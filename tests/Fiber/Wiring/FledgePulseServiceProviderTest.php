<?php

use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Commands\CheckCommand as UpstreamCheckCommand;
use Laravel\Pulse\Commands\WorkCommand as UpstreamWorkCommand;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Facades\Pulse as PulseFacade;
use Laravel\Pulse\Fiber\Commands\CheckCommand;
use Laravel\Pulse\Fiber\Commands\WorkCommand;
use Laravel\Pulse\Fiber\FiberPulse;
use Laravel\Pulse\Fiber\Ingests\FledgeRedisIngest;
use Laravel\Pulse\Fiber\Storage\FiberDatabaseStorage;
use Laravel\Pulse\Fiber\Storage\StorageManager;
use Laravel\Pulse\Ingests\NullIngest;
use Laravel\Pulse\Ingests\RedisIngest;
use Laravel\Pulse\Ingests\StorageIngest;
use Laravel\Pulse\Pulse;

/**
 * Point the application's redis manager at a client with a single `default`
 * connection. Connections are lazy, so nothing needs to be listening.
 */
function useRedisClient(string $client): void
{
    if (! app()->bound('redis.connector.fledge')) {
        app()->bind('redis.connector.fledge', fn () => new FledgeRedisConnector);
    }

    Config::set('database.redis.client', $client);
    Config::set('database.redis.options', []);
    Config::set('database.redis.default', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0]);
    Config::set('pulse.ingest.driver', 'redis');
    Config::set('pulse.ingest.redis.connection', 'default');

    app()->forgetInstance('redis');
}

it('resolves Pulse as a FiberPulse singleton', function () {
    expect(app(Pulse::class))->toBeInstanceOf(FiberPulse::class)
        ->and(app(Pulse::class))->toBe(app(Pulse::class));
});

it('gives the facade the FiberPulse instance', function () {
    expect(PulseFacade::getFacadeRoot())->toBeInstanceOf(FiberPulse::class)
        ->and(PulseFacade::getFacadeRoot())->toBe(app(Pulse::class));
});

it('resolves storage through the manager default driver', function () {
    Config::set('pulse.storage.driver', null);

    expect(app(StorageManager::class))->toBe(app(StorageManager::class))
        ->and(app(StorageManager::class)->getDefaultDriver())->toBe('database')
        ->and(app(Storage::class))->toBeInstanceOf(FiberDatabaseStorage::class);
});

it('resolves storage through the configured driver', function () {
    $custom = new class extends FiberDatabaseStorage
    {
        public function __construct() {}
    };

    app(StorageManager::class)->extend('custom', fn () => $custom);
    Config::set('pulse.storage.driver', 'custom');

    expect(app(Storage::class))->toBe($custom);
});

it('binds the Fledge redis ingest on a fledge redis connection', function () {
    useRedisClient('fledge');

    expect(app('redis')->connection('default'))->toBeInstanceOf(FledgeRedisConnection::class)
        ->and(app(Ingest::class))->toBeInstanceOf(FledgeRedisIngest::class);
});

it('keeps the upstream redis ingest on other redis clients', function () {
    useRedisClient('predis');

    $ingest = app(Ingest::class);

    expect($ingest)->toBeInstanceOf(RedisIngest::class)
        ->and($ingest)->not->toBeInstanceOf(FledgeRedisIngest::class);
});

it('keeps the upstream ingest selection for the other drivers', function () {
    Config::set('pulse.ingest.driver', 'storage');
    expect(app(Ingest::class))->toBeInstanceOf(StorageIngest::class);

    Config::set('pulse.ingest.driver', null);
    expect(app(Ingest::class))->toBeInstanceOf(NullIngest::class);

    Config::set('pulse.ingest.driver', 'nope');
    app(Ingest::class);
})->throws(RuntimeException::class, 'Unknown ingest driver [nope].');

it('does not touch redis while registering', function () {
    useRedisClient('fledge');

    // Booting the provider must not have resolved a redis connection.
    expect(app()->resolved('redis'))->toBeFalse();
});

it('replaces the upstream pulse commands with the Fledge subclasses', function () {
    $commands = Artisan::all();

    expect($commands['pulse:work'])->toBeInstanceOf(WorkCommand::class)
        ->and($commands['pulse:work'])->toBeInstanceOf(UpstreamWorkCommand::class)
        ->and($commands['pulse:check'])->toBeInstanceOf(CheckCommand::class)
        ->and($commands['pulse:check'])->toBeInstanceOf(UpstreamCheckCommand::class);
});

it('runs the Fledge pulse:work command', function () {
    PulseFacade::stopRecording();

    $this->artisan('pulse:work', ['--stop-when-empty' => true])->assertSuccessful();
});

it('runs the Fledge pulse:check command', function () {
    PulseFacade::stopRecording();

    $this->artisan('pulse:check', ['--once' => true])->assertSuccessful();
});
