<?php

namespace GiorgioImSocket\Tests;

class CustomRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('im.routes.prefix', 'chat/v1');
    }

    public function test_api_prefix_is_configurable(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        $this->actingAs($alice)->getJson('/chat/v1/init')->assertOk()->assertJsonPath('init.user.username', 'Alice');
        $this->getJson('/api/im/init')->assertNotFound();
    }
}
