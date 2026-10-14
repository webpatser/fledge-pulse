<?php

namespace Laravel\Pulse\Fiber\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;

/**
 * Resolves the SQL dialect of a connection by its class instead of its driver
 * name.
 *
 * Upstream matches on getDriverName(), which rejects every driver registered
 * under a custom name (for example `fledge-mariadb-native`). The connection
 * class is the reliable signal: MySqlConnection covers MariaDbConnection and
 * every Fledge MySQL/MariaDB connection, PostgresConnection and
 * SQLiteConnection cover their families.
 *
 * @internal
 */
final class SqlDialect
{
    /**
     * Resolve the dialect of the given connection.
     *
     * A connection of an unknown class falls back to the stock driver names.
     * Returns null when the dialect is not supported.
     *
     * @return 'mysql'|'pgsql'|'sqlite'|null
     */
    public static function resolve(Connection $connection): ?string
    {
        return match (true) {
            $connection instanceof MySqlConnection => 'mysql',
            $connection instanceof PostgresConnection => 'pgsql',
            $connection instanceof SQLiteConnection => 'sqlite',
            default => match ($connection->getDriverName()) {
                'mariadb', 'mysql' => 'mysql',
                'pgsql' => 'pgsql',
                'sqlite' => 'sqlite',
                default => null,
            },
        };
    }
}
