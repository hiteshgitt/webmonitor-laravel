<?php

namespace Ruyalab\WebMonitor\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ruyalab\WebMonitor\ErrorTracker;

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
        // Error tracking: fatal errors (shutdown) and every exception Laravel reports, grouped with file, line and
        // stack for Web Monitor. Laravel's own reporting and its "don't report" list are unchanged.
        ErrorTracker::install(null, base_path());
        $this->callAfterResolving(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (\Throwable $e) {
                    ErrorTracker::recordThrowable($e);
                });
            }
        });

        // No session, cookies, CSRF or rate-limit middleware: nothing touches the app's cache or database.
        // Requests are authenticated with the 64-character API key.
        Route::get('api/webmonitor/{endpoint}', [WebMonitorController::class, 'handle'])
            ->where('endpoint', '[a-z]+')
            ->name('webmonitor');

        // Heartbeats for the scheduler and queue checks (see LaravelHealth): every minute the scheduler stamps
        // the cache and queues a tiny job; the job stamps it again when a worker runs it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(function () {
                Cache::forever(LaravelHealth::SCHEDULER_KEY, time());
                if (!in_array(config('queue.default'), ['sync', null], true)) {
                    dispatch(new HeartbeatJob());
                }
            })->everyMinute()->name('webmonitor:heartbeat')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
            $this->publishes([__DIR__ . '/config/webmonitor.php' => config_path('webmonitor.php')], 'webmonitor-config');
        }
    }
}
