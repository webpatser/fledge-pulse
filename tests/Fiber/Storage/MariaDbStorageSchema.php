<?php

// Shared MariaDB helpers for the tests in this directory. Not a test file:
// the *Test.php files pull it in with require_once.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const FIBER_STORAGE_NATIVE_CONNECTION = 'pulse_native';

const FIBER_STORAGE_SHARED_CONNECTION = 'pulse_shared';

// A dedicated database keeps these tests away from the migrate:fresh and the
// wrapping transaction that RefreshDatabase runs against pulse_test, so the
// storage's own transactions are real top-level transactions here.
const FIBER_STORAGE_NATIVE_DATABASE = 'pulse_fiber_native_test';

/**
 * Connect to the local MariaDB server and make sure the dedicated database exists.
 * Returns false when the server cannot be reached.
 */
function fiberStorageMariaDbAvailable(): bool
{
    if (! extension_loaded('pdo_mysql')) {
        return false;
    }

    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);

        $pdo->exec('create database if not exists `'.FIBER_STORAGE_NATIVE_DATABASE.'`');
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * Register a `fledge-mariadb-native` connection (the real fledge-fiber driver,
 * with fiberio optional so it also runs without the extension) and create the
 * Pulse tables on it.
 */
function fiberStorageRegisterNativeConnection(): void
{
    fiberStorageRegisterMariaDbConnection(FIBER_STORAGE_NATIVE_CONNECTION, [
        'driver' => 'fledge-mariadb-native',
        'fiberio' => 'optional',
        'pool_size' => 4,
    ]);
}

/**
 * Register a plain `mariadb` connection: one PDO shared by every fiber, the
 * default for apps without the native pool. Creates the Pulse tables on it.
 */
function fiberStorageRegisterSharedConnection(): void
{
    fiberStorageRegisterMariaDbConnection(FIBER_STORAGE_SHARED_CONNECTION, [
        'driver' => 'mariadb',
    ]);
}

/**
 * Register a MariaDB connection on the dedicated database and (re)create the
 * Pulse tables. The shipped migration is not used because upstream matched it
 * on the driver name, and this keeps the schema independent of it.
 *
 * @param  array<string, mixed>  $config
 */
function fiberStorageRegisterMariaDbConnection(string $name, array $config): void
{
    Config::set("database.connections.{$name}", [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => FIBER_STORAGE_NATIVE_DATABASE,
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
        ...$config,
    ]);

    DB::purge($name);

    $schema = Schema::connection($name);

    $schema->dropIfExists('pulse_values');
    $schema->dropIfExists('pulse_entries');
    $schema->dropIfExists('pulse_aggregates');

    $schema->create('pulse_values', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('timestamp');
        $table->string('type');
        $table->mediumText('key');
        $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(md5(`key`))');
        $table->mediumText('value');

        $table->index('timestamp');
        $table->index('type');
        $table->unique(['type', 'key_hash']);
    });

    $schema->create('pulse_entries', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('timestamp');
        $table->string('type');
        $table->mediumText('key');
        $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(md5(`key`))');
        $table->bigInteger('value')->nullable();

        $table->index('timestamp');
        $table->index('type');
        $table->index('key_hash');
        $table->index(['timestamp', 'type', 'key_hash', 'value']);
    });

    $schema->create('pulse_aggregates', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('bucket');
        $table->unsignedMediumInteger('period');
        $table->string('type');
        $table->mediumText('key');
        $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(md5(`key`))');
        $table->string('aggregate');
        $table->decimal('value', 20, 2);
        $table->unsignedInteger('count')->nullable();

        $table->unique(['bucket', 'period', 'type', 'aggregate', 'key_hash']);
        $table->index(['period', 'bucket']);
        $table->index('type');
        $table->index(['period', 'type', 'aggregate', 'bucket']);
    });
}
