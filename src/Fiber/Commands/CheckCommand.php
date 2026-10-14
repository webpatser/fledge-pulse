<?php

namespace Laravel\Pulse\Fiber\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Laravel\Pulse\Commands\CheckCommand as BaseCheckCommand;
use Laravel\Pulse\Events\IsolatedBeat;
use Laravel\Pulse\Events\SharedBeat;
use Laravel\Pulse\Fiber\Support\FiberSleep;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Support\CacheStoreResolver;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `pulse:check` that waits with the Fledge event loop instead of a blocking
 * sleep, so other fibers in the process keep running between beats. The wait
 * goes through FiberSleep, so Sleep::fake() and its assertions still apply.
 *
 * @internal
 */
#[AsCommand(name: 'pulse:check')]
class CheckCommand extends BaseCheckCommand
{
    /**
     * Handle the command.
     */
    public function handle(
        Pulse $pulse,
        CacheStoreResolver $cache,
        Dispatcher $event,
    ): int {
        $isVapor = (bool) Env::get('VAPOR_SSM_PATH');

        $instance = $isVapor ? 'vapor' : Str::random();

        $lastRestart = $cache->store()->get('laravel:pulse:restart');

        $lock = ($store = $cache->store()->getStore()) instanceof LockProvider
            ? $store->lock('laravel:pulse:check', 1)
            : null;

        while (true) {
            if ($lastRestart !== $cache->store()->get('laravel:pulse:restart')) {
                return self::SUCCESS;
            }

            $now = CarbonImmutable::now();

            if ($lock?->get()) {
                $event->dispatch(new IsolatedBeat($now));
            }

            $event->dispatch(new SharedBeat($now, $instance));

            $pulse->ingest();

            $this->ensureTelescopeEntriesAreCollected();

            if ($isVapor || $this->option('once')) {
                return self::SUCCESS;
            }

            FiberSleep::until($now->addSecond());
        }
    }
}
