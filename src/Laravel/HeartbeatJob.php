<?php

namespace Ruyalab\WebMonitor\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Queued every minute by the scheduler heartbeat; running it proves a queue worker is alive. */
class HeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::forever(LaravelHealth::QUEUE_KEY, time());
    }
}
