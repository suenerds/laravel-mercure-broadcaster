<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Broadcasting;

use Illuminate\Support\Arr;

class PrivateChannel
{
    public function __construct(public readonly array|string $topics) {}

    public function toArray(): array
    {
        return Arr::wrap($this->topics);
    }
}
