<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\Channel;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\PrivateChannel;

class ChannelTest extends TestCase
{
    public function test_a_single_topic_is_wrapped_in_an_array()
    {
        $this->assertSame(['http://example/news'], (new Channel('http://example/news'))->toArray());
        $this->assertSame(['http://example/news'], (new PrivateChannel('http://example/news'))->toArray());
    }

    public function test_multiple_topics_are_kept_as_is()
    {
        $topics = ['http://example/a', 'http://example/b'];

        $this->assertSame($topics, (new Channel($topics))->toArray());
        $this->assertSame($topics, (new PrivateChannel($topics))->toArray());
    }
}
