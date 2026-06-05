<?php declare(strict_types = 1);

namespace Suenerds\LaravelMercureBroadcaster\Broadcasting;

use Illuminate\Support\Arr;

class PrivateChannel
{

    public function __construct(public array|string $topics)
    {
    }

    public function toArray()
    {
        return Arr::wrap($this->topics);
    }
}
