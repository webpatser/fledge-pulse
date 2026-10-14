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
use Laravel\Pulse\Storage\DatabaseStorage;
use Laravel\Pulse\Value;

require_once __DIR__.'/MariaDbStorageSchema.php';

// These tests drive the storage directly; keep Pulse's own recorders from
// buffering entries about the test's queries.
beforeEach(fn () => Pulse::stopRecording());

function fiberStorageFor(?string $connection): FiberDatabaseStorage
{
    Config::set('pulse.storage.database.connection', $connection);

    return new FiberDatabaseStorage(app('db'), app('config'));
}

/**
 * Store entries and values, then read them back through every read method.
 */
function fiberStorageExerciseRoundTrip(FiberDatabaseStorage $storage): void
{
    $storage->purge(['fiber_slow', 'fiber_system']);

    $now = CarbonImmutable::now()->getTimestamp();

    $storage->store(new Collection([
        (new Entry($now, 'fiber_slow', 'a', 100))->count()->min()->max()->sum()->avg(),
        (new Entry($now, 'fiber_slow', 'a', 300))->count()->min()->max()->sum()->avg(),
        (new Entry($now, 'fiber_slow', 'b', 50))->count()->min()->max()->sum()->avg(),
        new Value($now - 5, 'fiber_system', 'srv1', '{"cpu":1}'),
        new Value($now, 'fiber_system', 'srv1', '{"cpu":2}'),
        new Value($now, 'fiber_system', 'srv2', '{"cpu":3}'),
    ]));

    // A second store hits the "on duplicate key / on conflict" update path of every aggregate.
    $storage->store(new Collection([
        (new Entry($now, 'fiber_slow', 'a', 500))->count()->min()->max()->sum()->avg(),
        new Value($now + 1, 'fiber_system', 'srv1', '{"cpu":4}'),
    ]));

    $values = $storage->values('fiber_system');
    expect($values->keys()->sort()->values()->all())->toBe(['srv1', 'srv2']);
    expect($values->get('srv1')->value)->toBe('{"cpu":4}');
    expect((int) $values->get('srv1')->timestamp)->toBe($now + 1);
    expect($storage->values('fiber_system', ['srv2'])->keys()->all())->toBe(['srv2']);

    $rows = $storage->aggregate('fiber_slow', ['count', 'min', 'max', 'sum', 'avg'], CarbonInterval::hour(), 'count');
    expect($rows)->toHaveCount(2);

    $a = $rows->firstWhere('key', 'a');
    expect((float) $a->count)->toBe(3.0);
    expect((float) $a->min)->toBe(100.0);
    expect((float) $a->max)->toBe(500.0);
    expect((float) $a->sum)->toBe(900.0);
    expect((float) $a->avg)->toBe(300.0);

    $b = $rows->firstWhere('key', 'b');
    expect((float) $b->count)->toBe(1.0);
    expect((float) $b->sum)->toBe(50.0);

    // Ordered by count desc.
    expect($rows->first()->key)->toBe('a');

    expect($storage->aggregateTotal('fiber_slow', 'count', CarbonInterval::hour()))->toBe(4.0);
    expect($storage->aggregateTotal('fiber_slow', 'sum', CarbonInterval::hour()))->toBe(950.0);

    $totals = $storage->aggregateTotal(['fiber_slow'], 'count', CarbonInterval::hour());
    expect((float) $totals->get('fiber_slow'))->toBe(4.0);

    $graph = $storage->graph(['fiber_slow'], 'count', CarbonInterval::hour());
    expect($graph->keys()->sort()->values()->all())->toBe(['a', 'b']);
    expect((float) $graph->get('a')->get('fiber_slow')->filter()->sum())->toBe(3.0);

    $storage->purge(['fiber_slow', 'fiber_system']);

    expect($storage->values('fiber_system'))->toBeEmpty();
}

describe('on a fledge-mariadb-native connection', function () {
    beforeEach(function () {
        if (! fiberStorageMariaDbAvailable()) {
            test()->markTestSkipped('MariaDB is not reachable at 127.0.0.1 as root.');
        }

        fiberStorageRegisterNativeConnection();
    });

    afterEach(function () {
        DB::purge(FIBER_STORAGE_NATIVE_CONNECTION);
    });

    it('is a connection whose driver name the upstream storage rejects', function () {
        $connection = DB::connection(FIBER_STORAGE_NATIVE_CONNECTION);

        expect($connection)->toBeInstanceOf(Connection::class);
        expect($connection->getDriverName())->toBe('fledge-mariadb-native');

        Config::set('pulse.storage.database.connection', FIBER_STORAGE_NATIVE_CONNECTION);

        $upstream = new DatabaseStorage(app('db'), app('config'));

        $entry = (new Entry(CarbonImmutable::now()->getTimestamp(), 'fiber_slow', 'a', 1))->count();

        expect(fn () => $upstream->store(new Collection([$entry])))
            ->toThrow(RuntimeException::class, 'Unsupported database driver [fledge-mariadb-native]');
    });

    it('resolves the mysql dialect from the connection class', function () {
        $storage = fiberStorageFor(FIBER_STORAGE_NATIVE_CONNECTION);

        expect((fn () => $this->dialect())->call($storage))->toBe('mysql');
        expect((fn () => $this->requiresManualKeyHash())->call($storage))->toBeFalse();
    });

    it('stores entries and values and reads them back', function () {
        fiberStorageExerciseRoundTrip(fiberStorageFor(FIBER_STORAGE_NATIVE_CONNECTION));
    });
});

describe('on the default test connection', function () {
    it('resolves the dialect matching the driver and round-trips', function () {
        $storage = fiberStorageFor(null);

        $expected = match (DB::connection()->getDriverName()) {
            'mariadb', 'mysql' => 'mysql',
            'pgsql' => 'pgsql',
            'sqlite' => 'sqlite',
        };

        expect((fn () => $this->dialect())->call($storage))->toBe($expected);
        expect((fn () => $this->requiresManualKeyHash())->call($storage))->toBe($expected === 'sqlite');

        fiberStorageExerciseRoundTrip($storage);
    });
});
