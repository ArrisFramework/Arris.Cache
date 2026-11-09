<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use Arris\Toolkit\RedisClientException;
use Arris\Entity\Result;

class CacheRepositoryTest extends AbstractCacheTestCase
{
    public function testInitReturnsResultWhenRedisDisabled(): void
    {
        $result = Cache::init(redis_enabled: false);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success);
        $this->assertSame('Redis disabled', $result->getMessage());
        $this->assertFalse(Cache::isRedisConnected());
    }

    public function testInitResetsRepository(): void
    {
        Cache::set('stale', 'value');
        $this->assertTrue(Cache::check('stale'));

        $result = Cache::init(redis_enabled: false);

        $this->assertTrue($result->is_success);
        $this->assertFalse(Cache::check('stale'));
        $this->assertNull(Cache::get('stale'));
    }

    public function testRedisHelperReportsFailedInitConnection(): void
    {
        // Состояние после init(): клиент создан, но соединение не установлено.
        \Closure::bind(static function (): void {
            Cache::$redis = new \Arris\Toolkit\RedisClient(enabled: false);
            Cache::$redis_helper = null;
        }, null, Cache::class)();

        $this->assertFalse(Cache::isRedisConnected());

        try {
            Cache::redis();
            $this->fail('Expected RedisClientException');
        } catch (RedisClientException $e) {
            $this->assertStringContainsString('could not establish a connection', $e->getMessage());
            $this->assertStringContainsString('REDIS is not connected', $e->getMessage());
        }
    }

    public function testInitPassesRedisPasswordToClient(): void
    {
        Cache::init(redis_enabled: false, redis_password: 'secret');

        $client = self::cacheState('redis');
        $this->assertInstanceOf(\Arris\Toolkit\RedisClient::class, $client);

        // RedisClient has no auth getter; the only way to check the wiring is reflection.
        $auth = (new \ReflectionProperty($client, 'auth'))->getValue($client);
        $this->assertSame('secret', $auth);
    }

    public function testInitTreatsEmptyRedisPasswordAsNoAuth(): void
    {
        Cache::init(redis_enabled: false, redis_password: '');

        $client = self::cacheState('redis');
        $auth = (new \ReflectionProperty($client, 'auth'))->getValue($client);
        $this->assertNull($auth);
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

    public function testDropByMaskRemovesMatchingRepositoryKeys(): void
    {
        Cache::set('item_1', 1);
        Cache::set('item_2', 2);
        Cache::set('other', 3);

        $result = Cache::drop('item_*', redis_update: false);

        $this->assertTrue($result->is_success);
        $this->assertFalse(Cache::check('item_1'));
        $this->assertFalse(Cache::check('item_2'));
        $this->assertTrue(Cache::check('other'));
        $this->assertStringContainsString('2 cache key(s) removed', $result->getMessage());
    }

    public function testDropSingleCharMaskRemovesMatchingRepositoryKeys(): void
    {
        Cache::set('item_1', 1);
        Cache::set('item_2', 2);

        Cache::drop('item_?', redis_update: false);

        $this->assertFalse(Cache::check('item_1'));
        $this->assertFalse(Cache::check('item_2'));
    }

    public function testDropCharClassMaskRemovesMatchingRepositoryKeys(): void
    {
        Cache::set('user_1', 1);
        Cache::set('user_2', 2);
        Cache::set('user_a', 'a');

        Cache::drop('user_[0-9]', redis_update: false);

        $this->assertFalse(Cache::check('user_1'));
        $this->assertFalse(Cache::check('user_2'));
        $this->assertTrue(Cache::check('user_a'));
    }

    public function testDropExactKeyDoesNotTouchSimilarKeys(): void
    {
        Cache::set('key', 1);
        Cache::set('key_2', 2);

        Cache::drop('key', redis_update: false);

        $this->assertFalse(Cache::check('key'));
        $this->assertTrue(Cache::check('key_2'));
    }

    public function testDropWithRedisUpdateWhenNotConnectedSkipsRedis(): void
    {
        Cache::set('key', 'value');
        $result = Cache::drop('key');

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success);
        $this->assertFalse(Cache::check('key'));
        $this->assertStringContainsString('REDIS not connected', $result->getMessage());
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
        $this->assertSame([], self::cacheState('repository'));
    }

    public function testRedisHelperThrowsWhenNotConnected(): void
    {
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('REDIS is not connected');

        Cache::redis();
    }

    public function testRedisFacadeMethodsThrowWhenNotConnected(): void
    {
        $assertThrows = static function (callable $action): void {
            try {
                $action();
                self::fail('Expected RedisClientException');
            } catch (RedisClientException $e) {
                self::assertStringContainsString('REDIS is not connected', $e->getMessage());
            }
        };

        $assertThrows(static fn() => Cache::redisFetch('key'));
        $assertThrows(static fn() => Cache::redisCheck('key'));
        $assertThrows(static fn() => Cache::redisPush('key', 'value'));
        $assertThrows(static fn() => Cache::redisDel('key'));
    }

    public function testPushToRepositorySucceedsWithoutRedis(): void
    {
        $result = Cache::push('key', ['x' => 1]);

        $this->assertTrue($result->is_success);
        $this->assertStringContainsString('REDIS not connected', $result->getMessage());
        $this->assertSame(['x' => 1], Cache::get('key'));
    }

    public function testGetConnectorThrowsWhenNotConnected(): void
    {
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('REDIS is not connected');

        Cache::getConnector();
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
