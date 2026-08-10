<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use Arris\Toolkit\RedisClient;
use Arris\Entity\Result;

class CacheRepositoryTest extends AbstractCacheTestCase
{
    public function testInitReturnsResultWhenRedisDisabled(): void
    {
        $result = Cache::init(redis_enabled: false);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success);
        $this->assertSame('Redis disabled', $result->getMessage());
        $this->assertFalse(Cache::$is_redis_connected);
    }

    public function testSetGetRoundTrip(): void
    {
        Cache::set('key', 'value');
        $this->assertTrue(Cache::check('key'));
        $this->assertSame('value', Cache::get('key'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        $this->assertNull(Cache::get('missing'));
        $this->assertSame('def', Cache::get('missing', 'def'));
        $this->assertFalse(Cache::check('missing'));
    }

    public function testUnsetRemovesKey(): void
    {
        Cache::set('key', 'value');
        Cache::unset('key');
        $this->assertFalse(Cache::check('key'));
        $this->assertNull(Cache::get('key'));
    }

    public function testSetReplacesExistingValue(): void
    {
        Cache::set('key', 'a');
        Cache::set('key', 'b');
        $this->assertSame('b', Cache::get('key'));
    }

    public function testNullValueStillCountsAsPresentKey(): void
    {
        Cache::set('key', null);
        $this->assertTrue(Cache::check('key'));
        $this->assertNull(Cache::get('key'));
    }

    public function testDropWithoutRedisUpdate(): void
    {
        Cache::set('key', 'value');
        $result = Cache::drop('key', redis_update: false);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success);
        $this->assertFalse(Cache::check('key'));
        $this->assertStringContainsString('redis untouched', $result->getMessage());
    }

    public function testDropWithRedisUpdateWhenNotConnectedDoesNotThrow(): void
    {
        Cache::set('key', 'value');
        $result = Cache::drop('key');

        $this->assertInstanceOf(Result::class, $result);
        $this->assertFalse($result->is_success);
        $this->assertFalse(Cache::check('key'));
        $this->assertStringContainsString('redis removal failed', $result->getMessage());
    }

    public function testDropAllWhenNotConnectedDoesNotThrow(): void
    {
        Cache::set('a', 1);
        Cache::set('b', 2);

        $result = Cache::dropAll();

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success);
        $this->assertSame('Cache cleared, REDIS not connected', $result->getMessage());
        $this->assertFalse(Cache::check('a'));
        $this->assertFalse(Cache::check('b'));
    }

    public function testDropAllWithoutRedisUpdate(): void
    {
        Cache::set('a', 1);
        $result = Cache::dropAll(redis_update: false);

        $this->assertTrue($result->is_success);
        $this->assertSame('Cache cleared, REDIS untouched', $result->getMessage());
        $this->assertSame([], Cache::$repository);
    }

    public function testRedisHelperIsSafeWhenNotConnected(): void
    {
        $redis = Cache::redis();

        $this->assertNull($redis->fetch('key'));
        $this->assertFalse($redis->push('key', 'value'));
        $this->assertSame([], $redis->del('key'));
        $this->assertFalse($redis->check('key'));
        $this->assertSame([], $redis->keys('*'));
    }

    public function testRedisFacadeMethodsAreSafeWhenNotConnected(): void
    {
        $this->assertNull(Cache::redisFetch('key'));
        $this->assertFalse(Cache::redisCheck('key'));

        $push = Cache::redisPush('key', 'value');
        $this->assertFalse($push->is_success);

        $del = Cache::redisDel('key');
        $this->assertFalse($del->is_success);
        $this->assertSame([], $del->raw_array);
    }

    public function testPushToRepositoryStillSucceedsWhenNotConnected(): void
    {
        $result = Cache::push('key', ['x' => 1]);

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('REDIS push failed', $result->getMessage());
        $this->assertSame(['x' => 1], Cache::get('key'));
    }

    public function testGetConnectorReturnsRedisClient(): void
    {
        $this->assertInstanceOf(RedisClient::class, Cache::getConnector());
    }

    public function testTimeConstants(): void
    {
        $this->assertSame(1, Cache::TIME_SECOND);
        $this->assertSame(60, Cache::TIME_MINUTE);
        $this->assertSame(3600, Cache::TIME_HOUR);
        $this->assertSame(43200, Cache::TIME_DAY);
        $this->assertSame(86400, Cache::TIME_FULL_DAY);
        $this->assertSame(2592000, Cache::TIME_MONTH);
        $this->assertSame(31104000, Cache::TIME_YEAR);
    }
}
