<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Tests\Feature;

use Suenerds\LaravelMercureBroadcaster\Tests\Support\ExampleChannelEvent;
use Suenerds\LaravelMercureBroadcaster\Tests\Support\ExampleDatastarEvent;
use Suenerds\LaravelMercureBroadcaster\Tests\Support\ExampleEvent;
use Suenerds\LaravelMercureBroadcaster\Tests\Support\ExampleMultiTopicEvent;
use Suenerds\LaravelMercureBroadcaster\Tests\Support\ExamplePackagePrivateChannelEvent;
use Suenerds\LaravelMercureBroadcaster\Tests\Support\ExamplePrivateChannelEvent;
use Suenerds\LaravelMercureBroadcaster\Tests\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

class BroadcastTest extends TestCase
{
    /** @var Update[] */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = [];

        $this->app->instance(HubInterface::class, new MockHub(
            'http://localhost:3000/.well-known/mercure',
            new StaticTokenProvider('test.jwt'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'urn:uuid:'.count($this->published);
            },
        ));
    }

    public function test_it_publishes_an_event_on_a_package_channel_as_json()
    {
        event(new ExampleEvent('example data'));

        $this->assertCount(1, $this->published);
        $update = $this->published[0];
        $this->assertSame(['http://example/event'], $update->getTopics());
        $this->assertFalse($update->isPrivate());
        $this->assertSame(json_encode(['property' => 'example data']), $update->getData());
        $this->assertSame(ExampleEvent::class, $update->getType());
    }

    public function test_a_package_channel_can_publish_to_multiple_topics_in_a_single_update()
    {
        event(new ExampleMultiTopicEvent('example data'));

        $this->assertCount(1, $this->published);
        $this->assertSame(
            ['http://example/topic-a', 'http://example/topic-b'],
            $this->published[0]->getTopics()
        );
    }

    public function test_it_publishes_an_event_on_a_native_laravel_channel()
    {
        event(new ExampleChannelEvent('example data laravel channel'));

        $this->assertCount(1, $this->published);
        $update = $this->published[0];
        $this->assertSame(['http://example/channel-event'], $update->getTopics());
        $this->assertFalse($update->isPrivate());
        $this->assertSame(json_encode(['property' => 'example data laravel channel']), $update->getData());
    }

    public function test_a_native_private_channel_publishes_a_private_update_without_the_prefix()
    {
        event(new ExamplePrivateChannelEvent('secret data'));

        $this->assertCount(1, $this->published);
        $update = $this->published[0];
        $this->assertSame(['http://example/private-channel-event'], $update->getTopics());
        $this->assertTrue($update->isPrivate());
    }

    public function test_a_package_private_channel_publishes_a_private_update()
    {
        event(new ExamplePackagePrivateChannelEvent('secret data'));

        $this->assertCount(1, $this->published);
        $update = $this->published[0];
        $this->assertSame(['http://example/package-private'], $update->getTopics());
        $this->assertTrue($update->isPrivate());
    }

    public function test_a_datastar_line_list_payload_is_published_verbatim()
    {
        event(new ExampleDatastarEvent);

        $this->assertCount(1, $this->published);
        $update = $this->published[0];
        $this->assertSame(['http://example/datastar'], $update->getTopics());
        $this->assertSame(
            "selector #feed\nmode inner\nelements <div>a</div>\nelements <div>b</div>",
            $update->getData()
        );
        $this->assertSame('datastar-patch-elements', $update->getType());
    }

    public function test_nested_payloads_survive_json_encoding()
    {
        event(new ExampleEvent(['nested' => ['ok' => true]]));

        $this->assertSame(
            json_encode(['property' => ['nested' => ['ok' => true]]]),
            $this->published[0]->getData()
        );
    }
}
