<?php

namespace Laravel\Pulse\Fiber\Support;

use Closure;
use Fiber;
use Fledge\Async\Sync\LocalMutex;
use Fledge\Fiber\Database\Native\UsesNativePdoPool;
use Illuminate\Database\Connection;
use WeakReference;

/**
 * Serializes work on database connections that share one PDO between fibers.
 *
 * A stock (or `fledge-mariadb`) connection holds a single PDO. When parallel
 * fibers (for example lazy Pulse cards rendered by FiberHandleRequests) run
 * queries on it at the same time, the driver fails with "stream is in use by
 * another fiber", unbuffered-query errors or "already an active transaction".
 * Running each unit of work under a per-connection LocalMutex makes those
 * fibers take turns. Connections built on fledge-fiber's UsesNativePdoPool
 * lease one PDO per fiber and are never serialized.
 *
 * The lock is reentrant per fiber: a fiber that already holds the lock for a
 * connection runs nested work directly instead of waiting on itself. Work that
 * starts a child fiber with async() and awaits it while holding the lock will
 * deadlock if the child touches the same connection, so keep the locked
 * section free of such joins.
 *
 * The registry is static and keyed by connection name, so it lives as long as
 * the worker process (Octane, queue workers, the Fledge server). That is
 * intended: an idle mutex is a tiny object without state worth resetting, the
 * holder entry is cleared in `finally` on every exit path, and the holder is
 * referenced weakly so an abandoned fiber is collected (unwinding its
 * `finally`) instead of pinning the lock forever.
 *
 * @internal
 */
final class ConnectionMutex
{
    /**
     * The mutexes, keyed by connection name.
     *
     * @var array<string, LocalMutex>
     */
    private static array $mutexes = [];

    /**
     * The current holder of each mutex, keyed by connection name. A null
     * reference stands for the main context.
     *
     * @var array<string, WeakReference<Fiber>|null>
     */
    private static array $holders = [];

    /**
     * Whether a connection class hands each fiber its own PDO, keyed by class.
     *
     * @var array<class-string, bool>
     */
    private static array $pooled = [];

    /**
     * Determine whether work on the connection has to be serialized.
     */
    public static function requiredFor(Connection $connection): bool
    {
        return ! (self::$pooled[$connection::class] ??= in_array(
            UsesNativePdoPool::class,
            class_uses_recursive($connection),
            true,
        ));
    }

    /**
     * Run the callback while holding the mutex of the given connection name.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(string $connection, Closure $callback): mixed
    {
        if (self::heldByCurrentFiber($connection)) {
            return $callback();
        }

        $lock = (self::$mutexes[$connection] ??= new LocalMutex)->acquire();

        $fiber = Fiber::getCurrent();

        self::$holders[$connection] = $fiber === null ? null : WeakReference::create($fiber);

        try {
            return $callback();
        } finally {
            unset(self::$holders[$connection]);

            $lock->release();
        }
    }

    /**
     * Determine whether the current fiber (or the main context) holds the
     * mutex of the given connection name.
     */
    private static function heldByCurrentFiber(string $connection): bool
    {
        if (! array_key_exists($connection, self::$holders)) {
            return false;
        }

        $holder = self::$holders[$connection];
        $fiber = Fiber::getCurrent();

        return $holder === null
            ? $fiber === null
            : $fiber !== null && $holder->get() === $fiber;
    }
}
