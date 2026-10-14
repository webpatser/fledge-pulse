<?php

namespace Laravel\Pulse\Tests\Contract;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Value;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * The read contract every Pulse storage driver must honour.
 *
 * A fixed set of entries and values is stored at fixed timestamps around a
 * frozen "now", then every read method of the Storage contract is called and
 * compared with the output upstream `DatabaseStorage` produces for the same
 * input. The expected values below were captured from DatabaseStorage; the
 * suite in this directory runs them against DatabaseStorage itself (sqlite and
 * mariadb) to keep them honest.
 *
 * What is compared strictly:
 * - Collection classes (always `Illuminate\Support\Collection`), keys, key
 *   types (int vs string) and key order. values() and the array form of
 *   aggregateTotal() have no defined order upstream, so those two are
 *   compared with their keys sorted.
 * - Rows are `stdClass` with exactly the expected properties, in order.
 * - Strings (keys, value payloads, graph bucket keys `Y-m-d H:i:s` in UTC)
 *   and nulls are compared with `===`.
 *
 * What is compared by value: numbers. DatabaseStorage returns driver-dependent
 * scalar types (MySQL/MariaDB return numeric strings for decimal columns and
 * for sum()/avg(), SQLite returns int/float), so a number must be an int, a
 * float or a numeric string, and equal the expected value at 2 decimals.
 *
 * Upstream semantics a driver has to reproduce (all exercised below):
 * - A window is split into a "tail" (raw entries between the window start and
 *   the oldest full bucket) and the pre-aggregated buckets. onlyBuckets()
 *   entries never appear in the tail, only in buckets.
 * - aggregate()/aggregateTypes() take `key` from the raw entries, so a key
 *   that only has onlyBuckets() entries comes back with a null key.
 * - avg is the plain mean of (tail average, mean of the bucket averages),
 *   not a weighted mean of all values.
 * - count counts entries, including entries without a value.
 * - aggregateTypes(): a type without data for a key is 0 for `count` when
 *   the key has tail entries, and null otherwise.
 * - aggregateTotal() with an array omits types without data; with a string
 *   it returns 0.0 when there is no data.
 *
 * Reusing it from another package (for example the Elasticsearch driver):
 * 1. Install webpatser/fledge-pulse from source so `tests/` is present (the
 *    dist archive export-ignores it): in composer.json `config`,
 *    `"preferred-install": {"webpatser/fledge-pulse": "source", "*": "dist"}`
 *    (a composer path repository works too).
 * 2. Map the namespace in autoload-dev:
 *    `"Laravel\\Pulse\\Tests\\Contract\\": "vendor/webpatser/fledge-pulse/tests/Contract/"`.
 * 3. Run with the application timezone set to UTC, and make stored data
 *    readable before the reads run (for Elasticsearch: refresh the indices in
 *    the `$afterSeed` callback).
 * 4. Either run every case at once with `StorageContract::assertContract($storage, $afterSeed)`,
 *    or one test per case: `->with(StorageContract::dataset())` and
 *    `StorageContract::assertCase($storage, $case, $afterSeed)`.
 *
 * Every type used here starts with `contract_`, and the contract purges only
 * those types, so it can run against a storage that holds other data.
 */
final class StorageContract
{
    /**
     * The frozen "now" (UTC) every timestamp below is relative to.
     */
    public const string NOW = '2000-01-01 13:00:00';

    public const int NOW_TIMESTAMP = 946_731_600;

    /**
     * 12:00:00, one hour ago: outside the 1 hour window, inside the 6 hour window.
     */
    public const int OUTSIDE = self::NOW_TIMESTAMP - 3600;

    /**
     * 12:00:01 and 12:00:30: the tail of the 1 hour window (raw entries).
     */
    public const int TAIL = self::NOW_TIMESTAMP - 3599;

    public const int TAIL_LATE = self::NOW_TIMESTAMP - 3570;

    /**
     * 12:01:00: the oldest bucket of the 1 hour window.
     */
    public const int OLDEST_BUCKET = self::NOW_TIMESTAMP - 3540;

    /**
     * 12:50:00: a bucket in the middle of the 1 hour window.
     */
    public const int RECENT = self::NOW_TIMESTAMP - 600;

    /**
     * Every type the dataset writes or queries.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            'contract_timing',
            'contract_hit',
            'contract_miss',
            'contract_cpu',
            'contract_mem',
            'contract_bucket_only',
            'contract_system',
            'contract_other',
            'contract_unknown',
        ];
    }

    /**
     * Freeze time at NOW. Pre-aggregation and every read depend on it.
     */
    public static function freezeTime(): void
    {
        Carbon::setTestNow(CarbonImmutable::createFromTimestamp(self::NOW_TIMESTAMP, 'UTC'));
    }

    /**
     * The items to store, as consecutive store() calls. The second batch
     * lands in buckets the first one created, so a driver's merge path is
     * exercised too.
     *
     * @return list<Collection<int, Entry|Value>>
     */
    public static function batches(): array
    {
        $timing = fn (int $timestamp, string $key, int $value) => (new Entry($timestamp, 'contract_timing', $key, $value))
            ->count()->min()->max()->sum()->avg();

        $count = fn (int $timestamp, string $type, string $key) => (new Entry($timestamp, $type, $key))->count();

        $bucketAvg = fn (int $timestamp, string $type, string $key, int $value) => (new Entry($timestamp, $type, $key, $value))
            ->avg()->onlyBuckets();

        return [
            new Collection([
                $timing(self::OUTSIDE, 'alpha', 9999),
                $timing(self::TAIL, 'alpha', 100),
                $timing(self::TAIL_LATE, 'alpha', 300),
                $timing(self::OLDEST_BUCKET, 'alpha', 200),
                $timing(self::RECENT, 'alpha', 400),
                $timing(self::NOW_TIMESTAMP, 'alpha', 500),
                $timing(self::TAIL, 'beta', 50),
                $timing(self::NOW_TIMESTAMP, 'beta', 150),
                $timing(self::RECENT, 'gamma', 1000),
                $timing(self::NOW_TIMESTAMP, '42', 5),
                $timing(self::NOW_TIMESTAMP, '42', 7),

                $count(self::OUTSIDE, 'contract_hit', 'foo'),
                $count(self::TAIL, 'contract_hit', 'foo'),
                $count(self::TAIL, 'contract_hit', 'foo'),
                // Bucket-only, in the tail: invisible to every 1 hour read.
                $count(self::TAIL, 'contract_hit', 'foo')->onlyBuckets(),
                $count(self::TAIL, 'contract_hit', 'bar'),
                $count(self::RECENT, 'contract_hit', 'bar'),
                $count(self::TAIL, 'contract_miss', 'foo'),
                $count(self::NOW_TIMESTAMP, 'contract_miss', 'baz'),
                $count(self::NOW_TIMESTAMP, 'contract_miss', 'baz'),

                $bucketAvg(self::OLDEST_BUCKET, 'contract_cpu', 'web-1', 10),
                $bucketAvg(self::OLDEST_BUCKET, 'contract_cpu', 'web-1', 30),
                $bucketAvg(self::NOW_TIMESTAMP, 'contract_cpu', 'web-1', 40),
                $bucketAvg(self::RECENT, 'contract_cpu', 'web-2', 70),
                $bucketAvg(self::NOW_TIMESTAMP, 'contract_mem', 'web-1', 1000),

                $count(self::NOW_TIMESTAMP, 'contract_bucket_only', 'qux')->onlyBuckets(),

                // Within one batch the last value per key wins.
                new Value(self::TAIL, 'contract_system', 'web-2', '{"name":"Web 2 (stale)"}'),
                new Value(self::OLDEST_BUCKET, 'contract_system', 'web-2', '{"name":"Web 2"}'),
                new Value(self::RECENT, 'contract_system', 'web-1', '{"name":"Web 1 (stale)"}'),
                new Value(self::NOW_TIMESTAMP, 'contract_other', 'web-1', '{"other":true}'),
            ]),
            new Collection([
                $timing(self::NOW_TIMESTAMP, 'alpha', 700),
                $timing(self::NOW_TIMESTAMP, '42', 9),
                $count(self::NOW_TIMESTAMP, 'contract_hit', 'foo'),
                $count(self::NOW_TIMESTAMP, 'contract_hit', 'foo'),
                $count(self::NOW_TIMESTAMP, 'contract_hit', 'foo'),
                $count(self::NOW_TIMESTAMP, 'contract_miss', 'foo'),
                $bucketAvg(self::NOW_TIMESTAMP, 'contract_cpu', 'web-1', 60),
                new Value(self::NOW_TIMESTAMP, 'contract_system', 'web-1', '{"name":"Web 1"}'),
            ]),
        ];
    }

    /**
     * Every case: a read against the seeded storage and the output upstream
     * DatabaseStorage returns for it.
     *
     * @return array<string, array{query: Closure(Storage): mixed, expected: mixed, unordered: bool}>
     */
    public static function cases(): array
    {
        $hour = fn () => CarbonInterval::hour();
        $sixHours = fn () => CarbonInterval::hours(6);

        $row = fn (array $properties) => (object) $properties;

        // 1 hour graphs: 60 one-minute buckets, 12:01:00 up to 13:00:00.
        $minutes = fn (array $points = []) => self::series(self::NOW_TIMESTAMP - 59 * 60, 60, $points);

        // 6 hour graphs: 60 six-minute buckets, 07:06:00 up to 13:00:00.
        $sixMinutes = fn (array $points = []) => self::series(self::NOW_TIMESTAMP - 59 * 360, 360, $points);

        return [
            // values()

            'values returns the latest value per key' => self::case(
                fn (Storage $storage) => $storage->values('contract_system'),
                new Collection([
                    'web-1' => $row(['timestamp' => self::NOW_TIMESTAMP, 'key' => 'web-1', 'value' => '{"name":"Web 1"}']),
                    'web-2' => $row(['timestamp' => self::OLDEST_BUCKET, 'key' => 'web-2', 'value' => '{"name":"Web 2"}']),
                ]),
                unordered: true,
            ),

            'values filters by keys' => self::case(
                fn (Storage $storage) => $storage->values('contract_system', ['web-2']),
                new Collection([
                    'web-2' => $row(['timestamp' => self::OLDEST_BUCKET, 'key' => 'web-2', 'value' => '{"name":"Web 2"}']),
                ]),
            ),

            'values ignores unknown keys' => self::case(
                fn (Storage $storage) => $storage->values('contract_system', ['missing']),
                new Collection,
            ),

            'values is empty for an unknown type' => self::case(
                fn (Storage $storage) => $storage->values('contract_unknown'),
                new Collection,
            ),

            // graph()

            'graph plots bucket-only averages per minute' => self::case(
                fn (Storage $storage) => $storage->graph(['contract_cpu'], 'avg', $hour()),
                new Collection([
                    'web-1' => new Collection([
                        'contract_cpu' => $minutes(['2000-01-01 12:01:00' => 20, '2000-01-01 13:00:00' => 50]),
                    ]),
                    'web-2' => new Collection([
                        'contract_cpu' => $minutes(['2000-01-01 12:50:00' => 70]),
                    ]),
                ]),
            ),

            'graph pads every requested type for every key' => self::case(
                fn (Storage $storage) => $storage->graph(['contract_cpu', 'contract_mem'], 'avg', $hour()),
                new Collection([
                    'web-1' => new Collection([
                        'contract_cpu' => $minutes(['2000-01-01 12:01:00' => 20, '2000-01-01 13:00:00' => 50]),
                        'contract_mem' => $minutes(['2000-01-01 13:00:00' => 1000]),
                    ]),
                    'web-2' => new Collection([
                        'contract_cpu' => $minutes(['2000-01-01 12:50:00' => 70]),
                        'contract_mem' => $minutes(),
                    ]),
                ]),
            ),

            'graph plots counts with keys sorted' => self::case(
                fn (Storage $storage) => $storage->graph(['contract_hit'], 'count', $hour()),
                new Collection([
                    'bar' => new Collection([
                        'contract_hit' => $minutes(['2000-01-01 12:50:00' => 1]),
                    ]),
                    'foo' => new Collection([
                        'contract_hit' => $minutes(['2000-01-01 13:00:00' => 3]),
                    ]),
                ]),
            ),

            'graph includes keys that only have bucket-only entries' => self::case(
                fn (Storage $storage) => $storage->graph(['contract_bucket_only'], 'count', $hour()),
                new Collection([
                    'qux' => new Collection([
                        'contract_bucket_only' => $minutes(['2000-01-01 13:00:00' => 1]),
                    ]),
                ]),
            ),

            'graph plots six hours in six-minute buckets' => self::case(
                fn (Storage $storage) => $storage->graph(['contract_cpu'], 'avg', $sixHours()),
                new Collection([
                    'web-1' => new Collection([
                        'contract_cpu' => $sixMinutes(['2000-01-01 12:00:00' => 20, '2000-01-01 13:00:00' => 50]),
                    ]),
                    'web-2' => new Collection([
                        'contract_cpu' => $sixMinutes(['2000-01-01 12:48:00' => 70]),
                    ]),
                ]),
            ),

            'graph is empty for an unknown type' => self::case(
                fn (Storage $storage) => $storage->graph(['contract_unknown'], 'count', $hour()),
                new Collection,
            ),

            // aggregate()

            'aggregate computes every aggregation ordered by the first' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_timing', ['count', 'min', 'max', 'sum', 'avg'], $hour()),
                new Collection([
                    $row(['key' => 'alpha', 'count' => 6, 'min' => 100, 'max' => 700, 'sum' => 2200, 'avg' => 300]),
                    $row(['key' => '42', 'count' => 3, 'min' => 5, 'max' => 9, 'sum' => 21, 'avg' => 7]),
                    $row(['key' => 'beta', 'count' => 2, 'min' => 50, 'max' => 150, 'sum' => 200, 'avg' => 100]),
                    $row(['key' => 'gamma', 'count' => 1, 'min' => 1000, 'max' => 1000, 'sum' => 1000, 'avg' => 1000]),
                ]),
            ),

            'aggregate accepts a single aggregation' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_timing', 'max', $hour()),
                new Collection([
                    $row(['key' => 'gamma', 'max' => 1000]),
                    $row(['key' => 'alpha', 'max' => 700]),
                    $row(['key' => 'beta', 'max' => 150]),
                    $row(['key' => '42', 'max' => 9]),
                ]),
            ),

            'aggregate orders ascending by another aggregation and limits' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_timing', ['sum', 'min'], $hour(), 'min', 'asc', 2),
                new Collection([
                    $row(['key' => '42', 'sum' => 21, 'min' => 5]),
                    $row(['key' => 'beta', 'sum' => 200, 'min' => 50]),
                ]),
            ),

            'aggregate averages the tail average and the bucket averages' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_timing', 'avg', $hour()),
                new Collection([
                    $row(['key' => 'gamma', 'avg' => 1000]),
                    $row(['key' => 'alpha', 'avg' => 300]),
                    $row(['key' => 'beta', 'avg' => 100]),
                    $row(['key' => '42', 'avg' => 7]),
                ]),
            ),

            'aggregate limits the result' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_timing', 'count', $hour(), null, 'desc', 1),
                new Collection([
                    $row(['key' => 'alpha', 'count' => 6]),
                ]),
            ),

            'aggregate reads six hours from six-minute buckets' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_timing', ['count', 'max'], $sixHours()),
                new Collection([
                    $row(['key' => 'alpha', 'count' => 7, 'max' => 9999]),
                    $row(['key' => '42', 'count' => 3, 'max' => 9]),
                    $row(['key' => 'beta', 'count' => 2, 'max' => 150]),
                    $row(['key' => 'gamma', 'count' => 1, 'max' => 1000]),
                ]),
            ),

            'aggregate is empty for an unknown type' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_unknown', 'count', $hour()),
                new Collection,
            ),

            'aggregate returns a null key for a key with only bucket-only entries' => self::case(
                fn (Storage $storage) => $storage->aggregate('contract_bucket_only', 'count', $hour()),
                new Collection([
                    $row(['key' => null, 'count' => 1]),
                ]),
            ),

            // aggregateTypes()

            'aggregateTypes counts per type with zero or null for absent types' => self::case(
                fn (Storage $storage) => $storage->aggregateTypes(['contract_hit', 'contract_miss'], 'count', $hour()),
                new Collection([
                    $row(['key' => 'foo', 'contract_hit' => 5, 'contract_miss' => 2]),
                    $row(['key' => 'bar', 'contract_hit' => 2, 'contract_miss' => 0]),
                    $row(['key' => 'baz', 'contract_hit' => null, 'contract_miss' => 2]),
                ]),
            ),

            'aggregateTypes orders by another type and limits' => self::case(
                fn (Storage $storage) => $storage->aggregateTypes(['contract_hit', 'contract_miss'], 'count', $hour(), 'contract_miss', 'asc', 1),
                new Collection([
                    $row(['key' => 'bar', 'contract_hit' => 2, 'contract_miss' => 0]),
                ]),
            ),

            'aggregateTypes accepts a single type' => self::case(
                fn (Storage $storage) => $storage->aggregateTypes('contract_timing', 'max', $hour()),
                new Collection([
                    $row(['key' => 'gamma', 'contract_timing' => 1000]),
                    $row(['key' => 'alpha', 'contract_timing' => 700]),
                    $row(['key' => 'beta', 'contract_timing' => 150]),
                    $row(['key' => '42', 'contract_timing' => 9]),
                ]),
            ),

            // aggregateTotal()

            'aggregateTotal keys totals by type and omits types without data' => self::case(
                fn (Storage $storage) => $storage->aggregateTotal(['contract_hit', 'contract_miss', 'contract_unknown'], 'count', $hour()),
                new Collection(['contract_hit' => 7, 'contract_miss' => 4]),
                unordered: true,
            ),

            'aggregateTotal counts a single type' => self::case(
                fn (Storage $storage) => $storage->aggregateTotal('contract_hit', 'count', $hour()),
                7.0,
            ),

            'aggregateTotal takes the maximum' => self::case(
                fn (Storage $storage) => $storage->aggregateTotal('contract_timing', 'max', $hour()),
                1000.0,
            ),

            'aggregateTotal takes the sum' => self::case(
                fn (Storage $storage) => $storage->aggregateTotal('contract_timing', 'sum', $hour()),
                3421.0,
            ),

            'aggregateTotal takes the minimum' => self::case(
                fn (Storage $storage) => $storage->aggregateTotal('contract_timing', 'min', $hour()),
                5.0,
            ),

            'aggregateTotal is zero for an unknown type' => self::case(
                fn (Storage $storage) => $storage->aggregateTotal('contract_unknown', 'count', $hour()),
                0.0,
            ),
        ];
    }

    /**
     * The case names as a Pest / PHPUnit dataset (`name => [name]`).
     *
     * @return array<string, array{0: string}>
     */
    public static function dataset(): array
    {
        $names = array_keys(self::cases());

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    /**
     * Purge the contract types and store the dataset at the frozen time.
     *
     * @param  (Closure(Storage): void)|null  $afterSeed  e.g. refresh the Elasticsearch indices
     */
    public static function seed(Storage $storage, ?Closure $afterSeed = null): void
    {
        self::freezeTime();

        $storage->purge(self::types());

        foreach (self::batches() as $batch) {
            $storage->store($batch);
        }

        if ($afterSeed !== null) {
            $afterSeed($storage);
        }
    }

    /**
     * Seed the storage and assert one case. Leaves time frozen at NOW.
     *
     * @param  (Closure(Storage): void)|null  $afterSeed
     */
    public static function assertCase(Storage $storage, string $case, ?Closure $afterSeed = null): void
    {
        $cases = self::cases();

        if (! isset($cases[$case])) {
            throw new InvalidArgumentException("Unknown storage contract case [{$case}].");
        }

        self::seed($storage, $afterSeed);

        self::assertCaseOnSeeded($storage, $case, $cases[$case]);
    }

    /**
     * Seed the storage once and assert every case, then purge the contract
     * types and restore the previous test time.
     *
     * @param  (Closure(Storage): void)|null  $afterSeed
     */
    public static function assertContract(Storage $storage, ?Closure $afterSeed = null): void
    {
        $previousNow = Carbon::getTestNow();

        try {
            self::seed($storage, $afterSeed);

            foreach (self::cases() as $name => $case) {
                self::assertCaseOnSeeded($storage, $name, $case);
            }

            $storage->purge(self::types());
        } finally {
            Carbon::setTestNow($previousNow);
        }
    }

    /**
     * Assert that the actual output has the expected shape and values.
     */
    public static function assertMatches(mixed $expected, mixed $actual, string $path = '$'): void
    {
        if ($expected instanceof Collection) {
            Assert::assertInstanceOf(Collection::class, $actual, "{$path}: expected a Collection, got ".get_debug_type($actual).'.');
            Assert::assertSame($expected::class, $actual::class, "{$path}: wrong collection class.");
            Assert::assertSame(
                array_keys($expected->all()),
                array_keys($actual->all()),
                "{$path}: keys, key types or key order differ.",
            );

            $items = $actual->all();

            foreach ($expected->all() as $key => $value) {
                self::assertMatches($value, $items[$key], "{$path}[{$key}]");
            }

            return;
        }

        if ($expected instanceof stdClass) {
            Assert::assertInstanceOf(stdClass::class, $actual, "{$path}: expected a stdClass row, got ".get_debug_type($actual).'.');
            Assert::assertSame(
                array_keys(get_object_vars($expected)),
                array_keys(get_object_vars($actual)),
                "{$path}: row properties or their order differ.",
            );

            foreach (get_object_vars($expected) as $property => $value) {
                self::assertMatches($value, $actual->{$property}, "{$path}->{$property}");
            }

            return;
        }

        if (is_int($expected) || is_float($expected)) {
            Assert::assertTrue(
                is_int($actual) || is_float($actual) || (is_string($actual) && is_numeric($actual)),
                "{$path}: expected a number, got ".get_debug_type($actual).' '.var_export($actual, true).'.',
            );
            Assert::assertSame(
                round((float) $expected, 2),
                round((float) $actual, 2),
                "{$path}: expected {$expected}, got {$actual}.",
            );

            return;
        }

        Assert::assertSame($expected, $actual, "{$path}: values differ.");
    }

    /**
     * @param  array{query: Closure(Storage): mixed, expected: mixed, unordered: bool}  $case
     */
    private static function assertCaseOnSeeded(Storage $storage, string $name, array $case): void
    {
        $actual = ($case['query'])($storage);
        $expected = $case['expected'];

        if ($case['unordered'] && $expected instanceof Collection && $actual instanceof Collection) {
            $expected = $expected->sortKeys();
            $actual = $actual->sortKeys();
        }

        self::assertMatches($expected, $actual, $name);
    }

    /**
     * @param  Closure(Storage): mixed  $query
     * @return array{query: Closure(Storage): mixed, expected: mixed, unordered: bool}
     */
    private static function case(Closure $query, mixed $expected, bool $unordered = false): array
    {
        return ['query' => $query, 'expected' => $expected, 'unordered' => $unordered];
    }

    /**
     * A graph series: 60 bucket keys (UTC `Y-m-d H:i:s`) set to null, with
     * the given points filled in.
     *
     * @param  array<string, int|float>  $points
     * @return Collection<string, int|float|null>
     */
    private static function series(int $firstBucket, int $step, array $points): Collection
    {
        $series = [];

        for ($i = 0; $i < 60; $i++) {
            $series[gmdate('Y-m-d H:i:s', $firstBucket + $i * $step)] = null;
        }

        foreach ($points as $bucket => $value) {
            if (! array_key_exists($bucket, $series)) {
                throw new InvalidArgumentException("[{$bucket}] is not a bucket of this series.");
            }

            $series[$bucket] = $value;
        }

        return new Collection($series);
    }
}
