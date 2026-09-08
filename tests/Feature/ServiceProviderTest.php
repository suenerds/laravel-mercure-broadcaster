<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Tests\Feature;

use Illuminate\Broadcasting\BroadcastManager;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\Broadcasters\MercureBroadcaster;
use Suenerds\LaravelMercureBroadcaster\Tests\TestCase;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

class ServiceProviderTest extends TestCase
{
    public function test_the_mercure_driver_resolves_to_the_mercure_broadcaster()
    {
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('mercure');

        $this->assertInstanceOf(MercureBroadcaster::class, $broadcaster);
    }

    public function test_the_hub_is_a_singleton_configured_from_the_broadcasting_config()
    {
        $hub = $this->app->make(HubInterface::class);

        $this->assertInstanceOf(Hub::class, $hub);
        $this->assertSame('http://localhost:3000/.well-known/mercure', $hub->getUrl());
        $this->assertSame($hub, $this->app->make(HubInterface::class));
    }

    public function test_the_public_url_falls_back_to_the_hub_url_when_not_configured()
    {
        $hub = $this->app->make(HubInterface::class);

        $this->assertSame('http://localhost:3000/.well-known/mercure', $hub->getPublicUrl());
    }

    public function test_the_hub_is_aliased_to_the_concrete_class_and_a_string_accessor()
    {
        $hub = $this->app->make(HubInterface::class);

        $this->assertSame($hub, $this->app->make(Hub::class));
        $this->assertSame($hub, $this->app->make('mercure.hub'));
    }

    public function test_the_publisher_jwt_is_a_token_with_a_wildcard_publish_claim()
    {
        $jwt = $this->app->make('suenerds.mercure_broadcaster.publisher_jwt');

        $this->assertIsString($jwt);
        $this->assertCount(3, explode('.', $jwt));

        [, $claims] = explode('.', $jwt);
        $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        $this->assertSame(['publish' => ['*']], $claims['mercure']);
    }

    public function test_the_default_token_provider_serves_the_publisher_jwt()
    {
        $provider = $this->app->make(TokenProviderInterface::class);

        $this->assertInstanceOf(StaticTokenProvider::class, $provider);
        $this->assertSame(
            $this->app->make('suenerds.mercure_broadcaster.publisher_jwt'),
            $provider->getJwt()
        );
    }

    public function test_the_default_token_factory_is_the_lcobucci_factory()
    {
        $this->assertInstanceOf(
            LcobucciFactory::class,
            $this->app->make(TokenFactoryInterface::class)
        );
    }
}
