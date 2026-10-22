<?php

namespace Laravel\Pulse\Fiber\Support;

use Closure;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Laravel\Pulse\Support\RedisAdapter;
use Laravel\Pulse\Support\RedisServerException;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

/**
 * Runs the Pulse stream commands through a Fledge Redis connection.
 *
 * The upstream adapter only understands phpredis, Relay and Predis clients,
 * so a Fledge connection hits a TypeError on both the ingest pipeline and
 * every direct command. This adapter keeps the same public surface (and is
 * a RedisAdapter, so the inherited RedisIngest closures still type check)
 * but sends raw commands through FledgeRedisConnection::executeRaw().
 *
 * Key prefixing matches upstream exactly: the global
 * `database.redis.options.prefix` is prepended manually and the raw command
 * bypasses the connection's own key prefixer, so the stream key is
 * `{prefix}laravel:pulse:ingest` regardless of the Redis client in use.
 *
 * @internal
 */
class FledgeRedisAdapter extends RedisAdapter
{
    /**
     * The commands queued while running inside a pipeline, or null when
     * commands are executed immediately.
     *
     * @var list<array{list<string>, (Closure(mixed): mixed)|null}>|null
     */
    protected ?array $queued = null;

    /**
     * Create a new Fledge Redis adapter instance.
     *
     * Final because pipeline() builds the queueing adapter with `new static`.
     */
    final public function __construct(
        FledgeRedisConnection $connection,
        Repository $config,
    ) {
        parent::__construct($connection, $config);
    }

    /**
     * Add an entry to the stream.
     *
     * Returns the new entry id, or an empty string when queued inside a
     * pipeline (the ids are then part of the pipeline() result).
     *
     * @param  array<string, string>  $dictionary
     */
    public function xadd(string $key, array $dictionary): string
    {
        return (string) $this->handle([
            'XADD',
            $this->prefix($key),
            '*',
            ...$this->flattenDictionary($dictionary),
        ]);
    }

    /**
     * Read a range of items from the stream.
     *
     * @return array<string, array<string, string>>
     */
    public function xrange(string $key, string $start, string $end, ?int $count = null): array
    {
        $result = $this->handle([
            'XRANGE',
            $this->prefix($key),
            $start,
            $end,
            ...$count !== null ? ['COUNT', (string) $count] : [],
        ], $this->mapRange(...));

        return is_array($result) ? $result : [];
    }

    /**
     * Trim the stream.
     */
    public function xtrim(string $key, string $strategy, string $strategyModifier, string|int $threshold): int
    {
        return (int) $this->handle([
            'XTRIM',
            $this->prefix($key),
            $strategy,
            $strategyModifier,
            (string) $threshold,
        ]);
    }

    /**
     * Delete the items from the stream.
     *
     * @param  Collection<int, string>|array<int, string>  $keys
     */
    public function xdel(string $stream, Collection|array $keys): int
    {
        return (int) $this->handle([
            'XDEL',
            $this->prefix($stream),
            ...array_values(array_map(strval(...), Collection::wrap($keys)->all())),
        ]);
    }

    /**
     * Run commands within a pipeline.
     *
     * The queued commands are dispatched from one fiber each, in order. The
     * Fledge Redis link writes commands to the socket in enqueue order and
     * matches replies FIFO, so this is a real pipeline (one round trip for the
     * batch) and stream entries keep their insertion order.
     *
     * @param  (callable(self): void)  $closure
     * @return array<int, mixed>
     */
    public function pipeline(callable $closure): array
    {
        $pipeline = new static($this->connection(), $this->config);
        $pipeline->queued = [];

        $closure($pipeline);

        $commands = $pipeline->takeQueued();

        if ($commands === []) {
            return [];
        }

        $futures = array_map(
            fn (array $command) => async(fn (): mixed => $this->execute(...$command)),
            $commands,
        );

        $results = await($futures);

        ksort($results);

        return array_values($results);
    }

    /**
     * Take the queued commands and leave pipeline mode.
     *
     * A method rather than an inline property read: the pipeline closure
     * fills the queue behind PHPStan's back, which would otherwise keep
     * treating it as the empty array assigned before the closure ran.
     *
     * @return list<array{list<string>, (Closure(mixed): mixed)|null}>
     */
    protected function takeQueued(): array
    {
        $queued = $this->queued ?? [];

        $this->queued = null;

        return $queued;
    }

    /**
     * Run the given command, or queue it (returning null) inside a pipeline.
     *
     * @param  list<string>  $args
     * @param  (Closure(mixed): mixed)|null  $map
     */
    protected function handle(array $args, ?Closure $map = null): mixed
    {
        if ($this->queued !== null) {
            $this->queued[] = [$args, $map];

            return null;
        }

        return $this->execute($args, $map);
    }

    /**
     * Execute the given raw command against the Fledge connection.
     *
     * @param  list<string>  $args
     * @param  (Closure(mixed): mixed)|null  $map
     */
    protected function execute(array $args, ?Closure $map = null): mixed
    {
        try {
            $result = $this->connection()->executeRaw($args);
        } catch (QueryException $e) {
            throw RedisServerException::whileRunningCommand(implode(' ', $args), $e->getMessage(), previous: $e);
        }

        return $map !== null ? $map($result) : $result;
    }

    /**
     * Map a raw XRANGE reply to `[id => [field => value]]`.
     *
     * @return array<string, array<string, string>>
     */
    protected function mapRange(mixed $result): array
    {
        $entries = [];

        foreach (is_array($result) ? $result : [] as [$id, $fields]) {
            $entry = [];

            foreach (array_chunk((array) $fields, 2) as [$field, $value]) {
                $entry[(string) $field] = (string) $value;
            }

            $entries[(string) $id] = $entry;
        }

        return $entries;
    }

    /**
     * Flatten the dictionary into alternating field and value arguments.
     *
     * @param  array<string, string|int|float>  $dictionary
     * @return list<string>
     */
    protected function flattenDictionary(array $dictionary): array
    {
        $args = [];

        foreach ($dictionary as $field => $value) {
            $args[] = (string) $field;
            $args[] = (string) $value;
        }

        return $args;
    }

    /**
     * Apply the global Redis key prefix, as upstream does.
     */
    protected function prefix(string $key): string
    {
        return $this->config->get('database.redis.options.prefix').$key;
    }

    /**
     * Retrieve the Fledge Redis connection.
     */
    protected function connection(): FledgeRedisConnection
    {
        /** @var FledgeRedisConnection */
        return $this->connection;
    }
}
