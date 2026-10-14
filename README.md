# Fledge Pulse

Fiber-safe Pulse for the Fledge framework, with pluggable storage drivers.

[![Tests](https://github.com/webpatser/fledge-pulse/actions/workflows/tests.yml/badge.svg?branch=fledge/1.x)](https://github.com/webpatser/fledge-pulse/actions/workflows/tests.yml)
[![Packagist Version](https://img.shields.io/packagist/v/webpatser/fledge-pulse)](https://packagist.org/packages/webpatser/fledge-pulse)
[![License](https://img.shields.io/packagist/l/webpatser/fledge-pulse)](LICENSE.md)

`webpatser/fledge-pulse` is Laravel Pulse made safe for fibers. It targets apps running on [Fledge](https://github.com/webpatser/fledge-framework), where Livewire cards render in parallel fibers, databases may be native async drivers (such as `fledge-mariadb-native`) and Redis may be the Fledge client (`REDIS_CLIENT=fledge`). On stock Pulse that combination gives an empty dashboard, "stream is in use by another fiber" errors and, quite possibly, silently dropped recordings.

## Install

`fledge-pulse` `replace`s `laravel/pulse`, so remove the original first:

```bash
composer remove laravel/pulse
composer require webpatser/fledge-pulse
```

## Requirements

- PHP 8.5
- `webpatser/fledge-framework`
- `webpatser/fledge-fiber`
- Livewire 4

## Usage

Configuration, recorders and cards work exactly like Laravel Pulse, so the [Laravel Pulse docs](https://laravel.com/docs/pulse) cover dashboard and recorder usage. This README only covers what Fledge adds: the storage driver registry and the fiber guarantees below.

## What it is

- The `Laravel\Pulse` namespace is kept and `composer.json` has `"replace": {"laravel/pulse": "self.version"}`, so packages that depend on `laravel/pulse` (Reverb cards, `resonate-pulse` and friends) keep resolving.
- The fiber code is an overlay under `src/Fiber/` (`Laravel\Pulse\Fiber\...`). An auto-discovered `FledgePulseServiceProvider` rebinds the pieces that need it.
- Besides `composer.json`, exactly one upstream runtime file is edited: `src/Support/PulseMigration.php` (see below). The test setup is touched too: `phpunit.xml.dist` gains a `Fiber` testsuite and `tests/Pest.php` applies the shared test case to `tests/Fiber`. Neither ships behaviour.

## Versioning

Versions follow Fledge's four-segment scheme: `v1.8.1.0` is Fledge patch 0 on top of Pulse 1.8.1. The first three segments always match upstream; only the fourth moves for Fledge-only changes.

## Storage driver registry

Upstream always binds `DatabaseStorage`, whatever `pulse.storage.driver` says. Here that setting works. `Laravel\Pulse\Fiber\Storage\StorageManager` is a standard Laravel `Manager`, `Storage::class` resolves through it, and the default driver is `database` (`FiberDatabaseStorage`).

`FiberDatabaseStorage` is fiber-safe on every supported connection, in two different ways:

- **Native pool** (`fledge-mariadb-native`, `fledge-mysql-native`, or any connection using fledge-fiber's `UsesNativePdoPool`): each fiber leases its own PDO, so parallel cards query truly in parallel and nothing is serialized.
- **Shared PDO** (plain `mariadb`, `mysql`, `pgsql`, `sqlite`, `fledge-mariadb`, ...): one PDO serves every fiber. Each storage operation (`store` with its transaction, `trim`, `purge` and every read, including fully reading the result set) runs under a per-connection `LocalMutex`, so parallel cards take turns instead of failing with "stream is in use by another fiber", unbuffered-query errors or "already an active transaction". Correct, but sequential.

The lock is reentrant within one fiber. Don't start a child fiber with `async()` and await it from inside a storage call on the same shared connection: the child would wait for the lock its parent holds.

**Octane and other long-lived workers.** `StorageManager` caches the driver instance per application instance, like every Laravel `Manager`. If you change `pulse.storage.*` config after the first resolve, call `app(StorageManager::class)->forgetDrivers()` so the next resolve picks it up. The storage mutexes are static and keyed by connection name, so they live for the whole worker process; that is intended, because an idle mutex holds no state and the holder is cleared on every exit path.

Pick a driver in `config/pulse.php` or the environment:

```dotenv
PULSE_STORAGE_DRIVER=database
```

A package (or your app) registers its own driver with `extend`. The callback gets the container and returns a `Laravel\Pulse\Contracts\Storage`:

```php
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Fiber\Storage\StorageManager;

public function boot(): void
{
    $this->app->make(StorageManager::class)->extend('clickhouse', function ($app): Storage {
        return new ClickHouseStorage($app['config']->get('pulse.storage.clickhouse'));
    });
}
```

Then set `PULSE_STORAGE_DRIVER=clickhouse`. Implement every method on the `Storage` contract (`store`, `trim`, `purge`, `values`, `graph`, `aggregate`, `aggregateTypes`, `aggregateTotal`) and return the same shapes as `DatabaseStorage`; the cards depend on them. Avoid `static` closures in `extend`, because `Manager` rebinds the callback to itself.

An Elasticsearch driver is available as [`webpatser/fledge-pulse-elasticsearch`](https://github.com/webpatser/fledge-pulse-elasticsearch). It stores raw entries and aggregates at query time, and is selected with `PULSE_STORAGE_DRIVER=elasticsearch`.

## Fiber guarantees

- **Dialect by connection class.** `FiberDatabaseStorage` picks its upsert SQL from the connection class (MySQL family, Postgres, SQLite), not from `getDriverName()`. Native drivers like `fledge-mariadb-native` store and read like any MySQL connection.
- **Serialized storage on a shared PDO.** On connections without the native pool, storage operations take turns under a per-connection mutex (see above). Native-pool connections are not serialized.
- **Fiber-scoped `ignore()`.** `Pulse::ignore()` keeps a depth per fiber instead of saving and restoring one shared flag, so overlapping cards can no longer leave recording switched off. The depth is **not** inherited by child fibers started with `Fledge\Async\async()` inside the callback; wrap those closures in `ignore()` too if they must not record.
- **Ingest swaps buffers before I/O.** `ingest()` takes the entries and lazy resolvers out of the live buffers before it suspends, so anything recorded meanwhile is ingested next time instead of being flushed away.
- **One buffer-size ingest at a time.** When the buffer grows past `pulse.ingest.buffer`, one fiber ingests. Fibers that record while that ingest is in flight return immediately instead of waiting; their entries go into the next ingest.
- **Fledge Redis ingest.** With `REDIS_CLIENT=fledge`, the Redis ingest runs through `FledgeRedisConnection`. Upstream's `RedisAdapter` is typed for PhpRedis, Predis and Relay, so on a Fledge connection it throws and Pulse's error handling swallows it: nothing gets recorded.
- **No blocking sleeps.** `pulse:work` and `pulse:check` wait through `Laravel\Pulse\Fiber\Support\FiberSleep`, a `Sleep` subclass that waits with `Fledge\Async\delay()`, so they yield to other fibers. `Sleep::fake()` still works in tests. Digest and trim stay sequential.
- **`rememberMany`.** Cards can use `Laravel\Pulse\Fiber\Livewire\Concerns\RemembersConcurrently` to remember several queries at once. Cache hits are returned as is; only the misses run, concurrently, through `FiberDB::concurrent`. It shares cache keys and TTL with `remember()`:

```php
use Laravel\Pulse\Fiber\Livewire\Concerns\RemembersConcurrently;

$results = $this->rememberMany([
    'all' => fn ($interval) => Pulse::aggregate('cache_hit', ['count'], $interval),
    'keys' => fn ($interval) => Pulse::aggregate('cache_hit', ['count'], $interval, limit: 25),
]);

[$all, $allTime, $allRunAt] = $results['all'];
```

  The queries only truly overlap when each fiber gets its own PDO, which needs the native pool (`fledge-mariadb-native`). On a shared-PDO connection the storage lock serializes them, so `rememberMany` is still safe there but no faster than calling `remember()` in a row.

## The one upstream file edited

`src/Support/PulseMigration.php` decided whether a migration should run by matching `getDriverName()` against `mariadb`, `mysql`, `pgsql` and `sqlite`, which rejected native connections. It now checks the connection class instead. Everything else upstream is untouched, which keeps syncing new Pulse releases cheap.

## Credits

fledge-pulse is based on [Laravel Pulse](https://github.com/laravel/pulse) v1.8.1, created by Taylor Otwell and Jess Archer with the Laravel team and contributors, and released under the MIT license. The original license and copyright notice are kept in `LICENSE.md`. This repo starts fresh from that source with a single commit, so the upstream commit history lives in laravel/pulse. Big thanks to Taylor, Jess and everyone who built Pulse; this package rides on their work.

## License

MIT, see [LICENSE.md](LICENSE.md).
