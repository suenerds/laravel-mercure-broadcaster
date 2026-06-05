<?php declare(strict_types = 1);

namespace Suenerds\LaravelMercureBroadcaster\Broadcasting\Broadcasters;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\PrivateChannel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class MercureBroadcaster implements Broadcaster
{
    protected HubInterface $hub;

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request $request
     * @return mixed
     */
    public function auth($request)
    {
        // Mercure does its own implementation of authorization with jwt's
        // You can add targets to Channel class to specify your audience
    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  mixed $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        // Mercure does its own implementation of authorization with jwt's
        // You can add targets to Channel class to specify your audience
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
}
