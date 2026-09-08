<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Tests\Support;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ExampleDatastarEvent implements ShouldBroadcastNow
{
    public function broadcastOn()
    {
        return new Channel('http://example/datastar');
    }

    /**
     * A Datastar-style payload: a list of pre-formatted SSE data lines.
     *
     * @return array<int, string>
     */
    public function broadcastWith(): array
    {
        return [
            'selector #feed',
            'mode inner',
            'elements <div>a</div>',
            'elements <div>b</div>',
        ];
    }

    public function broadcastAs(): string
    {
        return 'datastar-patch-elements';
    }
}
