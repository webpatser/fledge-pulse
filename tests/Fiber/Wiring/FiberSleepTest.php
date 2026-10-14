<?php

use Illuminate\Support\Sleep;
use Laravel\Pulse\Fiber\Support\FiberSleep;

use function Fledge\Async\async;
use function Fledge\Async\delay;

afterEach(function () {
    Sleep::fake(false);
});

it('records into the Sleep fake instead of waiting', function () {
    Sleep::fake();

    $started = microtime(true);

    FiberSleep::for(5)->seconds();
    FiberSleep::for(1)->second();

    expect(microtime(true) - $started)->toBeLessThan(1.0);

    Sleep::assertSequence([
        Sleep::for(5)->seconds(),
        Sleep::for(1)->second(),
    ]);
});

it('waits on the event loop so other fibers keep running', function () {
    $ticks = 0;

    $ticker = async(function () use (&$ticks) {
        foreach (range(1, 5) as $tick) {
            delay(0.01);

            $ticks++;
        }
    });

    $started = microtime(true);

    FiberSleep::for(150)->milliseconds();

    $elapsed = microtime(true) - $started;
    $ticksDuringSleep = $ticks;

    $ticker->await();

    expect($elapsed)->toBeGreaterThanOrEqual(0.14)
        ->and($ticksDuringSleep)->toBe(5);
});
