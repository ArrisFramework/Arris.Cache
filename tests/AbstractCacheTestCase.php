<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use PHPUnit\Framework\TestCase;

/**
 * Base fixture: resets the static Cache to a clean "Redis disabled" state
 * and empties the in-memory repository before each test.
 *
 * Note: Cache::dropAll(false) never touches Redis, so running these tests
 * against a live Redis won't flush any real data.
 */
abstract class AbstractCacheTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::init(redis_enabled: false);
        Cache::dropAll(redis_update: false);
    }
}
