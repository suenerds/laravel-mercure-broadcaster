<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Broadcasting\Broadcasters;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\PrivateChannel as IlluminatePrivateChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Redirect;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\Channel;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\PrivateChannel;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class MercureBroadcaster extends Broadcaster
{
    public function __construct(
        protected readonly HubInterface $hub,
        protected readonly Authorization $authorization
    ) {}

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function auth($request)
    {
        $raw = $request->input('channels', []);

        // GET variant: ?channels=tenant.4.invoices,user.17
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw));
        }

        $channels = collect($raw)
//            ->map(fn ($name) => $this->normalizeChannelName((string) $name))
            ->unique()
            ->values();

        if ($channels->isEmpty()) {
            throw new AccessDeniedHttpException('No channels requested.');
        }

        foreach ($channels as $channel) {
            // Runs the matching callback from routes/channels.php.
            // Throws AccessDeniedHttpException unless a matching callback
            // authorizes the channel.
            $this->verifyUserCanAccessChannel($request, $channel);
        }

        return $this->validAuthenticationResponse($request, $channels->all());

    }

    /**
     * Return the valid authentication response.
     *
     * @param  Request  $request
     * @param  mixed  $result
     */
    public function validAuthenticationResponse($request, $result): mixed
    {
        $url = $this->hub->getPublicUrl().'?'.implode('&', array_map(
            fn (string $topic) => 'topic='.rawurlencode($topic),
            $result,
        ));

        // Mercure does its own implementation of authorization with jwt's
        // You can add targets to Channel class to specify your audience
        return Redirect::to($url)
            ->withCookie(
                cookie: $this->authorization->createCookie(
                    request: $request,
                    grants: $result,
                ),
            );
    }

    /**
     * Broadcast the given event.
     *
     * @param  string  $event
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $data = $this->formatData($payload);

        foreach ($channels as $channel) {
            $this->hub->publish(new Update(
                topics: $this->channelTopics($channel),
                data: $data,
                private: $channel instanceof PrivateChannel || $channel instanceof IlluminatePrivateChannel,
                type: $event));
        }
    }

    /**
     * Format the broadcast payload as the update's SSE data block.
     *
     * A list of pre-formatted data lines (e.g. Datastar events, whose
     * broadcastWith() returns ['selector #foo', 'elements <div>…', …]) is
     * joined verbatim so each entry becomes its own "data:" line; an
     * associative payload is JSON-encoded.
     */
    protected function formatData(array $payload): string
    {
        Arr::pull($payload, 'socket');

        if ($payload !== [] && Arr::isList($payload) && array_filter($payload, 'is_string') === $payload) {
            return implode("\n", $payload);
        }

        return json_encode($payload);
    }

    /**
     * Resolve the Mercure topics for a channel, which may be one of this
     * package's channel classes, a native Laravel channel, or a string.
     *
     * @param  Channel|PrivateChannel|\Illuminate\Broadcasting\Channel|string  $channel
     */
    protected function channelTopics($channel): array
    {
        if ($channel instanceof Channel || $channel instanceof PrivateChannel) {
            return $channel->toArray();
        }

        $name = (string) $channel;

        // Native private channels prefix the name with "private-", which
        // would corrupt the topic URI; privacy is conveyed by the update's
        // private flag instead.
        if ($channel instanceof IlluminatePrivateChannel && str_starts_with($name, 'private-')) {
            $name = substr($name, strlen('private-'));
        }

        return [$name];
    }

    /**
     * Determine if the channel name matches the pattern.
     *
     * Overrides the base implementation because Mercure topics are URIs:
     * the base regex uses "/" as its delimiter and leaves literal parts
     * unquoted, so a channel name containing a slash can never match.
     *
     * @param  string  $channel
     * @param  string  $pattern
     */
    protected function channelNameMatchesPattern($channel, $pattern): bool
    {
        return (bool) preg_match('#^'.$this->compileChannelPattern($pattern).'$#', $channel);
    }

    /**
     * Extract the channel keys from the incoming channel name.
     *
     * @param  string  $pattern
     * @param  string  $channel
     */
    protected function extractChannelKeys($pattern, $channel): array
    {
        preg_match('#^'.$this->compileChannelPattern($pattern).'#', $channel, $keys);

        return $keys;
    }

    /**
     * Compile a channel pattern into a regex body: literal parts are quoted,
     * {placeholder} segments become named capture groups that stop at "."
     * and "/" so a parameter cannot span segment boundaries.
     */
    protected function compileChannelPattern(string $pattern): string
    {
        return preg_replace_callback(
            '/\\\\\{(.*?)\\\\\}/',
            fn ($matches) => '(?<'.$matches[1].'>[^/.]+)',
            preg_quote($pattern, '#')
        );
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  Request  $request
     * @param  string  $channel
     *
     * @throws AccessDeniedHttpException
     */
    protected function verifyUserCanAccessChannel($request, $channel): void
    {
        foreach ($this->channels as $pattern => $callback) {
            if (! $this->channelNameMatchesPattern($channel, $pattern)) {
                continue;
            }

            $parameters = $this->extractAuthParameters($pattern, $channel, $callback);

            $handler = $this->normalizeChannelHandlerToCallable($callback);

            if ($handler($this->retrieveUser($request, $channel), ...$parameters)) {
                return;
            }
        }

        throw new AccessDeniedHttpException;
    }
}
