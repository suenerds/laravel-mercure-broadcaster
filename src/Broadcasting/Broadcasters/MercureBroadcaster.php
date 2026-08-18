<?php declare(strict_types = 1);

namespace Suenerds\LaravelMercureBroadcaster\Broadcasting\Broadcasters;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\PrivateChannel;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class MercureBroadcaster extends Broadcaster
{
    public function __construct(
        protected HubInterface $hub,
        protected Authorization $authorization
    ){
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request $request
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
            // Throws AccessDeniedHttpException when no callback matches
            // or the callback returns false.
            $this->verifyUserCanAccessChannel($request, $channel);
        }

        return $this->validAuthenticationResponse($request, $channels->all());

    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  mixed $result
     * @return mixed
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
     * @param  array $channels
     * @param  string $event
     * @param  array $payload
     * @return void
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        foreach ($channels as $channel) {
            $this->hub->publish(new Update(
                topics: $channel->toArray(),
                data: implode(PHP_EOL, $payload),
                private: $channel instanceof PrivateChannel,
                type: $event));
        }
    }



    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $channel
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    protected function verifyUserCanAccessChannel($request, $channel) : void
    {
        foreach ($this->channels as $pattern => $callback) {
            if (! $this->channelNameMatchesPattern($channel, $pattern)) {
                continue;
            }

            $parameters = $this->extractAuthParameters($pattern, $channel, $callback);

            $handler = $this->normalizeChannelHandlerToCallable($callback);

            $result = $handler($this->retrieveUser($request, $channel), ...$parameters);

            if ($result === false) {
                throw new AccessDeniedHttpException;
            }
        }
    }

}
