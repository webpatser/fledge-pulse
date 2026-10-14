<?php

use Fledge\Async\TimeoutCancellation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Fiber\FiberPulse;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * An in-memory ingest that can suspend mid-ingest and tracks concurrency.
 */
final class FiberPulseTestIngest implements Ingest
{
    /** @var list<list<string>> */
    public array $batches = [];

    public int $inFlight = 0;

    public int $maxInFlight = 0;

    public function __construct(private float $suspendFor = 0.0) {}

    public function ingest(Collection $items): void
    {
        $this->maxInFlight = max($this->maxInFlight, ++$this->inFlight);

        try {
            if ($this->suspendFor > 0) {
                delay($this->suspendFor);
            }

            $this->batches[] = $items->map(fn ($item) => $item->key)->values()->all();
        } finally {
            $this->inFlight--;
        }
    }

    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void
    {
        //
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_merge(...$this->batches ?: [[]]);
    }
}

function fiberPulse(FiberPulseTestIngest $ingest): FiberPulse
{
    app()->instance(Ingest::class, $ingest);

    return new FiberPulse(app());
}

it('ignores recordings only in the fiber that called ignore()', function () {
    $ingest = new FiberPulseTestIngest;
    $pulse = fiberPulse($ingest);

    $ignoring = async(fn () => $pulse->ignore(function () use ($pulse) {
        $pulse->record('test', 'ignored-before');

        delay(0.02);

        $pulse->record('test', 'ignored-after');

        return 'result';
    }));

    $recording = async(function () use ($pulse) {
        delay(0.005);

        $pulse->record('test', 'kept-fiber');
        $pulse->set('value', 'kept-value', 'v');
        $pulse->lazy(fn () => $pulse->record('test', 'kept-lazy'));
    });

    expect($ignoring->await())->toBe('result');
    $recording->await();

    $pulse->record('test', 'kept-main');

    expect($pulse->ingest())->toBe(4)
        ->and($ingest->keys())->toBe(['kept-fiber', 'kept-value', 'kept-main', 'kept-lazy']);
});

it('leaves recording enabled when overlapping ignore() calls end out of order', function () {
    $ingest = new FiberPulseTestIngest;
    $pulse = fiberPulse($ingest);

    // A enters first and leaves first; B enters while A is inside and leaves last.
    // Upstream's save/restore of a shared flag restores B's cached "false" here.
    $a = async(fn () => $pulse->ignore(fn () => delay(0.01)));
    $b = async(function () use ($pulse) {
        delay(0.002);

        $pulse->ignore(fn () => delay(0.02));
    });

    $a->await();
    $b->await();

    $pulse->record('test', 'main');
    $after = async(fn () => $pulse->record('test', 'fiber'));
    $after->await();

    expect($pulse->ingest())->toBe(2)
        ->and($ingest->keys())->toBe(['main', 'fiber']);
});

it('ignores recordings in the main context and honours nested ignore()', function () {
    $ingest = new FiberPulseTestIngest;
    $pulse = fiberPulse($ingest);

    $pulse->ignore(function () use ($pulse) {
        $pulse->ignore(fn () => $pulse->record('test', 'nested'));

        $pulse->record('test', 'outer');
    });

    $pulse->record('test', 'kept');

    expect($pulse->ingest())->toBe(1)
        ->and($ingest->keys())->toBe(['kept']);
});

it('does not carry the ignore depth into child fibers', function () {
    $ingest = new FiberPulseTestIngest;
    $pulse = fiberPulse($ingest);

    $pulse->ignore(function () use ($pulse) {
        $child = async(fn () => $pulse->record('test', 'child'));
        $child->await();
    });

    expect($pulse->ingest())->toBe(1)
        ->and($ingest->keys())->toBe(['child']);
});

it('keeps stopRecording() global across fibers', function () {
    $ingest = new FiberPulseTestIngest;
    $pulse = fiberPulse($ingest);

    $pulse->stopRecording();

    $fiber = async(fn () => $pulse->record('test', 'stopped'));
    $fiber->await();

    $pulse->startRecording();

    $fiber = async(fn () => $pulse->record('test', 'started'));
    $fiber->await();

    expect($pulse->ingest())->toBe(1)
        ->and($ingest->keys())->toBe(['started']);
});

it('keeps entries recorded while an ingest is suspended', function () {
    $ingest = new FiberPulseTestIngest(suspendFor: 0.02);
    $pulse = fiberPulse($ingest);

    $pulse->record('test', 'early');

    $ingesting = async(fn () => $pulse->ingest());
    $late = async(function () use ($pulse) {
        delay(0.005);

        $pulse->record('test', 'late');
    });

    expect($ingesting->await())->toBe(1);
    $late->await();

    expect($pulse->wantsIngesting())->toBeTrue()
        ->and($pulse->ingest())->toBe(1)
        ->and($ingest->batches)->toBe([['early'], ['late']])
        ->and($pulse->wantsIngesting())->toBeFalse();
});

it('keeps lazy resolvers queued while lazy resolution is suspended', function () {
    $ingest = new FiberPulseTestIngest;
    $pulse = fiberPulse($ingest);

    $pulse->lazy(function () use ($pulse) {
        delay(0.02);

        $pulse->record('test', 'slow-lazy');
    });

    $ingesting = async(fn () => $pulse->ingest());
    $other = async(function () use ($pulse) {
        delay(0.005);

        $pulse->lazy(fn () => $pulse->record('test', 'queued-lazy'));
    });

    expect($ingesting->await())->toBe(1);
    $other->await();

    expect($pulse->ingest())->toBe(1)
        ->and($ingest->batches)->toBe([['slow-lazy'], ['queued-lazy']]);
});

it('ingests every entry exactly once when fibers hit the buffer size concurrently', function () {
    Config::set('pulse.ingest.buffer', 3);

    $ingest = new FiberPulseTestIngest(suspendFor: 0.005);
    $pulse = fiberPulse($ingest);

    $expected = [];
    $fibers = [];

    foreach (range(1, 6) as $fiber) {
        foreach (range(1, 8) as $entry) {
            $expected[] = "{$fiber}-{$entry}";
        }

        $fibers[] = async(function () use ($pulse, $fiber) {
            foreach (range(1, 8) as $entry) {
                $pulse->record('test', "{$fiber}-{$entry}");

                delay(0);
            }
        });
    }

    foreach ($fibers as $future) {
        $future->await();
    }

    $pulse->ingest();

    $keys = $ingest->keys();

    expect(count($ingest->batches))->toBeGreaterThan(2)
        ->and($ingest->maxInFlight)->toBe(1)
        ->and(count($keys))->toBe(count($expected))
        ->and(array_unique($keys))->toHaveCount(count($expected))
        ->and(collect($keys)->sort()->values()->all())->toBe(collect($expected)->sort()->values()->all())
        ->and($pulse->wantsIngesting())->toBeFalse();
});

it('does not deadlock when a lazy resolver awaits child fibers that record past the buffer size', function () {
    Config::set('pulse.ingest.buffer', 2);

    $ingest = new FiberPulseTestIngest(suspendFor: 0.005);
    $pulse = fiberPulse($ingest);

    $trigger = async(function () use ($pulse) {
        $pulse->record('test', 'a');
        $pulse->record('test', 'b');

        // Queuing this pushes the buffer over its size, so the resolver runs
        // inside the buffer ingest; its children record while it is in flight.
        $pulse->lazy(function () use ($pulse) {
            $children = array_map(fn (int $child) => async(function () use ($pulse, $child) {
                $pulse->record('test', "child-{$child}-1");

                delay(0);

                $pulse->record('test', "child-{$child}-2");
            }), range(1, 3));

            foreach ($children as $future) {
                $future->await();
            }
        });
    });

    // A regression fails with a timeout (or an event loop error) instead of hanging.
    $trigger->await(new TimeoutCancellation(2.0));

    $pulse->ingest();

    $expected = ['a', 'b', 'child-1-1', 'child-1-2', 'child-2-1', 'child-2-2', 'child-3-1', 'child-3-2'];
    $keys = $ingest->keys();

    expect(count($keys))->toBe(count($expected))
        ->and(collect($keys)->sort()->values()->all())->toBe(collect($expected)->sort()->values()->all())
        ->and($ingest->maxInFlight)->toBe(1)
        ->and($pulse->wantsIngesting())->toBeFalse();
});
