<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Tests\Feature;

use Suenerds\LaravelMercureBroadcaster\Tests\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

class OverridesBindingsTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // A consuming application binding its own implementations must win
        // over the package defaults (which are registered with singletonIf).
        $app->singleton(TokenProviderInterface::class, fn () => new StaticTokenProvider('consumer-jwt'));
        $app->singleton(TokenFactoryInterface::class, fn () => new LcobucciFactory('consumer-secret'));
    }

    public function test_a_consumer_token_provider_wins_over_the_package_default()
    {
        $provider = $this->app->make(TokenProviderInterface::class);

        $this->assertInstanceOf(StaticTokenProvider::class, $provider);
        $this->assertSame('consumer-jwt', $provider->getJwt());
    }

    public function test_the_hub_composes_the_consumer_bindings()
    {
        $hub = $this->app->make(HubInterface::class);

        $this->assertSame('consumer-jwt', $hub->getProvider()->getJwt());
        $this->assertSame($this->app->make(TokenFactoryInterface::class), $hub->getFactory());
    }
}
