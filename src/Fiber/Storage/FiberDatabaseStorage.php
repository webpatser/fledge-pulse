<?php

namespace Laravel\Pulse\Fiber\Storage;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Fiber\Support\ConnectionMutex;
use Laravel\Pulse\Fiber\Support\SqlDialect;
use Laravel\Pulse\Storage\DatabaseStorage;
use Laravel\Pulse\Value;
use RuntimeException;

/**
 * Database storage that picks its SQL dialect by connection class instead of
 * by driver name.
 *
 * Upstream matches on getDriverName(), which rejects every driver registered
 * under a custom name (for example `fledge-mariadb-native`). The connection
 * class is the reliable signal: MySqlConnection covers MariaDbConnection and
 * every Fledge MySQL/MariaDB connection, PostgresConnection and
 * SQLiteConnection cover their families.
 *
 * Every public operation (store, trim, purge and all reads) also runs under a
 * per-connection mutex when the connection shares one PDO between fibers (any
 * connection that does not use fledge-fiber's UsesNativePdoPool). Parallel
 * card fibers then take turns on that PDO instead of failing with "stream is
 * in use by another fiber", unbuffered-query errors or "already an active
 * transaction". The whole operation is inside the lock: the store transaction
 * and fully consumed result sets. Native-pool connections run unserialized.
 * See ConnectionMutex for reentrancy and lifetime.
 *
 * @phpstan-import-type AggregateRow from DatabaseStorage
 *
 * @internal
 */
class FiberDatabaseStorage extends DatabaseStorage
{
    /**
     * Store the items.
     *
     * @param  Collection<int, Entry|Value>  $items
     */
    public function store(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $this->serialized(fn () => parent::store($items));
    }

    /**
     * Trim the storage.
     */
    public function trim(): void
    {
        $this->serialized(fn () => parent::trim());
    }

    /**
     * Purge the storage.
     *
     * @param  list<string>  $types
     */
    public function purge(?array $types = null): void
    {
        $this->serialized(fn () => parent::purge($types));
    }

    /**
     * Retrieve values for the given type.
     *
     * @param  list<string>  $keys
     * @return Collection<string, object{
     *     timestamp: int,
     *     key: string,
     *     value: string
     * }>
     */
    public function values(string $type, ?array $keys = null): Collection
    {
        return $this->serialized(fn () => parent::values($type, $keys));
    }

    /**
     * Retrieve aggregate values for plotting on a graph.
     *
     * @param  list<string>  $types
     * @param  'count'|'min'|'max'|'sum'|'avg'  $aggregate
     * @return Collection<string, Collection<string, Collection<string, int|null>>>
     */
    public function graph(array $types, string $aggregate, CarbonInterval $interval): Collection
    {
        return $this->serialized(fn () => parent::graph($types, $aggregate, $interval));
    }

    /**
     * Retrieve aggregate values for the given type.
     *
     * @param  'count'|'min'|'max'|'sum'|'avg'|list<'count'|'min'|'max'|'sum'|'avg'>  $aggregates
     * @return Collection<int, object{
     *     key: string,
     *     min?: int,
     *     max?: int,
     *     sum?: int,
     *     avg?: int,
     *     count?: int
     * }>
     */
    public function aggregate(
        string $type,
        array|string $aggregates,
        CarbonInterval $interval,
        ?string $orderBy = null,
        string $direction = 'desc',
        int $limit = 101,
    ): Collection {
        return $this->serialized(fn () => parent::aggregate($type, $aggregates, $interval, $orderBy, $direction, $limit));
    }

    /**
     * Retrieve aggregate values for the given types.
     *
     * @param  string|list<string>  $types
     * @param  'count'|'min'|'max'|'sum'|'avg'  $aggregate
     * @return Collection<int, object>
     */
    public function aggregateTypes(
        string|array $types,
        string $aggregate,
        CarbonInterval $interval,
        ?string $orderBy = null,
        string $direction = 'desc',
        int $limit = 101,
    ): Collection {
        return $this->serialized(fn () => parent::aggregateTypes($types, $aggregate, $interval, $orderBy, $direction, $limit));
    }

    /**
     * Retrieve an aggregate total for the given types.
     *
     * @param  string|list<string>  $types
     * @param  'count'|'min'|'max'|'sum'|'avg'  $aggregate
     * @return float|Collection<string, int>
     */
    public function aggregateTotal(
        array|string $types,
        string $aggregate,
        CarbonInterval $interval,
    ): float|Collection {
        return $this->serialized(fn () => parent::aggregateTotal($types, $aggregate, $interval));
    }

    /**
     * Run the callback under the connection's mutex when the connection shares
     * one PDO between fibers; run it directly on a native-pool connection.
     *
     * The callback must return fully materialized results (collections,
     * scalars), never a lazy cursor, so nothing touches the PDO after the lock
     * is released.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function serialized(Closure $callback): mixed
    {
        $connection = $this->lockConnection();

        if (! ConnectionMutex::requiredFor($connection)) {
            return $callback();
        }

        return ConnectionMutex::run($connection->getName() ?? $connection::class, $callback);
    }

    /**
     * Resolve the connection whose mutex guards the storage operations.
     */
    protected function lockConnection(): Connection
    {
        return $this->connection();
    }

    /**
     * Insert new records or update the existing ones and update the count.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertCount(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($this->dialect()) {
                    'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "{$this->wrap('pulse_aggregates.value')} + {$this->wrap('laravel_upsert_alias.value')}"
                            : '`value` + values(`value`)'
                    ),
                    'pgsql', 'sqlite' => new Expression(<<<SQL
                        {$this->wrap('pulse_aggregates.value')} + "excluded"."value"
                        SQL),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the minimum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertMin(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($this->dialect()) {
                    'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "least({$this->wrap('pulse_aggregates.value')}, {$this->wrap('laravel_upsert_alias.value')})"
                            : 'least(`value`, values(`value`))'
                    ),
                    'pgsql' => new Expression(<<<SQL
                        least({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlite' => new Expression(<<<SQL
                        min({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the maximum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertMax(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($this->dialect()) {
                    'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "greatest({$this->wrap('pulse_aggregates.value')}, {$this->wrap('laravel_upsert_alias.value')})"
                            : 'greatest(`value`, values(`value`))'
                    ),
                    'pgsql' => new Expression(<<<SQL
                        greatest({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlite' => new Expression(<<<SQL
                        max({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the sum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertSum(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($this->dialect()) {
                    'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "{$this->wrap('pulse_aggregates.value')} + {$this->wrap('laravel_upsert_alias.value')}"
                            : '`value` + values(`value`)'
                    ),
                    'pgsql', 'sqlite' => new Expression(<<<SQL
                        {$this->wrap('pulse_aggregates.value')} + "excluded"."value"
                        SQL),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the average.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertAvg(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            match ($this->dialect()) {
                'mysql' => $connection->getConfig('use_upsert_alias') ? [
                    'value' => new Expression(
                        "({$this->wrap('pulse_aggregates.value')} * {$this->wrap('pulse_aggregates.count')} + ({$this->wrap('laravel_upsert_alias.value')} * {$this->wrap('laravel_upsert_alias.count')})) / ({$this->wrap('pulse_aggregates.count')} + {$this->wrap('laravel_upsert_alias.count')})"
                    ),
                    'count' => new Expression(
                        "{$this->wrap('pulse_aggregates.count')} + {$this->wrap('laravel_upsert_alias.count')}"
                    ),
                ] : [
                    'value' => new Expression('(`value` * `count` + (values(`value`) * values(`count`))) / (`count` + values(`count`))'),
                    'count' => new Expression('`count` + values(`count`)'),
                ],
                'pgsql', 'sqlite' => [
                    'value' => new Expression(<<<SQL
                        ({$this->wrap('pulse_aggregates.value')} * {$this->wrap('pulse_aggregates.count')} + ("excluded"."value" * "excluded"."count")) / ({$this->wrap('pulse_aggregates.count')} + "excluded"."count")
                        SQL),
                    'count' => new Expression(<<<SQL
                        {$this->wrap('pulse_aggregates.count')} + "excluded"."count"
                        SQL),
                ],
            }
        );
    }

    /**
     * Determine whether a manually generated key hash is required.
     */
    protected function requiresManualKeyHash(): bool
    {
        return $this->dialect() === 'sqlite';
    }

    /**
     * Resolve the SQL dialect of the storage connection.
     *
     * Chosen by connection class so custom driver names (fledge-mariadb-native,
     * fledge-pgsql, ...) work. A connection of an unknown class falls back to
     * the stock driver names before giving up.
     *
     * @return 'mysql'|'pgsql'|'sqlite'
     */
    protected function dialect(): string
    {
        $connection = $this->connection();

        return SqlDialect::resolve($connection)
            ?? throw new RuntimeException("Unsupported database driver [{$connection->getDriverName()}] on connection class [".$connection::class.']');
    }
}
