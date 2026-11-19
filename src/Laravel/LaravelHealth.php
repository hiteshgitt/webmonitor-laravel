<?php

namespace Ruyalab\WebMonitor\Laravel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ruyalab\WebMonitor\WebMonitorCore as Core;

/**
 * Laravel-specific health: database, cache, scheduler, queue worker, failed jobs, storage and debug mode.
 *
 * The scheduler and the queue are checked with heartbeats: the service provider schedules a task every minute
 * that stamps the cache ("scheduler ran") and dispatches a tiny job that stamps it again ("a worker ran it").
 * A stale scheduler stamp means `schedule:run` is not running; a fresh scheduler stamp with a stale queue
 * stamp means no queue worker is processing jobs.
 */
class LaravelHealth
{
    public const SCHEDULER_KEY = 'webmonitor:heartbeat:scheduler';
    public const QUEUE_KEY = 'webmonitor:heartbeat:queue';
    public const INSTALLED_KEY = 'webmonitor:installed_at';
    private const STALE_SCHEDULER = 300;   // 5 minutes
    private const STALE_QUEUE = 600;       // 10 minutes
    private const GRACE = 900;             // no verdict in the first 15 minutes after install

    public static function components(): array
    {
        $out = [];
        $out[] = Core::guard('database', 'Database', function () {
            $start = microtime(true);
            DB::connection()->getPdo();
            DB::select('select 1');
            $ms = round((microtime(true) - $start) * 1000);
            return Core::component('database', 'Database', $ms > 1000 ? 'warning' : 'ok',
                'Connected (' . DB::connection()->getDriverName() . ", {$ms} ms).",
                $ms > 1000 ? 'The database is slow to answer; check its load and slow queries.' : null);
        }, 'Check that the database server is running and the DB_* values in .env are right.');

        $cacheOk = true;
        $out[] = Core::guard('cache', 'Cache', function () use (&$cacheOk) {
            $token = (string) microtime(true);
            Cache::put('webmonitor:ping', $token, 60);
            $cacheOk = Cache::get('webmonitor:ping') === $token;
            return Core::component('cache', 'Cache', $cacheOk ? 'ok' : 'error',
                $cacheOk ? 'Read and write work (' . config('cache.default') . ').' : 'Wrote a value but could not read it back.',
                $cacheOk ? null : 'Check the cache store (Redis/Memcached) is running and reachable.');
        }, 'Check the cache store (Redis/Memcached) is running and reachable.');

        if ($cacheOk) {
            $installed = Cache::get(self::INSTALLED_KEY);
            if (!$installed) {
                Cache::forever(self::INSTALLED_KEY, time());
                $installed = time();
            }
            $young = time() - (int) $installed < self::GRACE;
            $out[] = self::scheduler($young);
            $out[] = self::queue($young);
        }

        $out[] = Core::guard('failed_jobs', 'Failed jobs', function () {
            $table = config('queue.failed.table', 'failed_jobs');
            if (!Schema::hasTable($table)) {
                return Core::component('failed_jobs', 'Failed jobs', 'unknown', 'No failed_jobs table.');
            }
            $n = DB::table($table)->where('failed_at', '>=', now()->subDay())->count();
            return Core::component('failed_jobs', 'Failed jobs', $n > 0 ? 'warning' : 'ok',
                $n > 0 ? "{$n} job" . ($n === 1 ? '' : 's') . ' failed in the last 24 hours.' : 'No failed jobs in the last 24 hours.',
                $n > 0 ? 'Run `php artisan queue:failed` to see the errors, fix them, then `php artisan queue:retry all`.' : null);
        });

        $writable = is_writable(storage_path()) && is_writable(storage_path('logs'));
        $out[] = Core::component('storage', 'Storage folder', $writable ? 'ok' : 'error',
            $writable ? 'storage/ is writable.' : 'storage/ or storage/logs is not writable.',
            $writable ? null : 'Give the web server user write access to storage/ and bootstrap/cache/.');

        if (app()->environment('production')) {
            $debug = (bool) config('app.debug');
            $out[] = Core::component('debug_mode', 'Debug mode', $debug ? 'warning' : 'ok',
                $debug ? 'APP_DEBUG is on in production: error pages show code and settings to visitors.' : 'APP_DEBUG is off.',
                $debug ? 'Set APP_DEBUG=false in .env and run `php artisan config:cache`.' : null);
        }
        return $out;
    }

    private static function scheduler(bool $young): array
    {
        $at = Cache::get(self::SCHEDULER_KEY);
        if (!$at) {
            return Core::component('scheduler', 'Task scheduler', $young ? 'unknown' : 'error',
                $young ? 'Waiting for the first scheduler run.' : 'The scheduler has never run since Web Monitor was installed.',
                'Add the cron entry: * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1');
        }
        $age = time() - (int) $at;
        return $age > self::STALE_SCHEDULER
            ? Core::component('scheduler', 'Task scheduler', 'error', 'Last ran ' . self::ago($age) . ' ago: scheduled tasks are not running.',
                'Check the cron entry for `php artisan schedule:run` (or the scheduler container) is still active.')
            : Core::component('scheduler', 'Task scheduler', 'ok', 'Ran ' . self::ago($age) . ' ago.');
    }

    private static function queue(bool $young): array
    {
        $driver = config('queue.default');
        if ($driver === 'sync' || $driver === null) {
            return Core::component('queue', 'Queue worker', 'ok', 'Jobs run synchronously (QUEUE_CONNECTION=sync); no worker needed.');
        }
        $scheduler = Cache::get(self::SCHEDULER_KEY);
        $at = Cache::get(self::QUEUE_KEY);
        // Without a running scheduler there is no heartbeat job to process, so no verdict on the worker.
        if (!$scheduler || time() - (int) $scheduler > self::STALE_SCHEDULER) {
            return Core::component('queue', 'Queue worker', 'unknown', 'Checked through the scheduler, which is not running.');
        }
        if (!$at) {
            return Core::component('queue', 'Queue worker', $young ? 'unknown' : 'error',
                $young ? 'Waiting for the first heartbeat job.' : "No worker has processed a job on the \"{$driver}\" queue.",
                'Start a worker: `php artisan queue:work` (keep it running with Supervisor or Horizon).');
        }
        $age = time() - (int) $at;
        return $age > self::STALE_QUEUE
            ? Core::component('queue', 'Queue worker', 'error', 'Queue worker stopped: the last job was processed ' . self::ago($age) . ' ago.',
                'Restart the worker (`php artisan queue:restart`, Supervisor or Horizon) and check its log for the crash.')
            : Core::component('queue', 'Queue worker', 'ok', 'Processing jobs on "' . $driver . '" (last ' . self::ago($age) . ' ago).');
    }

    private static function ago(int $seconds): string
    {
        return $seconds < 120 ? "{$seconds} s" : ($seconds < 7200 ? round($seconds / 60) . ' min' : ($seconds < 172800 ? round($seconds / 3600) . ' h' : round($seconds / 86400) . ' days'));
    }
}
