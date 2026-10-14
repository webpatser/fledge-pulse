<?php

namespace Laravel\Pulse\Fiber\Support;

use Illuminate\Support\Sleep;

use function Fledge\Async\delay;

/**
 * A Sleep that waits on the Fledge event loop instead of blocking the process.
 *
 * Everything else is the parent's: the fluent duration API, `Sleep::fake()`,
 * `whenFakingSleep()`, Carbon syncing and the sequence assertions. The fake
 * state is static on `Sleep`, so faking `Sleep` fakes this class too.
 *
 * @internal
 */
class FiberSleep extends Sleep
{
    /**
     * Sleep for the configured duration via `Fledge\Async\delay()`.
     *
     * Fakes, skipped sleeps and invalid durations are left to the parent so
     * they record, skip or throw exactly as `Sleep` does.
     *
     * @return void
     */
    protected function goodnight()
    {
        if (static::$fake || $this->alreadySlept || ! $this->shouldSleep || $this->pending !== null) {
            parent::goodnight();

            return;
        }

        $while = $this->while ?: function () {
            static $return = [true, false];

            return array_shift($return);
        };

        $seconds = max(0.0, (float) $this->duration->totalMicroseconds / 1_000_000);

        while ($while()) {
            delay($seconds);
        }
    }
}
