<?php

namespace GiorgioImSocket\Tests;

use Illuminate\Support\Facades\Route;

class DisabledRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('im.routes.enabled', false);
    }

    public function test_route_registration_can_be_disabled(): void
    {
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => str_starts_with($route->uri(), 'api/im')));
    }
}
