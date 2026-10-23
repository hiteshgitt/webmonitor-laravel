<?php

namespace Ruyalab\WebMonitor\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered by Laravel: registers GET /api/webmonitor/{endpoint} and the webmonitor:install command.
 */
class WebMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/webmonitor.php', 'webmonitor');
    }

    public function boot(): void
    {
        // No session, cookies, CSRF or rate-limit middleware: nothing touches the app's cache or database.
        // Requests are authenticated with the 64-character API key.
        Route::get('api/webmonitor/{endpoint}', [WebMonitorController::class, 'handle'])
            ->where('endpoint', '[a-z]+')
            ->name('webmonitor');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
            $this->publishes([__DIR__ . '/config/webmonitor.php' => config_path('webmonitor.php')], 'webmonitor-config');
        }
    }
}
