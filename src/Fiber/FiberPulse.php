<?php

namespace Laravel\Pulse\Fiber;

use Fiber;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Lottery;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Value;
use WeakMap;

/**
 * Pulse with fiber-safe recording state and ingest buffers.
 *
 * - ignore() keeps an ignore depth per fiber (plus one for the main
 *   context) instead of saving and restoring the shared `$shouldRecord`
 *   flag, so overlapping fibers can never leave recording switched off.
 * - startRecording() / stopRecording() remain a global switch that every
 *   fiber honours.
 * - ingest() swaps the entries and lazy buffers into locals before any I/O,
 *   so anything recorded while it is suspended lands in a fresh buffer and
 *   is ingested next time instead of being discarded.
 * - ingestWhenOverBufferSize() lets only one fiber ingest at a time; the
 *   others skip it rather than wait, so record() never suspends.
 *
 * @internal
 */
class FiberPulse extends Pulse
{
    /**
     * Whether recording is active for the current fiber.
     *
     * The backing value is the global startRecording() / stopRecording()
     * switch. Reads also consult the current fiber's ignore depth, so every
     * upstream read (record(), set(), lazy()) honours ignore() per fiber.
     */
    protected bool $shouldRecord = true {
        get => $this->shouldRecord && $this->ignoreDepth() === 0;
    }

    /**
     * The ignore() depth of each fiber currently inside ignore().
     *
     * Keyed on the fiber, never on fiber lifetime: Revolt reuses callback
     * fibers, so an entry is removed as soon as its depth returns to 0.
     *
     * @var WeakMap<Fiber, int>
     */
    private WeakMap $fiberIgnoreDepths;

    /**
     * The ignore() depth of the main (non-fiber) context.
     */
    private int $mainIgnoreDepth = 0;

    /**
     * Whether a buffer-triggered ingest is running in any fiber.
     *
     * Shared between fibers on purpose: it is both the recursion guard
     * (upstream's `$evaluatingBuffer`) and the single-ingest claim.
     */
    private bool $bufferIngestInFlight = false;

    /**
     * Create a new Pulse instance.
     */
    public function __construct(Application $app)
    {
        $this->fiberIgnoreDepths = new WeakMap;

        parent::__construct($app);
    }

    /**
     * Execute the given callback without recording.
     *
     * Recording is suppressed only for the calling fiber (or the main
     * context) while the callback runs; other fibers keep recording, even
     * when this callback suspends. The depth is NOT inherited by child
     * fibers started with `Fledge\Async\async()` inside the callback: wrap
     * their closures in ignore() as well if they must not record.
     *
     * startRecording() / stopRecording() called inside the callback flip the
     * global switch and are not undone when the callback returns.
     *
     * @template TReturn
     *
     * @param  (callable(): TReturn)  $callback
     * @return TReturn
     */
    public function ignore($callback): mixed
    {
        $fiber = Fiber::getCurrent();

        $this->adjustIgnoreDepth($fiber, 1);

        try {
            return $callback();
        } finally {
            $this->adjustIgnoreDepth($fiber, -1);
        }
    }

    /**
     * Ingest the entries.
     *
     * Both buffers are taken before the first suspension point, so entries
     * and lazy resolvers recorded while this ingest waits on I/O stay in the
     * live buffers for the next ingest.
     */
    public function ingest(): int
    {
        $entries = $this->takeEntries();

        $this->resolveLazyEntries();

        // Lazy resolvers record into the live buffer; take what they (and any
        // fiber that recorded while they were suspended) added.
        $entries = $entries->concat($this->takeEntries());

        return $this->ignore(function () use ($entries) {
            $entries = $this->rescue(fn () => $entries->filter($this->shouldRecord(...))) ?? collect([]);

            if ($entries->isEmpty()) {
                $this->forgetRememberedUser();

                return 0;
            }

            $ingest = $this->app->make(Ingest::class);

            $count = $this->rescue(function () use ($entries, $ingest) {
                $ingest->ingest($entries); /* @phpstan-ignore argument.type */

                return $entries->count();
            }) ?? 0;

            $odds = $this->app->make('config')->get('pulse.ingest.trim.lottery') ?? $this->app->make('config')->get('pulse.ingest.trim_lottery');

            Lottery::odds(...$odds)
                ->winner(fn () => $this->rescue($ingest->trim(...)))
                ->choose();

            $this->forgetRememberedUser();

            return $count;
        });
    }

    /**
     * Start ingesting entries if over buffer size.
     *
     * Only one buffer ingest runs at a time. Any fiber that records while it
     * is in flight (including child fibers a lazy resolver awaits, and the
     * holder itself recursing through record()) returns immediately instead
     * of waiting: the holder swaps out and ingests the whole buffer, and
     * anything recorded after that swap stays for the next ingest. Never
     * waiting here keeps record() free of suspension points, so it is safe
     * inside query listeners and transactions, and a resolver that awaits
     * recording children cannot deadlock on its own ingest.
     */
    protected function ingestWhenOverBufferSize(): void
    {
        if ($this->bufferIngestInFlight) {
            return;
        }

        $buffer = $this->app->make('config')->get('pulse.ingest.buffer') ?? 5_000;

        if (($this->entries->count() + $this->lazy->count()) <= $buffer) {
            return;
        }

        // No suspension point between the check above and this assignment,
        // so exactly one fiber claims the buffer ingest.
        $this->bufferIngestInFlight = true;

        try {
            $this->resolveLazyEntries();

            if ($this->entries->count() > $buffer) {
                $this->ingest();
            }
        } finally {
            $this->bufferIngestInFlight = false;
        }
    }

    /**
     * Resolve lazy entries.
     *
     * The resolvers are taken before any of them runs, so a resolver queued
     * while one of them is suspended survives for the next round.
     */
    protected function resolveLazyEntries(): void
    {
        $lazy = $this->lazy;

        $this->lazy = collect([]);

        $this->rescue(fn () => $lazy->each(fn ($resolver) => $resolver()));
    }

    /**
     * Take the buffered entries, leaving a fresh buffer behind.
     *
     * @return Collection<int, Entry|Value>
     */
    private function takeEntries(): Collection
    {
        $entries = $this->entries;

        $this->entries = collect([]);

        return $entries;
    }

    /**
     * Forget the remembered user once no queued lazy resolver may need it.
     */
    private function forgetRememberedUser(): void
    {
        if ($this->lazy->isEmpty()) {
            $this->rememberedUserId = null;
        }
    }

    /**
     * The ignore() depth of the current fiber or the main context.
     */
    private function ignoreDepth(): int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->mainIgnoreDepth : ($this->fiberIgnoreDepths[$fiber] ?? 0);
    }

    private function adjustIgnoreDepth(?Fiber $fiber, int $delta): void
    {
        if ($fiber === null) {
            $this->mainIgnoreDepth = max(0, $this->mainIgnoreDepth + $delta);

            return;
        }

        $depth = ($this->fiberIgnoreDepths[$fiber] ?? 0) + $delta;

        if ($depth > 0) {
            $this->fiberIgnoreDepths[$fiber] = $depth;
        } else {
            unset($this->fiberIgnoreDepths[$fiber]);
        }
    }
}
