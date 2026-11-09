<?php

namespace Arris\Cache\Tests\Fixtures;

use Arris\Toolkit\RedisClient;

/**
 * RedisClient stub that rejects every write without opening a connection.
 *
 * Instantiate with `enabled: false` so reads short-circuit too; `is_connected`
 * is then set to `true` by the test to make the facade take the write branch.
 */
class FailingRedisClient extends RedisClient
{
    public function set(string $key, mixed $value, ?int $timeout = null): bool
    {
        return false;
    }

    public function flushDatabase($async = null): bool
    {
        return false;
    }
}
