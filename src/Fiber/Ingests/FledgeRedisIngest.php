<?php

namespace Laravel\Pulse\Fiber\Ingests;

use Fledge\Fiber\Redis\FledgeRedisConnection;
use Laravel\Pulse\Fiber\Support\FledgeRedisAdapter;
use Laravel\Pulse\Ingests\RedisIngest;
use Laravel\Pulse\Support\RedisAdapter;

/**
 * Redis stream ingest that works on `REDIS_CLIENT=fledge`.
 *
 * ingest(), trim() and digest() are inherited unchanged from upstream, so the
 * chunking, MAXLEN / MINID trimming and deletion of digested ids behave
 * exactly the same; only the adapter differs. Non-Fledge connections fall
 * back to the upstream adapter.
 *
 * @internal
 */
class FledgeRedisIngest extends RedisIngest
{
    /**
     * Resolve the redis connection.
     */
    protected function connection(): RedisAdapter
    {
        $connection = $this->redis->connection(
            $this->config->get('pulse.ingest.redis.connection')
        );

        if ($connection instanceof FledgeRedisConnection) {
            return new FledgeRedisAdapter($connection, $this->config);
        }

        return new RedisAdapter($connection, $this->config);
    }
}
