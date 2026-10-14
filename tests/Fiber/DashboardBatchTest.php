<?php

use Fledge\Fiber\Livewire\Mechanisms\FiberHandleRequests;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

use function Fledge\Async\delay;

const DASHBOARD_BATCH_CONNECTION = 'pulse_batch_native';

// A dedicated database keeps this test away from the migrate:fresh that
// RefreshDatabase runs against the default test database.
const DASHBOARD_BATCH_DATABASE = 'pulse_fiber_batch_test';

/**
 * The lazy cards rendered in one batch, with text each must show from the
 * seeded data.
 */
const DASHBOARD_BATCH_CARDS = [
    'pulse.servers' => ['Web Batch'],
    'pulse.queues' => ['batch-queue'],
    'pulse.cache' => ['batch-cache-key'],
    'pulse.slow-queries' => ['batch_table', 'app/Batch.php:12'],
    'pulse.exceptions' => ['BatchException', 'app/Batch.php:34'],
    'pulse.slow-requests' => ['/batch-uri', 'BatchController@index'],
    'pulse.slow-jobs' => ['App\Jobs\BatchJob'],
    'pulse.slow-outgoing-requests' => ['batch.example.com'],
];

/**
 * Errors seen in production when the cards shared one PDO across fibers.
 */
const DASHBOARD_BATCH_FIBER_ERRORS = '/stream is in use|active transaction|unbuffered|gone away/i';

/**
 * Connect to the local MariaDB server and make sure the dedicated database
 * exists. Returns false when the server cannot be reached.
 */
function dashboardBatchMariaDbAvailable(): bool
{
    if (! extension_loaded('pdo_mysql')) {
        return false;
    }

    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);

        $pdo->exec('create database if not exists `'.DASHBOARD_BATCH_DATABASE.'`');
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
function dashboardBatchUseNativeConnection(): void
{
    Config::set('database.connections.'.DASHBOARD_BATCH_CONNECTION, [
        'driver' => 'fledge-mariadb-native',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => DASHBOARD_BATCH_DATABASE,
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
        'fiberio' => 'optional',
        // Fewer leases than cards, so fibers also wait on the pool.
        'pool_size' => 4,
    ]);

    DB::purge(DASHBOARD_BATCH_CONNECTION);

    Config::set('pulse.storage.driver', 'database');
    Config::set('pulse.storage.database.connection', DASHBOARD_BATCH_CONNECTION);

    $exitCode = Artisan::call('migrate:fresh', [
        '--database' => DASHBOARD_BATCH_CONNECTION,
        '--path' => realpath(__DIR__.'/../../database/migrations/2023_06_07_000001_create_pulse_tables.php'),
        '--realpath' => true,
    ]);

    expect($exitCode)->toBe(0, Artisan::output());
}

/**
 * Record data for every card in the batch and store it.
 */
function dashboardBatchSeed(): void
{
    Pulse::set('system', 'batch-1', json_encode([
        'name' => 'Web Batch',
        'memory_used' => 1234,
        'memory_total' => 2468,
        'cpu' => 42,
        'storage' => [
            ['directory' => '/', 'used' => 123, 'total' => 456],
        ],
    ]));
    Pulse::record('cpu', 'batch-1', 42)->avg()->onlyBuckets();
    Pulse::record('memory', 'batch-1', 1234)->avg()->onlyBuckets();

    Pulse::record('queued', 'redis:batch-queue')->count()->onlyBuckets();
    Pulse::record('processed', 'redis:batch-queue')->count()->onlyBuckets();

    Pulse::record('cache_hit', 'batch-cache-key')->count();
    Pulse::record('cache_hit', 'batch-cache-key')->count();
    Pulse::record('cache_miss', 'batch-cache-key')->count();

    Pulse::record('slow_query', json_encode(['select * from `batch_table`', 'app/Batch.php:12']), 1500)->max()->count();
    Pulse::record('exception', json_encode(['BatchException', 'app/Batch.php:34']), now()->timestamp)->max()->count();
    Pulse::record('slow_request', json_encode(['GET', '/batch-uri', 'BatchController@index', null]), 1500)->max()->count();
    Pulse::record('slow_job', 'App\Jobs\BatchJob', 1500)->max()->count();
    Pulse::record('slow_outgoing_request', json_encode(['GET', 'http://batch.example.com/api']), 1500)->max()->count();

    expect(Pulse::ingest())->toBe(13);
}

/**
 * Pull every lazy card out of the dashboard HTML: its snapshot (the raw JSON
 * string the browser posts back) and the encoded mount params of its
 * `$wire.__lazyLoad(...)` call, keyed by component name.
 *
 * @return array<string, array{snapshot: string, lazyLoad: string}>
 */
function dashboardBatchLazyComponents(string $html): array
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $snapshots);
    preg_match_all('/__lazyLoad\((?:&#039;|&apos;|\')([A-Za-z0-9+\/=]+)(?:&#039;|&apos;|\')\)/', $html, $lazyLoads);

    $snapshotsByName = [];

    foreach ($snapshots[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);

        $snapshotsByName[json_decode($snapshot, true)['memo']['name']] = $snapshot;
    }

    $components = [];

    foreach ($lazyLoads[1] as $encoded) {
        // The mount params travel as the snapshot of a container component
        // that remembers which component they belong to.
        $name = json_decode(base64_decode($encoded), true)['data']['forComponent'];

        $components[$name] = ['snapshot' => $snapshotsByName[$name], 'lazyLoad' => $encoded];
    }

    return $components;
}

beforeEach(function () {
    if (! dashboardBatchMariaDbAvailable()) {
        test()->markTestSkipped('MariaDB is not reachable at 127.0.0.1 as root.');
    }

    dashboardBatchUseNativeConnection();

    Config::set('pulse.ingest.driver', 'storage');
    Config::set('pulse.cache', 'array');

    Carbon::setTestNow('2000-01-01 13:00:00');
});

afterEach(function () {
    Carbon::setTestNow();

    DB::purge(DASHBOARD_BATCH_CONNECTION);
});

it('renders a lazy batch of cards in parallel fibers on a fledge-mariadb-native connection', function () {
    expect(app(HandleRequests::class))->toBeInstanceOf(FiberHandleRequests::class)
        ->and(config('fledge-livewire.concurrent', true))->toBeTrue()
        ->and(config('fledge-livewire.max_concurrent', 10))->toBeGreaterThanOrEqual(count(DASHBOARD_BATCH_CARDS));

    dashboardBatchSeed();

    $components = dashboardBatchLazyComponents($this->get('/pulse')->assertOk()->content());

    foreach (array_keys(DASHBOARD_BATCH_CARDS) as $name) {
        expect($components)->toHaveKey($name);
    }

    // Every storage query a card runs suspends its fiber while the card is
    // still inside Pulse::ignore(), the way async I/O does without fiberio.
    // The other cards run (and record) meanwhile.
    $inFlight = 0;
    $maxInFlight = 0;
    $queryFibers = [];

    DB::listen(function (QueryExecuted $event) use (&$inFlight, &$maxInFlight, &$queryFibers) {
        $fiber = Fiber::getCurrent();

        if ($event->connectionName !== DASHBOARD_BATCH_CONNECTION || $fiber === null) {
            return;
        }

        $queryFibers[spl_object_id($fiber)] = true;
        $maxInFlight = max($maxInFlight, ++$inFlight);

        try {
            delay(0.005);
        } finally {
            $inFlight--;
        }
    });

    $logged = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message;
    });

    // A recording from inside every card's fiber, made while other cards
    // are suspended inside Pulse::ignore().
    View::composer('pulse::livewire.*', function ($view) {
        Pulse::record('dashboard_batch_render', $view->name())->count();
    });

    $response = $this
        ->withoutExceptionHandling()
        ->postJson(livewireUpdateEndpoint(), [
            '_token' => csrf_token(),
            'components' => collect(DASHBOARD_BATCH_CARDS)->keys()->map(fn (string $name) => [
                'snapshot' => $components[$name]['snapshot'],
                'updates' => [],
                'calls' => [
                    ['path' => '', 'method' => '__lazyLoad', 'params' => [$components[$name]['lazyLoad']]],
                ],
            ])->all(),
        ], ['X-Livewire' => 'true'])
        ->assertOk();

    // The batch really ran concurrently.
    expect($maxInFlight)->toBeGreaterThanOrEqual(2)
        ->and(count($queryFibers))->toBeGreaterThanOrEqual(2);

    // Every card rendered its own seeded data, in request order.
    $rendered = collect($response->json('components'))
        ->mapWithKeys(fn (array $component) => [
            json_decode($component['snapshot'], true)['memo']['name'] => $component['effects']['html'] ?? '',
        ]);

    expect($rendered->keys()->all())->toBe(array_keys(DASHBOARD_BATCH_CARDS));

    foreach (DASHBOARD_BATCH_CARDS as $name => $needles) {
        foreach ($needles as $needle) {
            expect($rendered[$name])->toContain($needle);
        }

        expect($rendered[$name])->not->toMatch(DASHBOARD_BATCH_FIBER_ERRORS);
    }

    expect(collect($logged)->filter(fn (string $message) => preg_match(DASHBOARD_BATCH_FIBER_ERRORS, $message)))->toBeEmpty();

    // Nothing recorded during the batch was dropped: the request lifecycle
    // ingests at terminate; ingest whatever is left, then read it back.
    if (Pulse::wantsIngesting()) {
        Pulse::ingest();
    }

    $markers = DB::connection(DASHBOARD_BATCH_CONNECTION)
        ->table('pulse_entries')
        ->where('type', 'dashboard_batch_render')
        ->pluck('key')
        ->sort()
        ->values()
        ->all();

    expect($markers)->toBe(collect(DASHBOARD_BATCH_CARDS)->keys()
        ->map(fn (string $name) => 'pulse::livewire.'.Str::after($name, 'pulse.'))
        ->sort()
        ->values()
        ->all());

    // Recording is still on for the main context after the batch.
    Pulse::record('dashboard_batch_after', 'main')->count();

    expect(Pulse::wantsIngesting())->toBeTrue();

    Pulse::ingest();
});
