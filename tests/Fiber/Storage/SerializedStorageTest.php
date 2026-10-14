<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Fiber\Storage\FiberDatabaseStorage;
use Laravel\Pulse\Fiber\Support\ConnectionMutex;
use Laravel\Pulse\Value;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\awaitAll;

require_once __DIR__.'/MariaDbStorageSchema.php';

// What these tests can and cannot show without the fiberio extension: with a
// blocking PDO a query never suspends its fiber, so the original driver errors
// ("stream is in use by another fiber", unbuffered-query errors) do not
// reproduce locally. The probe below suspends (delay()) every time an
// operation touches the connection, which is exactly where a fiberio-hooked
// PDO would suspend, and records which task was running. A serialized storage
// shows one contiguous run per task; an unserialized one interleaves.

/**
 * Records which task touches the connection and yields to other fibers on
 * every touch.
 */
class SerializedStorageProbe extends FiberDatabaseStorage
{
    /** @var array<int, string> */
    public array $labels = [];

    /** @var list<string> */
    public array $trace = [];

    public function label(string $label): void
    {
        $this->labels[$this->fiberId()] = $label;
    }

    protected function connection(): Connection
    {
        $this->trace[] = $this->labels[$this->fiberId()] ?? 'unlabelled';

        delay(0.001);

        return parent::connection();
    }

    // Resolve the lock key without the probe's tracing and yielding.
    protected function lockConnection(): Connection
    {
        return parent::connection();
    }

    private function fiberId(): int
    {
        return spl_object_id(Fiber::getCurrent() ?? $this);
    }
}

/**
 * The same probe with the serialization switched off, to prove the probe
 * detects overlap.
 */
class UnserializedStorageProbe extends SerializedStorageProbe
{
    protected function serialized(Closure $callback): mixed
    {
        return $callback();
    }
}

/**
 * Calls a public (serialized) read from inside store()'s locked transaction.
 */
class ReentrantStorageProbe extends FiberDatabaseStorage
{
    public ?Collection $nested = null;

    protected function upsertCount(array $values): int
    {
        $this->nested = $this->values('fiber_par_sys');

        return parent::upsertCount($values);
    }
}

beforeEach(fn () => Pulse::stopRecording());

/**
 * @template T of FiberDatabaseStorage
 *
 * @param  class-string<T>  $class
 * @return T
 */
function serializedStorage(?string $connection, string $class = FiberDatabaseStorage::class): FiberDatabaseStorage
{
    Config::set('pulse.storage.database.connection', $connection);

    return new $class(app('db'), app('config'));
}

function serializedStorageSeed(FiberDatabaseStorage $storage): int
{
    $storage->purge(['fiber_par', 'fiber_par_sys', 'fiber_par_new']);

    $now = CarbonImmutable::now()->getTimestamp();

    $storage->store(new Collection([
        (new Entry($now, 'fiber_par', 'a', 100))->count()->sum(),
        (new Entry($now, 'fiber_par', 'a', 300))->count()->sum(),
        (new Entry($now, 'fiber_par', 'b', 50))->count()->sum(),
        new Value($now, 'fiber_par_sys', 'srv1', '{"cpu":1}'),
    ]));

    return $now;
}

/**
 * Run every task in its own fiber, all started before any of them finishes.
 *
 * @param  array<string, Closure(SerializedStorageProbe): mixed>  $tasks
 * @return array<string, mixed>
 */
function serializedStorageRunParallel(SerializedStorageProbe $probe, array $tasks): array
{
    $futures = [];

    foreach ($tasks as $label => $task) {
        $futures[$label] = async(function () use ($probe, $label, $task) {
            $probe->label($label);

            return $task($probe);
        });
    }

    [$errors, $results] = awaitAll($futures);

    expect(array_map(fn (Throwable $e) => $e::class.': '.$e->getMessage(), $errors))->toBe([]);

    return $results;
}

/**
 * Collapse consecutive duplicates: one entry per uninterrupted run of a task.
 *
 * @param  list<string>  $trace
 * @return list<string>
 */
function serializedStorageRuns(array $trace): array
{
    $runs = [];

    foreach ($trace as $label) {
        if ($runs === [] || $runs[array_key_last($runs)] !== $label) {
            $runs[] = $label;
        }
    }

    return $runs;
}

/**
 * Five reads and a store in parallel fibers on one connection: no errors,
 * correct results, and each operation ran without another one interleaving.
 */
function serializedStorageExerciseParallel(?string $connection): void
{
    $now = serializedStorageSeed(serializedStorage($connection));

    $probe = serializedStorage($connection, SerializedStorageProbe::class);

    $tasks = [
        'values' => fn (SerializedStorageProbe $s) => $s->values('fiber_par_sys'),
        'aggregate' => fn (SerializedStorageProbe $s) => $s->aggregate('fiber_par', ['count', 'sum'], CarbonInterval::hour()),
        'store' => fn (SerializedStorageProbe $s) => $s->store(new Collection([
            (new Entry($now, 'fiber_par_new', 'c', 7))->count(),
            new Value($now, 'fiber_par_sys', 'srv2', '{"cpu":2}'),
        ])),
        'aggregateTypes' => fn (SerializedStorageProbe $s) => $s->aggregateTypes(['fiber_par'], 'count', CarbonInterval::hour()),
        'aggregateTotal' => fn (SerializedStorageProbe $s) => $s->aggregateTotal('fiber_par', 'count', CarbonInterval::hour()),
        'graph' => fn (SerializedStorageProbe $s) => $s->graph(['fiber_par'], 'count', CarbonInterval::hour()),
        'values_again' => fn (SerializedStorageProbe $s) => $s->values('fiber_par_sys', ['srv1']),
    ];

    $results = serializedStorageRunParallel($probe, $tasks);

    // One uninterrupted run per operation, granted in arrival order.
    expect(serializedStorageRuns($probe->trace))->toBe(array_keys($tasks));

    expect($results['values']->get('srv1')->value)->toBe('{"cpu":1}');
    expect($results['values_again']->keys()->all())->toBe(['srv1']);

    $a = $results['aggregate']->firstWhere('key', 'a');
    expect((float) $a->count)->toBe(2.0);
    expect((float) $a->sum)->toBe(400.0);
    $b = $results['aggregate']->firstWhere('key', 'b');
    expect((float) $b->count)->toBe(1.0);
    expect((float) $b->sum)->toBe(50.0);

    expect((float) $results['aggregateTypes']->firstWhere('key', 'a')->fiber_par)->toBe(2.0);
    expect($results['aggregateTotal'])->toBe(3.0);

    expect($results['graph']->keys()->sort()->values()->all())->toBe(['a', 'b']);
    expect((float) $results['graph']->get('a')->get('fiber_par')->filter()->sum())->toBe(2.0);

    // The concurrent store landed.
    $storage = serializedStorage($connection);
    expect($storage->aggregateTotal('fiber_par_new', 'count', CarbonInterval::hour()))->toBe(1.0);
    expect($storage->values('fiber_par_sys')->keys()->sort()->values()->all())->toBe(['srv1', 'srv2']);

    $storage->purge(['fiber_par', 'fiber_par_sys', 'fiber_par_new']);
}

describe('ConnectionMutex', function () {
    it('lets one fiber at a time in, in arrival order', function () {
        $name = 'mutex-'.uniqid();

        // Shared state lives on an object, not in by-reference locals: an
        // arrow function anywhere in the chain captures by value and would
        // silently detach a by-reference counter (maxActive stuck at 0).
        $state = new stdClass;
        $state->active = 0;
        $state->maxActive = 0;
        $state->order = [];

        $futures = array_map(
            fn (int $i) => async(fn () => ConnectionMutex::run($name, function () use ($i, $state) {
                $state->active++;
                $state->maxActive = max($state->maxActive, $state->active);
                $state->order[] = $i;

                // Suspend while holding the lock so the other fibers queue up.
                delay(0.002);

                $state->active--;

                return $i * 10;
            })),
            range(1, 5),
        );

        [$errors, $results] = awaitAll($futures);

        expect($errors)->toBe([]);
        expect($results)->toBe([10, 20, 30, 40, 50]);
        expect($state->maxActive)->toBe(1);
        expect($state->order)->toBe([1, 2, 3, 4, 5]);
    });

    it('is reentrant within the same fiber and in the main context', function () {
        $name = 'mutex-'.uniqid();

        $nested = fn () => ConnectionMutex::run($name, fn () => ConnectionMutex::run($name, fn () => 'inner'));

        expect($nested())->toBe('inner');
        expect(async($nested)->await())->toBe('inner');
    });

    it('releases the mutex when the callback throws', function () {
        $name = 'mutex-'.uniqid();

        expect(fn () => async(fn () => ConnectionMutex::run($name, fn () => throw new RuntimeException('boom')))->await())
            ->toThrow(RuntimeException::class, 'boom');

        expect(async(fn () => ConnectionMutex::run($name, fn () => 'free'))->await())->toBe('free');
        expect(ConnectionMutex::run($name, fn () => 'free'))->toBe('free');
    });

    it('does not let a waiting fiber in while another fiber holds the mutex', function () {
        $name = 'mutex-'.uniqid();
        $events = [];

        $holder = async(function () use ($name, &$events) {
            ConnectionMutex::run($name, function () use (&$events) {
                $events[] = 'holder in';
                delay(0.005);
                $events[] = 'holder out';
            });
        });

        $waiter = async(function () use ($name, &$events) {
            ConnectionMutex::run($name, function () use (&$events) {
                $events[] = 'waiter in';
            });
        });

        awaitAll([$holder, $waiter]);

        expect($events)->toBe(['holder in', 'holder out', 'waiter in']);
    });
});

describe('on the default test connection', function () {
    it('needs serialization on a shared-PDO connection', function () {
        expect(ConnectionMutex::requiredFor(DB::connection()))->toBeTrue();
    });

    it('serializes parallel reads and a store', function () {
        serializedStorageExerciseParallel(null);
    });

    it('interleaves without serialization, so the probe detects overlap', function () {
        serializedStorageSeed(serializedStorage(null));

        $probe = serializedStorage(null, UnserializedStorageProbe::class);

        $tasks = array_fill_keys(['r1', 'r2', 'r3', 'r4', 'r5'], fn (SerializedStorageProbe $s) => $s->aggregate('fiber_par', ['count', 'sum'], CarbonInterval::hour()));

        $results = serializedStorageRunParallel($probe, $tasks);

        expect(count(serializedStorageRuns($probe->trace)))->toBeGreaterThan(count($tasks));

        foreach ($results as $rows) {
            expect((float) $rows->firstWhere('key', 'a')->count)->toBe(2.0);
        }

        serializedStorage(null)->purge(['fiber_par', 'fiber_par_sys']);
    });

    it('does not deadlock when a storage call is made while holding the lock', function () {
        serializedStorageSeed(serializedStorage(null));

        $entry = fn () => new Collection([(new Entry(CarbonImmutable::now()->getTimestamp(), 'fiber_par_new', 'c', 1))->count()]);

        $inFiber = serializedStorage(null, ReentrantStorageProbe::class);
        async(fn () => $inFiber->store($entry()))->await();
        expect($inFiber->nested?->keys()->all())->toBe(['srv1']);

        $inMain = serializedStorage(null, ReentrantStorageProbe::class);
        $inMain->store($entry());
        expect($inMain->nested?->keys()->all())->toBe(['srv1']);

        expect(serializedStorage(null)->aggregateTotal('fiber_par_new', 'count', CarbonInterval::hour()))->toBe(2.0);

        serializedStorage(null)->purge(['fiber_par', 'fiber_par_sys', 'fiber_par_new']);
    });
});

describe('on MariaDB', function () {
    beforeEach(function () {
        if (! fiberStorageMariaDbAvailable()) {
            test()->markTestSkipped('MariaDB is not reachable at 127.0.0.1 as root.');
        }
    });

    afterEach(function () {
        DB::purge(FIBER_STORAGE_SHARED_CONNECTION);
        DB::purge(FIBER_STORAGE_NATIVE_CONNECTION);
    });

    it('serializes parallel reads and a store on a plain mariadb connection (one shared PDO)', function () {
        fiberStorageRegisterSharedConnection();

        $connection = DB::connection(FIBER_STORAGE_SHARED_CONNECTION);
        expect($connection->getDriverName())->toBe('mariadb');
        expect(ConnectionMutex::requiredFor($connection))->toBeTrue();

        serializedStorageExerciseParallel(FIBER_STORAGE_SHARED_CONNECTION);

        // Every store transaction was committed and closed.
        expect($connection->transactionLevel())->toBe(0);
    });

    it('runs native-pool connections unserialized', function () {
        fiberStorageRegisterNativeConnection();

        expect(ConnectionMutex::requiredFor(DB::connection(FIBER_STORAGE_NATIVE_CONNECTION)))->toBeFalse();

        serializedStorageSeed(serializedStorage(FIBER_STORAGE_NATIVE_CONNECTION));

        $probe = serializedStorage(FIBER_STORAGE_NATIVE_CONNECTION, SerializedStorageProbe::class);

        $tasks = array_fill_keys(['r1', 'r2', 'r3', 'r4', 'r5'], fn (SerializedStorageProbe $s) => $s->aggregate('fiber_par', ['count', 'sum'], CarbonInterval::hour()));

        $results = serializedStorageRunParallel($probe, $tasks);

        expect(count(serializedStorageRuns($probe->trace)))->toBeGreaterThan(count($tasks));

        foreach ($results as $rows) {
            expect((float) $rows->firstWhere('key', 'a')->count)->toBe(2.0);
        }
    });
});
