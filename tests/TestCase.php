<?php

namespace GiorgioImSocket\Tests;

use GiorgioImSocket\Providers\ImServiceProvider;
use GiorgioSocket\Providers\EventServiceProvider;
use GiorgioSocket\Providers\SocketServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SocketServiceProvider::class, EventServiceProvider::class, ImServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array',
            'im.require_tls' => false, 'im.user_model' => Account::class,
            'im.user_name_column' => 'display_name',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('accounts', function (Blueprint $table) {
            $table->id('account_id');
            $table->string('display_name');
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}

/** Deliberately has no Sanctum trait, default users table, name column or id property. */
class Account extends User
{
    protected $table = 'accounts';

    protected $primaryKey = 'account_id';

    protected $guarded = [];

    public $timestamps = false;
}
