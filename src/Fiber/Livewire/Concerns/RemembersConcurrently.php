<?php

namespace Laravel\Pulse\Fiber\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use DateTimeInterface;
use Fledge\Fiber\Database\FiberDB;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\App;
use Laravel\Pulse\Support\CacheStoreResolver;

/**
 * Remember several card queries at once, running the cache misses concurrently.
 *
 * Use it in a card that already uses `HasPeriod` (for `$period` and
 * `periodAsInterval()`), as an alternative to calling `remember()` once per
 * query:
 *
 *     [
 *         'all' => [$all, $allTime, $allRunAt],
 *         'keys' => [$keys, $keyTime, $keyRunAt],
 *     ] = $this->rememberMany([
 *         'all' => fn (CarbonInterval $interval) => Pulse::aggregate(...),
 *         'keys' => fn (CarbonInterval $interval) => Pulse::aggregate(...),
 *     ]);
 *
 * Each query runs in its own fiber via `FiberDB::concurrent()`. On a driver
 * with a connection per fiber (the native pool) the queries really overlap;
 * on a single shared PDO connection they run one after the other.
 */
trait RemembersConcurrently
{
    /**
     * Remember the queries for the current period, running the cache misses concurrently.
     *
     * The cache keys and TTL are the ones `RemembersQueries::remember()` uses,
     * so both methods share cached entries. Cache hits never run their query.
     * Each query receives the period as a `CarbonInterval`.
     *
     * @param  array<array-key, Closure(CarbonInterval): mixed>  $queries
     * @return array<array-key, array{0: mixed, 1: float, 2: string}> keyed like `$queries`, in the same order
     */
    protected function rememberMany(array $queries, DateTimeInterface|DateInterval|Closure|int|null $ttl = 5): array
    {
        $store = App::make(CacheStoreResolver::class)->store();

        $results = [];
        $misses = [];

        foreach ($queries as $key => $query) {
            $results[$key] = null;

            $cached = $store->get($this->concurrentCacheKey((string) $key));

            if ($cached !== null) {
                $results[$key] = $cached;

                continue;
            }

            $misses[$key] = function () use ($store, $key, $query, $ttl): array {
                $start = CarbonImmutable::now('UTC')->toDateTimeString();

                [$value, $duration] = Benchmark::value(fn () => $query($this->periodAsInterval()));

                $result = [$value, $duration, $start];

                // Like Repository::remember(): a TTL closure receives the cached result.
                $store->put($this->concurrentCacheKey((string) $key), $result, value($ttl, $result));

                return $result;
            };
        }

        if (count($misses) === 1) {
            $results[array_key_first($misses)] = (reset($misses))();
        } elseif ($misses !== []) {
            $resolved = FiberDB::concurrent(...array_values($misses));

            foreach (array_keys($misses) as $position => $key) {
                $results[$key] = $resolved[$position];
            }
        }

        return $results;
    }

    /**
     * The cache key `RemembersQueries::remember()` uses for the given query key.
     */
    private function concurrentCacheKey(string $key): string
    {
        return 'laravel:pulse:'.static::class.':'.$key.':'.$this->period;
    }
}
