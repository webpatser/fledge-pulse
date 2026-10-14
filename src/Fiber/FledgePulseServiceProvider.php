<?php

namespace Laravel\Pulse\Fiber;

use Fledge\Fiber\Redis\FledgeRedisConnection;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Fiber\Commands\CheckCommand;
use Laravel\Pulse\Fiber\Commands\WorkCommand;
use Laravel\Pulse\Fiber\Ingests\FledgeRedisIngest;
use Laravel\Pulse\Fiber\Storage\StorageManager;
use Laravel\Pulse\Ingests\NullIngest;
use Laravel\Pulse\Ingests\RedisIngest;
use Laravel\Pulse\Ingests\StorageIngest;
use Laravel\Pulse\Pulse;
use RuntimeException;

/**
 * Overlays the fiber-safe implementations on top of Pulse's own bindings.
 *
 * This provider is listed after PulseServiceProvider in the package manifest,
 * so its register() runs second and its bindings replace Pulse's. Every
 * binding is resolved lazily: nothing here opens a database or Redis
 * connection while the application boots.
 */
class FledgePulseServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The facade accessor is Pulse::class, so the facade resolves FiberPulse too.
        $this->app->singleton(Pulse::class, FiberPulse::class);

        $this->app->singleton(StorageManager::class);
        $this->app->bind(Storage::class, fn (Application $app) => $app->make(StorageManager::class)->driver());

        $this->registerIngest();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerCommands();
    }

    /**
     * Register the ingest implementation.
     *
     * Mirrors upstream's driver selection; the only difference is that the
     * `redis` driver uses FledgeRedisIngest when the configured connection is
     * a Fledge Redis connection (upstream's adapter cannot talk to it).
     */
    protected function registerIngest(): void
    {
        $this->app->bind(Ingest::class, function (Application $app) {
            $config = $app->make('config');

            return match ($driver = $config->get('pulse.ingest.driver')) {
                'storage' => $app->make(StorageIngest::class),
                'redis' => $app->make($this->usesFledgeRedis($app) ? FledgeRedisIngest::class : RedisIngest::class),
                null, 'null' => $app->make(NullIngest::class),
                default => throw new RuntimeException("Unknown ingest driver [{$driver}]."),
            };
        });
    }

    /**
     * Determine whether the ingest's redis connection is a Fledge connection.
     */
    protected function usesFledgeRedis(Application $app): bool
    {
        return $app->make('redis')
            ->connection($app->make('config')->get('pulse.ingest.redis.connection'))
            instanceof FledgeRedisConnection;
    }

    /**
     * Register the commands that replace upstream's blocking ones.
     *
     * Pulse's own provider boots first and registers its commands; the
     * console application replaces a command registered under the same name,
     * so these win.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkCommand::class,
                CheckCommand::class,
            ]);
        }
    }
}
