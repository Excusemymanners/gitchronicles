<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Inertia\DevTools\DevTools;
use Tests\TestCase;

/**
 * The development tools (Inertia DevTools, Laravel Boost) are off outside the local environment, even when
 * the .env turns them on or debugging is on (security audit WEB-LAYER-01, WEB-LAYER-02).
 */
class DevelopmentToolsTest extends TestCase
{
    public function test_they_are_turned_off_in_production_even_if_the_configuration_asks_for_them(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['boost.enabled' => true, 'inertia.devtools.enabled' => true, 'app.debug' => true]);

        (new AppServiceProvider($this->app))->register();

        $this->assertFalse(config('boost.enabled'));
        $this->assertFalse(config('inertia.devtools.enabled'));
        $this->assertFalse(DevTools::enabled());
    }

    public function test_they_are_not_touched_in_the_local_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['boost.enabled' => true, 'inertia.devtools.enabled' => true]);

        (new AppServiceProvider($this->app))->register();

        $this->assertTrue(config('boost.enabled'));
        $this->assertTrue(DevTools::enabled());
    }

    public function test_the_boost_and_devtools_routes_do_not_exist_in_the_test_environment(): void
    {
        $this->get('/_inertia/devtools/entries')->assertNotFound();
        $this->post('/_boost/browser-logs', ['logs' => []])->assertNotFound();
    }
}
