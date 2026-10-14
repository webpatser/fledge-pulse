<?php

use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Fiber\Ingests\FledgeRedisIngest;
use Laravel\Pulse\Fiber\Support\FledgeRedisAdapter;
use Laravel\Pulse\Ingests\RedisIngest;
use Laravel\Pulse\Storage\DatabaseStorage;
use Laravel\Pulse\Support\RedisServerException;
use Laravel\Pulse\Value;

const FLEDGE_REDIS_HOST = '127.0.0.1';
const FLEDGE_REDIS_PORT = 6379;

beforeEach(function () {
    $socket = @fsockopen(FLEDGE_REDIS_HOST, FLEDGE_REDIS_PORT, $errno, $errstr, 0.5);

    if ($socket === false) {
        $this->markTestSkipped('Valkey/Redis is not reachable on '.FLEDGE_REDIS_HOST.':'.FLEDGE_REDIS_PORT.'.');
    }

    fclose($socket);

    if (! $this->app->bound('redis.connector.fledge')) {
        $this->app->bind('redis.connector.fledge', fn () => new FledgeRedisConnector);
    }

    Pulse::stopRecording();

    // A unique prefix keeps the shared local Valkey untouched (no FLUSHALL).
    $this->prefix = 'fledge-pulse-test:'.Str::lower(Str::random(8)).':';
    $this->streamKey = $this->prefix.'laravel:pulse:ingest';

    Config::set('database.redis.options.prefix', $this->prefix);
    Config::set('pulse.ingest.redis.connection', 'default');

    $this->redis = new RedisManager($this->app, 'fledge', [
        'options' => ['prefix' => $this->prefix],
        'default' => [
            'host' => FLEDGE_REDIS_HOST,
            'port' => FLEDGE_REDIS_PORT,
            'database' => 0,
        ],
    ]);

    $this->connection = $this->redis->connection('default');
    $this->ingest = new FledgeRedisIngest($this->redis, App::make('config'));
    $this->xlen = fn (): int => (int) $this->connection->executeRaw(['XLEN', $this->streamKey]);
});

afterEach(function () {
    if (isset($this->connection)) {
        $this->connection->executeRaw(['DEL', $this->streamKey, $this->prefix.'stream-name']);
        $this->connection->disconnect();
    }
});

it('resolves a fledge connection, which the upstream ingest cannot use (hazard 4)', function () {
    expect($this->connection)->toBeInstanceOf(FledgeRedisConnection::class);

    $upstream = new RedisIngest($this->redis, App::make('config'));

    expect(fn () => $upstream->ingest(collect([new Entry(now()->timestamp, 'foo', 'bar', 1)])))
        ->toThrow(TypeError::class);
    expect(fn () => $upstream->digest(App::make(DatabaseStorage::class)))
        ->toThrow(TypeError::class);
});

it('ingests into the prefixed stream key and digests into database storage', function () {
    $now = now()->timestamp;

    $this->ingest->ingest(collect([
        (new Entry($now, 'cache_hit', 'users:1', 1))->count(),
        new Entry($now, 'slow_query', 'select 1', 1200),
        new Entry($now, 'slow_query', 'select 2', 1500),
        new Value($now, 'system', 'web-1', '{"cpu":12}'),
        new Value($now, 'system', 'web-2', '{"cpu":34}'),
    ]));

    // The global prefix is applied once, exactly like upstream's raw commands.
    expect(($this->xlen)())->toBe(5)
        ->and((int) $this->connection->executeRaw(['EXISTS', $this->prefix.$this->streamKey]))->toBe(0);

    $total = $this->ingest->digest(App::make(DatabaseStorage::class));

    expect($total)->toBe(5)
        ->and(($this->xlen)())->toBe(0)
        ->and(DB::table('pulse_entries')->orderBy('id')->pluck('key')->all())
        ->toBe(['users:1', 'select 1', 'select 2'])
        ->and(DB::table('pulse_entries')->where('type', 'slow_query')->sum('value'))->toEqual(2700)
        ->and(DB::table('pulse_values')->orderBy('key')->pluck('value', 'key')->all())
        ->toBe(['web-1' => '{"cpu":12}', 'web-2' => '{"cpu":34}'])
        ->and(DB::table('pulse_aggregates')->where('type', 'cache_hit')->where('aggregate', 'count')->count())
        ->toBe(4);
});

it('digests in chunks and deletes every digested id', function () {
    Config::set('pulse.ingest.redis.chunk', 2);
    $now = now()->timestamp;

    $this->ingest->ingest(collect(range(1, 5))->map(
        fn (int $i) => new Entry($now, 'foo', "key-{$i}", $i)
    ));

    expect(($this->xlen)())->toBe(5);

    $total = $this->ingest->digest(App::make(DatabaseStorage::class));

    expect($total)->toBe(5)
        ->and(($this->xlen)())->toBe(0)
        ->and(DB::table('pulse_entries')->orderBy('id')->pluck('value')->all())
        ->toEqual([1, 2, 3, 4, 5]);

    expect($this->ingest->digest(App::make(DatabaseStorage::class)))->toBe(0);
});

it('trims the stream by MAXLEN when keep is an integer', function () {
    Config::set('pulse.ingest.trim.keep', 10);
    $now = now()->timestamp;

    $this->ingest->ingest(collect(range(1, 300))->map(
        fn (int $i) => new Entry($now, 'foo', "key-{$i}", $i)
    ));

    expect(($this->xlen)())->toBe(300);

    $this->ingest->trim();

    // MAXLEN ~ trims whole stream nodes, so the result is approximate.
    expect(($this->xlen)())->toBeLessThan(300)->toBeGreaterThanOrEqual(10);
});

it('trims the stream by MINID when keep is a duration', function () {
    Config::set('pulse.ingest.trim.keep', '7 days');

    for ($i = 1; $i <= 250; $i++) {
        $this->connection->executeRaw(['XADD', $this->streamKey, "{$i}-0", 'data', 'old']);
    }

    $this->ingest->ingest(collect([
        new Entry(now()->timestamp, 'foo', 'fresh-1', 1),
        new Entry(now()->timestamp, 'foo', 'fresh-2', 2),
    ]));

    $this->ingest->trim();

    $remaining = (new FledgeRedisAdapter($this->connection, App::make('config')))
        ->xrange('laravel:pulse:ingest', '-', '+');

    expect(count($remaining))->toBeLessThan(252)
        ->and(collect($remaining)->filter(fn (array $fields) => $fields['data'] !== 'old'))->toHaveCount(2);
});

it('returns upstream shaped results from the adapter', function () {
    $adapter = new FledgeRedisAdapter($this->connection, App::make('config'));

    $first = $adapter->xadd('stream-name', ['foo' => 1, 'bar' => 2]);
    $adapter->xadd('stream-name', ['foo' => 3, 'bar' => 4]);
    usleep(5000);
    $last = $adapter->xadd('stream-name', ['foo' => 5, 'bar' => 6]);

    expect($first)->toBeString()->toMatch('/^\d+-\d+$/');

    $range = $adapter->xrange('stream-name', '-', '+', 2);

    expect(array_values($range))->toBe([
        ['foo' => '1', 'bar' => '2'],
        ['foo' => '3', 'bar' => '4'],
    ])->and(array_key_first($range))->toBe($first);

    expect($adapter->xtrim('stream-name', 'MINID', '=', Str::before($last, '-')))->toBe(2)
        ->and($adapter->xdel('stream-name', collect([$last])))->toBe(1);
});

it('returns the ids of entries added in a pipeline in order', function () {
    $adapter = new FledgeRedisAdapter($this->connection, App::make('config'));

    $ids = $adapter->pipeline(function (FledgeRedisAdapter $pipeline) {
        foreach (range(1, 20) as $i) {
            expect($pipeline->xadd('stream-name', ['n' => $i]))->toBe('');
        }
    });

    expect($ids)->toHaveCount(20)
        ->and(array_keys($adapter->xrange('stream-name', '-', '+')))->toBe($ids)
        ->and(array_column(array_values($adapter->xrange('stream-name', '-', '+')), 'n'))
        ->toBe(array_map(strval(...), range(1, 20)));
});

it('wraps server errors like upstream', function () {
    $adapter = new FledgeRedisAdapter($this->connection, App::make('config'));

    expect(fn () => $adapter->xtrim('stream-name', 'FOO', 'a', 'xyz'))->toThrow(
        RedisServerException::class,
        'The Redis version does not support the command or some of its arguments [XTRIM '.$this->prefix.'stream-name FOO a xyz]. Redis error: [ERR syntax error].',
    );
});
