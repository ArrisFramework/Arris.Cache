<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use Arris\Cache\RedisHelper;
use Arris\Cache\Tests\Fixtures\FailingRedisClient;
use Psr\Log\NullLogger;

/**
 * Redis write path: a rejected write must never be reported as success.
 *
 * Uses a RedisClient stub instead of a real Redis, so no connection is needed.
 */
class CacheRedisWriteFailureTest extends AbstractCacheTestCase
{
    public function testRedisHelperPushReturnsFalseWhenSetFails(): void
    {
        $helper = new RedisHelper(new FailingRedisClient(enabled: false), new NullLogger());

        $this->assertFalse($helper->push('key', 'value'));
        $this->assertFalse($helper->push('key', 'value', ttl: 60));
    }

    public function testAddRuleReportsRedisFailureAndKeepsRepositoryValue(): void
    {
        $this->useFailingRedis();

        $result = Cache::addRule('rule', source: Cache::RULE_SOURCE_RAW, action: ['v' => 1]);

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('REDIS write failed', $result->getMessage());

        // The repository write is not rolled back: the value stays usable in-process.
        $this->assertTrue(Cache::check('rule'));
        $this->assertSame(['v' => 1], Cache::get('rule'));
    }

    public function testPushReportsRedisFailureButKeepsRepositoryValue(): void
    {
        $this->useFailingRedis();

        $result = Cache::push('key', ['v' => 1]);

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('REDIS push failed', $result->getMessage());

        $this->assertTrue(Cache::check('key'));
        $this->assertSame(['v' => 1], Cache::get('key'));
    }

    public function testAddRuleReportsRedisFailureWithTtl(): void
    {
        $this->useFailingRedis();

        $result = Cache::addRule('ttl_rule', source: Cache::RULE_SOURCE_RAW, action: 'v', ttl: 60);

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('REDIS write failed', $result->getMessage());
        $this->assertSame('v', Cache::get('ttl_rule'));
    }

    /**
     * Swaps the facade's client for a stub whose writes fail; the stub is
     * marked connected so addRule()/push() take the Redis write branch.
     */
    private function useFailingRedis(): void
    {
        $client = new FailingRedisClient(enabled: false);
        $client->is_connected = true;

        \Closure::bind(static function () use ($client): void {
            Cache::$redis = $client;
            Cache::$redis_helper = null;
        }, null, Cache::class)();
    }
}
