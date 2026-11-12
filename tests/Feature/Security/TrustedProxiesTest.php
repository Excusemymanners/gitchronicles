<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Nothing is trusted by default; a proxy is trusted only when TRUSTED_PROXIES names it
 * (security audit WEB-LAYER-05, AI-LAYER-10, WEB-LAYER-09).
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__who', fn () => response()->json(['ip' => request()->ip(), 'secure' => request()->isSecure()]));
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        TrustHosts::flushState();

        parent::tearDown();
    }

    public function test_by_default_the_forwarded_address_of_a_visitor_is_not_believed(): void
    {
        $this->assertSame([], config('gitchronicle.http.trusted_proxies'));

        $this->call('GET', '/__who', server: ['REMOTE_ADDR' => '10.0.0.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https'])
            ->assertJson(['ip' => '10.0.0.9', 'secure' => false]);
    }

    public function test_a_proxy_named_in_the_configuration_may_forward_the_address(): void
    {
        config(['gitchronicle.http.trusted_proxies' => ['10.0.0.9']]);
        $this->app->register(AppServiceProvider::class, force: true);

        $this->call('GET', '/__who', server: ['REMOTE_ADDR' => '10.0.0.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https'])
            ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);

        $this->call('GET', '/__who', server: ['REMOTE_ADDR' => '198.51.100.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])
            ->assertJson(['ip' => '198.51.100.5']);
    }

    public function test_the_trusted_hosts_come_from_the_configuration_and_only_that_host_is_trusted(): void
    {
        config(['gitchronicle.http.trusted_hosts' => ['gitchronicle.example']]);

        $hosts = (new TrustHosts($this->app))->hosts();

        $this->assertSame(['gitchronicle.example'], $hosts);
    }

    public function test_the_policy_never_allows_everything(): void
    {
        $this->assertStringNotContainsString('*', (new SecurityHeaders)->policy('abc'));
    }
}
