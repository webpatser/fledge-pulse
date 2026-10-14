<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Fiber\Storage\FiberDatabaseStorage;
use Laravel\Pulse\Storage\DatabaseStorage;
use Laravel\Pulse\Tests\Contract\StorageContract;

const CONTRACT_NATIVE_CONNECTION = 'pulse_contract_native';

// A dedicated database keeps these tests away from the migrate:fresh that
// RefreshDatabase runs against the default test database.
const CONTRACT_NATIVE_DATABASE = 'pulse_fiber_contract_test';

beforeEach(function () {
    // The contract drives the storage directly; keep Pulse's recorders from
    // buffering entries about the queries it runs.
    Pulse::stopRecording();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Connect to the local MariaDB server and make sure the dedicated database
 * exists. Returns false when the server cannot be reached.
 */
function contractMariaDbAvailable(): bool
{
    if (! extension_loaded('pdo_mysql')) {
        return false;
    }

    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);

        $pdo->exec('create database if not exists `'.CONTRACT_NATIVE_DATABASE.'`');
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * Register a `fledge-mariadb-native` connection (fiberio optional, so it runs
 * without the extension), point Pulse's storage at it and create the Pulse
 * tables with the shipped migration.
 */
function contractUseNativeConnection(): void
{
    Config::set('database.connections.'.CONTRACT_NATIVE_CONNECTION, [
        'driver' => 'fledge-mariadb-native',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => CONTRACT_NATIVE_DATABASE,
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
        'fiberio' => 'optional',
        'pool_size' => 4,
    ]);

    DB::purge(CONTRACT_NATIVE_CONNECTION);

    Config::set('pulse.storage.database.connection', CONTRACT_NATIVE_CONNECTION);

    $exitCode = Artisan::call('migrate:fresh', [
        '--database' => CONTRACT_NATIVE_CONNECTION,
        '--path' => realpath(__DIR__.'/../../database/migrations/2023_06_07_000001_create_pulse_tables.php'),
        '--realpath' => true,
    ]);

    expect($exitCode)->toBe(0, Artisan::output());
}

describe('upstream DatabaseStorage on the default connection', function () {
    // Keeps the captured expectations honest: they must be what upstream returns.
    it('matches the contract', function (string $case) {
        StorageContract::assertCase(new DatabaseStorage(app('db'), app('config')), $case);
    })->with(StorageContract::dataset());
});

describe('FiberDatabaseStorage on the default connection', function () {
    it('matches the contract', function (string $case) {
        StorageContract::assertCase(new FiberDatabaseStorage(app('db'), app('config')), $case);
    })->with(StorageContract::dataset());

    it('passes the whole contract in one call', function () {
        StorageContract::assertContract(new FiberDatabaseStorage(app('db'), app('config')));

        expect(Carbon::hasTestNow())->toBeFalse();
    });
});

describe('FiberDatabaseStorage on a fledge-mariadb-native connection', function () {
    beforeEach(function () {
        if (! contractMariaDbAvailable()) {
            test()->markTestSkipped('MariaDB is not reachable at 127.0.0.1 as root.');
        }

        contractUseNativeConnection();
    });

    afterEach(function () {
        DB::purge(CONTRACT_NATIVE_CONNECTION);
    });

    it('matches the contract', function (string $case) {
        expect(DB::connection(CONTRACT_NATIVE_CONNECTION)->getDriverName())->toBe('fledge-mariadb-native');

        StorageContract::assertCase(new FiberDatabaseStorage(app('db'), app('config')), $case);
    })->with(StorageContract::dataset());
});
