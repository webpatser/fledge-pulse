<?php

namespace Laravel\Pulse\Fiber\Commands;

use Carbon\CarbonImmutable;
use Laravel\Pulse\Commands\WorkCommand as BaseWorkCommand;
use Laravel\Pulse\Fiber\Support\FiberSleep;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Support\CacheStoreResolver;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `pulse:work` that waits with the Fledge event loop instead of a blocking
 * sleep, so other fibers in the process keep running between iterations. The
 * wait goes through FiberSleep, so Sleep::fake() and its assertions still apply.
 *
 * Digest and trim stay sequential, exactly as upstream.
 *
 * @internal
 */
#[AsCommand(name: 'pulse:work')]
class WorkCommand extends BaseWorkCommand
{
    /**
     * Handle the command.
     */
    public function handle(
        Pulse $pulse,
        CacheStoreResolver $cache,
    ): int {
        $lastRestart = $cache->store()->get('laravel:pulse:restart');

        $lastTrimmedStorageAt = CarbonImmutable::now()->startOfMinute();

        while (true) {
            $now = CarbonImmutable::now();

            if ($lastRestart !== $cache->store()->get('laravel:pulse:restart')) {
                return self::SUCCESS;
            }

            $pulse->digest();

            if ($now->subMinutes(10)->greaterThan($lastTrimmedStorageAt)) {
                $pulse->trim();

                $lastTrimmedStorageAt = $now;
            }

            $this->ensureTelescopeEntriesAreCollected();

            if ($this->option('stop-when-empty')) {
                return self::SUCCESS;
            }

            FiberSleep::for(1)->second();
        }
    }
}
