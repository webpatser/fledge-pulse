<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Laravel\Pulse\Facades\Pulse;
use Tests\Fiber\Livewire\Fixtures\RemembersConcurrentlyCard;

use function Fledge\Async\delay;

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('pulse.cache', null);

    // Pulse's own cache recorder would otherwise queue entries for the cache
    // traffic these tests generate, tripping the "pending entries" guard.
    Pulse::stopRecording();

    Cache::store('array')->flush();
});

it('returns the value, duration and run time for every key in input order', function () {
    $results = (new RemembersConcurrentlyCard)->many([
        'zebra' => fn () => 'z',
        'apple' => fn () => ['a' => 1],
        'mango' => fn () => 42,
    ]);

    expect(array_keys($results))->toBe(['zebra', 'apple', 'mango']);

    expect($results['zebra'][0])->toBe('z')
        ->and($results['apple'][0])->toBe(['a' => 1])
        ->and($results['mango'][0])->toBe(42);

    foreach ($results as [$value, $duration, $runAt]) {
        expect($duration)->toBeFloat()
            ->and($runAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    }
});

it('hands the period interval to every query', function () {
    $card = new RemembersConcurrentlyCard;
    $card->period = '24_hours';

    $results = $card->many([
        'one' => fn (CarbonInterval $interval) => $interval->totalHours,
        'two' => fn (CarbonInterval $interval) => $interval->totalHours,
    ]);

    expect($results['one'][0])->toBe(24.0)
        ->and($results['two'][0])->toBe(24.0);
});

it('skips the queries that are already cached', function () {
    $card = new RemembersConcurrentlyCard;
    $runs = [];

    $queries = function () use (&$runs) {
        return [
            'a' => function () use (&$runs) {
                $runs[] = 'a';

                return 'first';
            },
            'b' => function () use (&$runs) {
                $runs[] = 'b';

                return 'second';
            },
        ];
    };

    $first = $card->many($queries());
    $second = $card->many($queries());

    expect($runs)->toBe(['a', 'b'])
        ->and($second)->toBe($first);
});

it('runs only the cache misses when some keys are cached', function () {
    $card = new RemembersConcurrentlyCard;
    $runs = [];

    $card->many(['a' => function () use (&$runs) {
        $runs[] = 'a';

        return 'cached';
    }]);

    $results = $card->many([
        'a' => function () use (&$runs) {
            $runs[] = 'a again';

            return 'stale';
        },
        'b' => function () use (&$runs) {
            $runs[] = 'b';

            return 'fresh';
        },
        'c' => function () use (&$runs) {
            $runs[] = 'c';

            return 'fresh too';
        },
    ]);

    expect($runs)->toEqualCanonicalizing(['a', 'b', 'c'])
        ->and(array_keys($results))->toBe(['a', 'b', 'c'])
        ->and($results['a'][0])->toBe('cached')
        ->and($results['b'][0])->toBe('fresh')
        ->and($results['c'][0])->toBe('fresh too');
});

it('stores results under the keys RemembersQueries::remember() uses', function () {
    $card = new RemembersConcurrentlyCard;
    $card->period = '6_hours';

    $results = $card->many(['servers' => fn () => 'value', 'other' => fn () => 'value']);

    $cached = Cache::store('array')->get('laravel:pulse:'.RemembersConcurrentlyCard::class.':servers:6_hours');

    expect($cached)->toBe($results['servers']);
});

it('keeps separate cache entries per period', function () {
    $card = new RemembersConcurrentlyCard;

    $oneHour = $card->many(['a' => fn () => '1h', 'b' => fn () => '1h']);

    $card->period = '7_days';

    $sevenDays = $card->many(['a' => fn () => '7d', 'b' => fn () => '7d']);

    expect($oneHour['a'][0])->toBe('1h')
        ->and($sevenDays['a'][0])->toBe('7d');
});

it('runs the queries again once the ttl has expired', function () {
    $card = new RemembersConcurrentlyCard;
    $runs = 0;

    $queries = function () use (&$runs) {
        return [
            'a' => function () use (&$runs) {
                return ++$runs;
            },
            'b' => fn () => 'x',
        ];
    };

    $card->many($queries(), ttl: 5);
    $card->many($queries(), ttl: 5);

    expect($runs)->toBe(1);

    $this->travel(10)->seconds();

    $results = $card->many($queries(), ttl: 5);

    expect($runs)->toBe(2)
        ->and($results['a'][0])->toBe(2);
});

it('passes the cached result to a ttl closure, like remember()', function () {
    $card = new RemembersConcurrentlyCard;
    $runs = ['short' => 0, 'long' => 0];
    $seen = [];

    $queries = function () use (&$runs) {
        return [
            'short' => function () use (&$runs) {
                $runs['short']++;

                return 'short';
            },
            'long' => function () use (&$runs) {
                $runs['long']++;

                return 'long';
            },
        ];
    };

    $ttl = function (array $result) use (&$seen) {
        $seen[] = $result;

        return $result[0] === 'short' ? 1 : 60;
    };

    $first = $card->many($queries(), $ttl);

    expect($seen)->toEqualCanonicalizing([$first['short'], $first['long']]);

    $this->travel(5)->seconds();

    $card->many($queries(), $ttl);

    expect($runs)->toBe(['short' => 2, 'long' => 1]);
});

it('runs the cache misses concurrently', function () {
    $card = new RemembersConcurrentlyCard;

    $started = microtime(true);

    $results = $card->many([
        'a' => function () {
            delay(0.3);

            return 'a';
        },
        'b' => function () {
            delay(0.3);

            return 'b';
        },
        'c' => function () {
            delay(0.3);

            return 'c';
        },
    ]);

    $elapsed = microtime(true) - $started;

    expect(array_column($results, 0))->toBe(['a', 'b', 'c'])
        // Serial would take 0.9s; allow generous scheduling slack.
        ->and($elapsed)->toBeLessThan(0.7)
        ->and($elapsed)->toBeGreaterThanOrEqual(0.3);
});

it('returns an empty array for no queries', function () {
    expect((new RemembersConcurrentlyCard)->many([]))->toBe([]);
});

it('runs a single miss inline and still caches it', function () {
    $card = new RemembersConcurrentlyCard;

    $results = $card->many(['only' => fn () => 'v']);

    expect($results['only'][0])->toBe('v')
        ->and(Cache::store('array')->has('laravel:pulse:'.RemembersConcurrentlyCard::class.':only:1_hour'))->toBeTrue();
});
