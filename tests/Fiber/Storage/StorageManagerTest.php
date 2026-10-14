<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Fiber\Storage\FiberDatabaseStorage;
use Laravel\Pulse\Fiber\Storage\StorageManager;

/**
 * A small real Storage implementation used as a third-party driver.
 */
class InMemoryTestStorage implements Storage
{
    /** @var Collection<int, mixed> */
    public Collection $stored;

    public function __construct()
    {
        $this->stored = new Collection;
    }

    public function store(Collection $items): void
    {
        $this->stored = $this->stored->concat($items);
    }

    public function trim(): void
    {
        //
    }

    public function purge(?array $types = null): void
    {
        $this->stored = new Collection;
    }

    public function values(string $type, ?array $keys = null): Collection
    {
        return new Collection;
    }

    public function graph(array $types, string $aggregate, CarbonInterval $interval): Collection
    {
        return new Collection;
    }

    public function aggregate(string $type, string|array $aggregates, CarbonInterval $interval, ?string $orderBy = null, string $direction = 'desc', int $limit = 101): Collection
    {
        return new Collection;
    }

    public function aggregateTypes(string|array $types, string $aggregate, CarbonInterval $interval, ?string $orderBy = null, string $direction = 'desc', int $limit = 101): Collection
    {
        return new Collection;
    }

    public function aggregateTotal(array|string $types, string $aggregate, CarbonInterval $interval): float|Collection
    {
        return 0.0;
    }
}

/**
 * Test-local provider doing what FledgePulseServiceProvider will do.
 */
function registerStorageManagerProvider(): void
{
    app()->register(new class(app()) extends ServiceProvider
    {
        public function register(): void
        {
            $this->app->singleton(StorageManager::class);
            $this->app->bind(Storage::class, fn ($app) => $app->make(StorageManager::class)->driver());
        }
    });
}

it('resolves a custom extended driver for the storage contract', function () {
    registerStorageManagerProvider();

    $custom = new InMemoryTestStorage;

    app(StorageManager::class)->extend('memory', fn ($app) => $custom);
    config()->set('pulse.storage.driver', 'memory');

    expect(app(Storage::class))->toBe($custom)
        ->and(app(Storage::class))->toBeInstanceOf(InMemoryTestStorage::class);
});

it('resolves the fiber database storage by default', function () {
    if (! class_exists(FiberDatabaseStorage::class)) {
        $this->markTestSkipped('FiberDatabaseStorage does not exist yet.');
    }

    registerStorageManagerProvider();

    config()->set('pulse.storage.driver', null);

    expect(app(StorageManager::class)->getDefaultDriver())->toBe('database')
        ->and(app(Storage::class))->toBeInstanceOf(FiberDatabaseStorage::class);
});

it('throws for an unknown driver', function () {
    registerStorageManagerProvider();

    config()->set('pulse.storage.driver', 'nope');

    app(Storage::class);
})->throws(InvalidArgumentException::class, 'Driver [nope] not supported.');
