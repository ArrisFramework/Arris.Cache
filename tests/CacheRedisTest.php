<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use Arris\Cache\CacheHelper;
use Arris\Entity\Result;

/**
 * Redis-backed behavior tests.
 *
 * These use a dedicated database (15) so real data on other DBs is never touched:
 * each test flushes DB 15 in setUp/tearDown. If Redis is unreachable, the whole
 * suite is skipped.
 */
class CacheRedisTest extends AbstractCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::init(redis_host: '127.0.0.1', redis_database: 15, redis_enabled: true);

        if (!Cache::$is_redis_connected) {
            $this->markTestSkipped('Redis not available on 127.0.0.1:6379');
        }

        Cache::dropAll(redis_update: true);
    }

    protected function tearDown(): void
    {
        Cache::dropAll(redis_update: true);
        parent::tearDown();
    }

    public function testInitConnectsToRedis(): void
    {
        $this->assertTrue(Cache::$is_redis_connected);
        $this->assertTrue(Cache::redisCheck('anything_not_present') === false);
    }

    public function testRedisPushFetchRoundTrip(): void
    {
        Cache::redisPush('data', ['a' => 1, 'b' => [2, 3]]);

        $this->assertTrue(Cache::redisCheck('data'));
        $this->assertSame(['a' => 1, 'b' => [2, 3]], Cache::redisFetch('data'));
    }

    public function testRedisFetchWithoutJsonDecode(): void
    {
        Cache::redisPush('data', ['a' => 1]);

        $this->assertSame('{"a":1}', Cache::redisFetch('data', use_json_decode: false));
    }

    public function testRedisPushWithoutJsonEncode(): void
    {
        Cache::redisPush('raw', 'plain', use_json_encode: false);

        $this->assertSame('plain', Cache::redisFetch('raw', use_json_decode: false));
    }

    public function testRedisPushReturnsResult(): void
    {
        $result = Cache::redisPush('key', 1);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success);
    }

    public function testRedisDelByMask(): void
    {
        Cache::redisPush('mask_1', 'a');
        Cache::redisPush('mask_2', 'b');

        $result = Cache::redisDel('mask_*');

        $this->assertTrue($result->is_success);
        $this->assertCount(2, $result->raw_array);
        $this->assertFalse(Cache::redisCheck('mask_1'));
        $this->assertFalse(Cache::redisCheck('mask_2'));
    }

    public function testRedisDelNoMatch(): void
    {
        $result = Cache::redisDel('nothing_matches_*');

        $this->assertTrue($result->is_success);
        $this->assertSame([], $result->raw_array);
        $this->assertStringContainsString('No keys matched', $result->getMessage());
    }

    public function testPushStoresToRepositoryAndRedis(): void
    {
        $result = Cache::push('key', ['v' => 1]);

        $this->assertTrue($result->is_success);
        $this->assertSame(['v' => 1], Cache::get('key'));
        $this->assertSame(['v' => 1], Cache::redisFetch('key'));
    }

    public function testDropRemovesFromRepositoryAndRedis(): void
    {
        Cache::push('key', 'val');

        $result = Cache::drop('key');

        $this->assertTrue($result->is_success);
        $this->assertFalse(Cache::check('key'));
        $this->assertFalse(Cache::redisCheck('key'));
    }

    public function testDropAllFlushesRedis(): void
    {
        Cache::redisPush('a', 1);
        Cache::redisPush('b', 2);

        $result = Cache::dropAll();

        $this->assertTrue($result->is_success);
        $this->assertSame('Cache cleared, REDIS database flushed', $result->getMessage());
        $this->assertFalse(Cache::redisCheck('a'));
        $this->assertFalse(Cache::redisCheck('b'));
    }

    public function testRedisHelperKeys(): void
    {
        Cache::redisPush('k_a', 1);
        Cache::redisPush('k_b', 2);

        $this->assertCount(2, Cache::redis()->keys('k_*'));
    }

    public function testAddRuleLoadsExistingValueFromRedis(): void
    {
        Cache::redisPush('rule', ['src' => 'redis']);

        $result = Cache::addRule(
            'rule',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: static fn() => ['src' => 'callback']
        );

        $this->assertTrue($result->is_success);
        $this->assertSame(['src' => 'redis'], Cache::get('rule'));
        $this->assertStringContainsString('from redis', $result->getMessage());
    }

    public function testAddRuleComputesAndMirrorsToRedisWhenKeyAbsent(): void
    {
        $result = Cache::addRule(
            'fresh',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: static fn() => ['v' => 42]
        );

        $this->assertTrue($result->is_success);
        $this->assertSame(['v' => 42], Cache::get('fresh'));
        $this->assertSame(['v' => 42], Cache::redisFetch('fresh'));
    }

    public function testCountersUseRedisAsSource(): void
    {
        $this->assertSame(10, Cache::addCounter('c', 10));
        $this->assertSame(11, Cache::incrCounter('c'));
        $this->assertSame(9, Cache::decrCounter('c', 2));
        $this->assertSame(9, Cache::getCounter('c'));

        Cache::unset('c');
        $this->assertSame(9, Cache::getCounter('c'));
    }

    public function testCounterGetFallsBackToDefaultWhenKeyAbsent(): void
    {
        $this->assertSame(0, Cache::getCounter('absent'));
        $this->assertSame(77, Cache::getCounter('absent', 77));
    }

    public function testCounterTtlExpiresInRedis(): void
    {
        Cache::addCounter('ct', 5, ttl: 1);
        $this->assertTrue(Cache::redisCheck('ct'));

        sleep(2);

        $this->assertFalse(Cache::redisCheck('ct'));
        Cache::unset('ct');
        $this->assertSame(0, Cache::getCounter('ct'));
    }

    public function testPushTtlExpiresInRedis(): void
    {
        Cache::redisPush('ttl', 'v', ttl: 1);
        $this->assertTrue(Cache::redisCheck('ttl'));

        sleep(2);

        $this->assertFalse(Cache::redisCheck('ttl'));
    }

    public function testFetchOptionBool(): void
    {
        CacheHelper::raiseFlag('flag1', 1);
        $this->assertSame(7, CacheHelper::fetchOptionBool('flag1', 7, 0));

        CacheHelper::raiseFlag('flag0', 0);
        $this->assertSame(0, CacheHelper::fetchOptionBool('flag0', 7, 0));

        $this->assertSame(0, CacheHelper::fetchOptionBool('missing_flag', 7, 0));
        $this->assertSame(0, CacheHelper::fetchOptionBool('', 7, 0));
    }

    public function testGetConnectorTalksToRedis(): void
    {
        Cache::getConnector()->set('c_key', 1);

        $this->assertTrue(Cache::redisCheck('c_key'));
    }
}
