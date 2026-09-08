<?php

declare(strict_types=1);

namespace Suenerds\LaravelMercureBroadcaster\Tests\Feature;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Suenerds\LaravelMercureBroadcaster\Broadcasting\Broadcasters\MercureBroadcaster;
use Suenerds\LaravelMercureBroadcaster\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

class AuthTest extends TestCase
{
    private MercureBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HubInterface::class, new MockHub(
            'http://localhost:3000/.well-known/mercure',
            new StaticTokenProvider('test.jwt'),
            fn (Update $update): string => 'urn:uuid:1',
            new LcobucciFactory('bfaf06ec-ac9d-11ed-a49f-6bc3bc0854c9'),
            'https://localhost/.well-known/mercure',
        ));

        $this->broadcaster = $this->app->make(BroadcastManager::class)->connection('mercure');
    }

    private function authRequest(array|string $channels): Request
    {
        return Request::create('/broadcasting/auth', 'POST', ['channels' => $channels]);
    }

    public function test_an_authorized_channel_redirects_to_the_hub_with_an_authorization_cookie()
    {
        $this->broadcaster->channel('http://example/news', fn ($user) => true);

        $response = $this->broadcaster->auth($this->authRequest(['http://example/news']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            'https://localhost/.well-known/mercure?topic='.rawurlencode('http://example/news'),
            $response->getTargetUrl()
        );

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'mercureAuthorization');

        $this->assertNotNull($cookie);
        $claims = $this->decodeJwtClaims((string) $cookie->getValue());
        $this->assertSame(['http://example/news'], $claims['mercure']['subscribe']);
    }

    public function test_multiple_channels_become_multiple_topics_and_grants()
    {
        $this->broadcaster->channel('http://example/news', fn ($user) => true);
        $this->broadcaster->channel('http://example/weather', fn ($user) => true);

        $response = $this->broadcaster->auth(
            $this->authRequest(['http://example/news', 'http://example/weather'])
        );

        $this->assertSame(
            'https://localhost/.well-known/mercure'
                .'?topic='.rawurlencode('http://example/news')
                .'&topic='.rawurlencode('http://example/weather'),
            $response->getTargetUrl()
        );

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'mercureAuthorization');
        $claims = $this->decodeJwtClaims((string) $cookie->getValue());
        $this->assertSame(
            ['http://example/news', 'http://example/weather'],
            $claims['mercure']['subscribe']
        );
    }

    public function test_channels_may_be_passed_as_a_comma_separated_string()
    {
        $this->broadcaster->channel('tenant.{tenant}.invoices', fn ($user, $tenant) => true);
        $this->broadcaster->channel('user.{id}', fn ($user, $id) => true);

        $response = $this->broadcaster->auth(
            Request::create('/broadcasting/auth', 'GET', ['channels' => 'tenant.4.invoices,user.17'])
        );

        $this->assertSame(
            'https://localhost/.well-known/mercure?topic=tenant.4.invoices&topic=user.17',
            $response->getTargetUrl()
        );
    }

    public function test_duplicate_channels_are_deduplicated()
    {
        $this->broadcaster->channel('http://example/news', fn ($user) => true);

        $response = $this->broadcaster->auth(
            $this->authRequest(['http://example/news', 'http://example/news'])
        );

        $this->assertSame(
            'https://localhost/.well-known/mercure?topic='.rawurlencode('http://example/news'),
            $response->getTargetUrl()
        );
    }

    public function test_an_unregistered_channel_is_denied()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest(['http://example/unregistered']));
    }

    public function test_a_callback_returning_false_is_denied()
    {
        $this->broadcaster->channel('http://example/denied', fn ($user) => false);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest(['http://example/denied']));
    }

    public function test_a_callback_returning_a_falsy_value_is_denied()
    {
        $this->broadcaster->channel('http://example/falsy', fn ($user) => null);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest(['http://example/falsy']));
    }

    public function test_an_empty_channel_list_is_denied()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest([]));
    }

    public function test_one_unauthorized_channel_denies_the_whole_request()
    {
        $this->broadcaster->channel('http://example/news', fn ($user) => true);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth(
            $this->authRequest(['http://example/news', 'http://example/unregistered'])
        );
    }

    public function test_uri_patterns_match_and_extract_parameters()
    {
        $captured = null;
        $this->broadcaster->channel(
            'http://example/user/{id}/dm',
            function ($user, $id) use (&$captured) {
                $captured = $id;

                return $id === '42';
            }
        );

        $response = $this->broadcaster->auth($this->authRequest(['http://example/user/42/dm']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('42', $captured);
    }

    public function test_a_uri_pattern_with_a_wrong_parameter_is_denied()
    {
        $this->broadcaster->channel('http://example/user/{id}/dm', fn ($user, $id) => $id === '42');

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest(['http://example/user/7/dm']));
    }

    public function test_a_parameter_cannot_span_multiple_segments()
    {
        $this->broadcaster->channel('http://example/user/{id}/dm', fn ($user, $id) => true);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest(['http://example/user/42/deep/dm']));
    }

    public function test_a_channel_with_trailing_segments_does_not_match()
    {
        $this->broadcaster->channel('http://example/user/{id}/dm', fn ($user, $id) => true);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest(['http://example/user/42/dm/extra']));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtClaims(string $jwt): array
    {
        [, $claims] = explode('.', $jwt);

        return json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
    }
}
