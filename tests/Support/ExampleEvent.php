<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Tests\Support;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\Channel;

class ExampleEvent implements ShouldBroadcastNow
{
    public $property;

    public function __construct($property)
    {
        $this->property = $property;
    }

    public function broadcastOn()
    {
        return new Channel(
            'http://example/event',
        );
    }
}
