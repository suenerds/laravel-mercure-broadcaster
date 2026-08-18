<?php declare(strict_types = 1);

namespace Suenerds\LaravelMercureBroadcaster;

use Illuminate\Foundation\Application;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\Broadcasters\MercureBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

class LaravelMercureBroadcasterServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app
            ->make(BroadcastManager::class)
            ->extend('mercure', function ($app, array $config) {
                $hub = $app->make(HubInterface::class);
                return new MercureBroadcaster(
                    hub: $hub,
                    authorization: new Authorization(
                        registry: new HubRegistry( defaultHub: $hub )
                    ),
                );
            });
    }

    public function register()
    {
        $this->app->singleton('suenerds.mercure_broadcaster.publisher_jwt', function () {
            $jwtConfiguration = Configuration::forSymmetricSigner(
                new Sha256(),
                InMemory::plainText(config('broadcasting.connections.mercure.secret'))
            );

            return $jwtConfiguration->builder()
                ->withClaim('mercure', ['publish' => ['*']])
                ->getToken($jwtConfiguration->signer(), $jwtConfiguration->signingKey())
                ->toString();
        });
        // 1. The overridable piece. singletonIf => consumer binding wins regardless of order.
        $this->app->singletonIf(TokenFactoryInterface::class, function (Application $app) {
            return new LcobucciFactory(config('broadcasting.connections.mercure.secret'));
        });

        // 2. Depends on #1 — resolve through the container so overrides propagate.
        $this->app->singletonIf(TokenProviderInterface::class, function (Application $app) {
            return new StaticTokenProvider($app->make('suenerds.mercure_broadcaster.publisher_jwt'));
        });

        // 3. Hub composes the interfaces, again via make() not new.
        $this->app->singletonIf(HubInterface::class, function (Application $app) {
            return new Hub(
                config('broadcasting.connections.mercure.url'),
                $app->make(TokenProviderInterface::class),
                $app->make(TokenFactoryInterface::class),
                config('broadcasting.connections.mercure.public_url')
            );
        });

        // Concrete alias so `Hub` type-hints also work
        $this->app->alias(HubInterface::class, Hub::class);
        $this->app->alias(HubInterface::class, 'mercure.hub');
    }
}
