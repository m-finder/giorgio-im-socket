<?php

namespace GiorgioImSocket\Providers;

use GiorgioImSocket\Contracts\SocketIdentity;
use GiorgioImSocket\Services\SocketAuthenticator;
use GiorgioImSocket\Services\SocketEvents;
use GiorgioImSocket\Services\SocketPresence;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ImServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/im.php', 'im');
        $this->app->bind(SocketIdentity::class, fn ($app) => $app->make(config('im.socket_identity')));
    }

    public function boot(): void
    {
        if (config('im.configure_socket', true)) {
            config(['socket.authenticate' => SocketAuthenticator::class, 'socket.presence' => SocketPresence::class,
                'socket.allow_client_messages' => false, 'socket.events.table' => 'im_events', 'socket.events.reader' => SocketEvents::class]);
        }
        RateLimiter::for('im-api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('im-files', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        if (config('im.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        }
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/im.php' => config_path('im.php')], 'im-config');
            $this->publishes([__DIR__.'/../../database/migrations' => database_path('migrations')], 'im-migrations');
        }
    }
}
