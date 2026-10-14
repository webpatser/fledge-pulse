<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Fiber\Support\SqlDialect;
use Laravel\Pulse\Support\PulseMigration;

/**
 * Register a `fledge-mariadb-native` connection. Connections are lazy, so no
 * server needs to be reachable to ask which dialect it uses.
 */
function pulseMigrationNativeConnection(): string
{
    Config::set('database.connections.pulse_migration_native', [
        'driver' => 'fledge-mariadb-native',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'pulse_migration_native_test',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
        'fiberio' => 'optional',
        'pool_size' => 1,
    ]);

    DB::purge('pulse_migration_native');

    return 'pulse_migration_native';
}

function pulseMigration(): PulseMigration
{
    return new class extends PulseMigration
    {
        public function resolvedDriver(): string
        {
            return $this->driver();
        }
    };
}

it('runs on a fledge-mariadb-native connection', function () {
    Config::set('pulse.storage.database.connection', pulseMigrationNativeConnection());

    $migration = pulseMigration();

    expect(DB::connection('pulse_migration_native')->getDriverName())->toBe('fledge-mariadb-native')
        ->and($migration->resolvedDriver())->toBe('mysql')
        ->and($migration->shouldRun())->toBeTrue();
});

it('runs on the default test connection', function () {
    $migration = pulseMigration();

    expect($migration->resolvedDriver())->toBeIn(['mysql', 'pgsql', 'sqlite'])
        ->and($migration->shouldRun())->toBeTrue();
});

it('resolves the dialect of a native connection by class', function () {
    $connection = DB::connection(pulseMigrationNativeConnection());

    expect(SqlDialect::resolve($connection))->toBe('mysql');
});
