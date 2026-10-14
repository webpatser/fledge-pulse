<?php

namespace Laravel\Pulse\Fiber\Storage;

use Illuminate\Support\Manager;
use Laravel\Pulse\Contracts\Storage;

/**
 * Registry of Pulse storage drivers.
 *
 * Upstream binds the database storage regardless of `pulse.storage.driver`.
 * This manager adds the missing extension point: bind `Storage::class` to
 * `fn ($app) => $app->make(StorageManager::class)->driver()` and third-party
 * packages can register their own drivers.
 *
 * Register a driver with `extend`. The callback receives the application
 * container and must return a `Laravel\Pulse\Contracts\Storage` instance:
 *
 *     $manager->extend('elasticsearch', fn ($app): Storage => new MyStorage(...));
 *
 * Note that Manager rebinds the callback to the manager instance, so avoid
 * `static` closures. The manager must be registered as a singleton for
 * extended drivers to persist between resolutions.
 *
 * @method Storage driver(?string $driver = null)
 */
class StorageManager extends Manager
{
    /**
     * Get the default storage driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('pulse.storage.driver', 'database') ?: 'database';
    }

    /**
     * Create the database storage driver.
     *
     * Resolved through the container, the same way upstream builds
     * `DatabaseStorage` (database manager and config repository).
     */
    protected function createDatabaseDriver(): Storage
    {
        return $this->container->make(FiberDatabaseStorage::class);
    }
}
