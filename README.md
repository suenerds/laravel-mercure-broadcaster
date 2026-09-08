# Laravel Mercure Broadcaster

[![Latest Version on Packagist](https://img.shields.io/packagist/v/suenerds/laravel-mercure-broadcaster.svg?style=flat-square)](https://packagist.org/packages/suenerds/laravel-mercure-broadcaster)
![Build status](https://github.com/suenerds/laravel-mercure-broadcaster/workflows/Run%20tests/badge.svg)
[![Total Downloads](https://img.shields.io/packagist/dt/suenerds/laravel-mercure-broadcaster.svg?style=flat-square)](https://packagist.org/packages/suenerds/laravel-mercure-broadcaster)

Laravel broadcaster for [Mercure](https://github.com/dunglas/mercure) for doing Server Sent Events in a breeze.

- Works with Laravel's native `Channel` and `PrivateChannel` classes
- Publish one update to multiple topics at once with the package's channel classes
- JSON payloads for classic events, raw SSE data lines for [Datastar](https://data-star.dev)-style events (e.g. [suenerds/laravel-datastar](https://github.com/suenerds/laravel-datastar))
- Built-in channel authorization endpoint that redirects the browser's `EventSource` to the hub with a Mercure JWT cookie

## Installation

Requires PHP 8.3+ and Laravel 10 or newer.

Install the package via Composer:

```
composer require suenerds/laravel-mercure-broadcaster
```

Configure Laravel to use the Mercure broadcaster by editing `config/broadcasting.php`:

```php
<?php

return [

    'default' => env('BROADCAST_DRIVER', 'mercure'),

    'connections' => [

        // ...

        'mercure' => [
            'driver' => 'mercure',
            // URL your Laravel app uses to publish updates to the hub
            'url' => env('MERCURE_URL', 'http://localhost:3000/.well-known/mercure'),
            // URL browsers use to subscribe; defaults to `url` when omitted
            'public_url' => env('MERCURE_PUBLIC_URL'),
            'secret' => env('MERCURE_SECRET', 'aVerySecretKey'),
        ],

    ],

];
```

## Usage

### Broadcasting events

Add an event which implements the `ShouldBroadcast` interface like in the
[Laravel broadcasting docs](https://laravel.com/docs/master/broadcasting#defining-broadcast-events).
Mercure topics are URIs, so use a topic URI as the channel name:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class NewsItemCreated implements ShouldBroadcast
{
    public function __construct(public NewsItem $newsItem)
    {
    }

    public function broadcastOn()
    {
        return new Channel('http://example/news-items');
    }

    public function broadcastAs(): string
    {
        return 'news-item.created';
    }
}
```

Each update is published with an SSE event type: the `broadcastAs()` name, or the event's
class name if you don't define one. Listen for that type in your frontend — a plain
`message` listener will not fire for typed events:

```javascript
const url = 'http://localhost:3000/.well-known/mercure?topic=' + encodeURIComponent('http://example/news-items');
const es = new EventSource(url);
es.addEventListener('news-item.created', (messageEvent) => {
    const eventData = JSON.parse(messageEvent.data);
    console.log(eventData);
});
```

### Publishing to multiple topics

Laravel's channel classes carry a single topic. To publish one update to several topics,
use this package's channel classes instead:

```php
use Suenerds\LaravelMercureBroadcaster\Broadcasting\Channel;

public function broadcastOn()
{
    return new Channel([
        'http://example/news-items',
        "http://example/authors/{$this->newsItem->author_id}/news-items",
    ]);
}
```

`Suenerds\LaravelMercureBroadcaster\Broadcasting\PrivateChannel` does the same for private
updates.

### Payload formats

The broadcast payload (your event's public properties, or whatever `broadcastWith()`
returns) is serialized in one of two ways:

- An **associative array** is JSON-encoded — decode it with `JSON.parse` as shown above.
- A **list of strings** is joined verbatim, each entry becoming its own `data:` line of
  the SSE event. This is what [Datastar](https://data-star.dev) expects, so events using
  the traits from [suenerds/laravel-datastar](https://github.com/suenerds/laravel-datastar)
  broadcast correctly out of the box:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\View\View;
use Suenerds\LaravelDatastar\IsPatchElementsEvent;

class OrderShipped implements ShouldBroadcast
{
    use IsPatchElementsEvent;

    public function __construct(public Order $order)
    {
    }

    public function broadcastOn()
    {
        return new PrivateChannel("http://example/user/{$this->order->user_id}/orders");
    }

    public function elements(): View
    {
        return view('orders.card', ['order' => $this->order]);
    }
}
```

### Private channels

Private channels are baked into Mercure and secured with a JWT cookie. Events broadcast
on a `PrivateChannel` (Laravel's or this package's) are published as private updates, and
the hub only delivers them to subscribers whose JWT grants the topic.

First, authorize your channels in `routes/channels.php` — exactly like any other Laravel
broadcaster. A channel is denied unless a matching callback returns a truthy value.
Placeholders such as `{id}` match a single segment; they stop at `.` and `/`:

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('http://example/user/{id}/direct-messages', function ($user, $id) {
    return (int) $id === $user->id;
});
```

The endpoint accepts the requested channels as a `channels[]` array or a comma-separated
`channels` query string. It authorizes each channel, then responds with a redirect to the
hub's public URL (with a `topic` parameter per channel) and sets a `mercureAuthorization`
cookie granting exactly those topics. An `EventSource` follows that redirect, so your
frontend can subscribe straight through the endpoint:

```javascript
const url = '/broadcasting/auth?channels=' + encodeURIComponent('http://example/user/1/direct-messages');
const es = new EventSource(url, { withCredentials: true });
es.addEventListener('direct-message.created', (messageEvent) => {
    const eventData = JSON.parse(messageEvent.data);
    console.log(eventData);
});
```

Because Laravel encrypts cookies by default, add an
  [exception](https://laravel.com/docs/master/responses#cookies-and-encryption) for the
  `mercureAuthorization` cookie in your cookie encryption configuration.
```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['mercureAuthorization']);
    })
```

The cookie can only be set when the hub's public URL shares a second-level domain with
your app (e.g. `app.example.com` and `mercure.example.com`). With the hub on an
unrelated domain, the underlying Symfony component refuses to create the cookie.

### Advanced usage

The service provider registers its collaborators with `singletonIf`, so any binding you
define in your application wins over the package default:

- `Symfony\Component\Mercure\HubInterface` — the hub itself (also aliased to
  `Symfony\Component\Mercure\Hub` and `mercure.hub`)
- `Symfony\Component\Mercure\Jwt\TokenProviderInterface` — provides the JWT used to
  publish updates
- `Symfony\Component\Mercure\Jwt\TokenFactoryInterface` — creates the subscriber JWTs
  for authorization cookies

If you only want to customize the publisher JWT (custom claims, another signing
algorithm, …), override the `suenerds.mercure_broadcaster.publisher_jwt` service; it must
resolve to a JWT string. See
[`LaravelMercureBroadcasterServiceProvider`](src/LaravelMercureBroadcasterServiceProvider.php)
for how the default is generated.

```php
$this->app->singleton('suenerds.mercure_broadcaster.publisher_jwt', function () {
    return MyJwtBuilder::publisherToken();
});
```

### Further reading

Make sure you read the documentation of Mercure and how to run it securely (behind https).

* [Mercure documentation](https://github.com/dunglas/mercure)
* [Symfony integration document](https://symfony.com/doc/current/mercure.html)

### Testing

```bash
composer test                                # full suite; needs a running Docker daemon
vendor/bin/phpunit --exclude-group docker    # fast suite, no Docker required
composer lint                                # code style
```

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

This package started as a fork of
[mvanduijker/laravel-mercure-broadcaster](https://github.com/mvanduijker/laravel-mercure-broadcaster).

- [Thore Sünert](https://github.com/thoresuenert)
- [Mark van Duijker](https://github.com/mvanduijker)
- [Kévin Dunglas](https://github.com/dunglas)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
