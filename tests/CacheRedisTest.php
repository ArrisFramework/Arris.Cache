<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use Arris\Cache\CacheHelper;
use Arris\Entity\Result;
use Arris\Toolkit\RedisClientException;

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

        if (!Cache::isRedisConnected()) {
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
        $this->assertTrue(Cache::isRedisConnected());
        $this->assertTrue(Cache::redisCheck('anything_not_present') === false);
    }

    public function testInitResetsRepositoryButLeavesRedisIntact(): void
    {
        Cache::push('key', 'value');
        $this->assertTrue(Cache::check('key'));

        Cache::init(redis_host: '127.0.0.1', redis_database: 15, redis_enabled: true);

        $this->assertFalse(Cache::check('key'));
        $this->assertTrue(Cache::redisCheck('key'));
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

    public function testRedisFetchDecodesFalsyJsonValues(): void
    {
        Cache::redisPush('zero', 0);
        Cache::redisPush('zero_float', 0.0);
        Cache::redisPush('false_value', false);
        Cache::redisPush('empty_string', '');

        $this->assertSame(0, Cache::redisFetch('zero'));
        $this->assertSame(0.0, Cache::redisFetch('zero_float'));
        $this->assertFalse(Cache::redisFetch('false_value'));
        $this->assertSame('', Cache::redisFetch('empty_string'));
    }

    public function testRedisFetchReturnsRawEmptyStringWithoutDecoding(): void
    {
        Cache::redisPush('raw_empty', '', use_json_encode: false);

        $this->assertSame('', Cache::redisFetch('raw_empty'));
    }

    public function testRedisFetchReturnsNullForMissingKey(): void
    {
        $this->assertNull(Cache::redisFetch('missing_key'));
    }

    public function testRedisHelperReconnectsAfterConnectionClose(): void
    {
        Cache::redisPush('reconnect', 'value');

        Cache::getConnector()->close();

        $this->assertSame('value', Cache::redisFetch('reconnect'));
        $this->assertTrue(Cache::redisCheck('reconnect'));
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

    public function testDropByMaskRemovesFromRepositoryAndRedis(): void
    {
        Cache::push('item_1', 'a');
        Cache::push('item_2', 'b');
        Cache::push('other', 'c');

        $result = Cache::drop('item_*');

        $this->assertTrue($result->is_success);
        $this->assertFalse(Cache::check('item_1'));
        $this->assertFalse(Cache::check('item_2'));
        $this->assertTrue(Cache::check('other'));
        $this->assertFalse(Cache::redisCheck('item_1'));
        $this->assertFalse(Cache::redisCheck('item_2'));
        $this->assertTrue(Cache::redisCheck('other'));
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

    public function testAddRuleWithNullActionDoesNotCache(): void
    {
        $result = Cache::addRule('empty', source: Cache::RULE_SOURCE_RAW, action: null);

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('empty action', $result->getMessage());
        $this->assertFalse(Cache::check('empty'));
        $this->assertFalse(Cache::redisCheck('empty'));
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

    public function testDelCounterRemovesFromRepositoryAndRedis(): void
    {
        Cache::addCounter('ct', 5);

        $result = Cache::delCounter('ct');

        $this->assertTrue($result->is_success);
        $this->assertFalse(Cache::check('ct'));
        $this->assertFalse(Cache::redisCheck('ct'));
        $this->assertSame(0, Cache::getCounter('ct'));
    }

    public function testGetCounterClearsStaleRepositoryValueWhenRedisKeyMissing(): void
    {
        Cache::addCounter('ct', 5);
        $this->assertSame(5, Cache::get('ct'));

        Cache::redisDel('ct'); // удаляем только из Redis, репозиторий остаётся stale

        $this->assertTrue(Cache::check('ct'));
        $this->assertSame(0, Cache::getCounter('ct'));
        $this->assertFalse(Cache::check('ct'));
        $this->assertSame(42, Cache::getCounter('ct', 42));
    }

    public function testIncrAndDecrThrowWhenRedisValueIsNotInteger(): void
    {
        Cache::redisPush('bad', 'abc', use_json_encode: false);

        try {
            Cache::incrCounter('bad');
            $this->fail('Expected RedisClientException on incrCounter');
        } catch (RedisClientException $e) {
            $this->assertStringContainsString('Failed to increment counter', $e->getMessage());
        }

        try {
            Cache::decrCounter('bad');
            $this->fail('Expected RedisClientException on decrCounter');
        } catch (RedisClientException $e) {
            $this->assertStringContainsString('Failed to decrement counter', $e->getMessage());
        }

        $this->assertSame('abc', Cache::redisFetch('bad', use_json_decode: false));
        $this->assertFalse(Cache::check('bad'));
    }

    public function testPushTtlExpiresInRedis(): void
    {
        Cache::redisPush('ttl', 'v', ttl: 1);
        $this->assertTrue(Cache::redisCheck('ttl'));

        sleep(2);

        $this->assertFalse(Cache::redisCheck('ttl'));
    }

    public function testPushAppliesTtlInTheSameCommand(): void
    {
        Cache::redisPush('ttl_atomic', 'v', ttl: 60);

        $ttl = Cache::getConnector()->getClient()->ttl('ttl_atomic');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);

        // SET without TTL clears the previous TTL (same as before the refactor).
        Cache::redisPush('ttl_atomic', 'v2');
        $this->assertSame(-1, Cache::getConnector()->getClient()->ttl('ttl_atomic'));
    }

    public function testAddRuleMirrorsToRedisWithTtl(): void
    {
        $result = Cache::addRule(
            'rule_ttl',
            source: Cache::RULE_SOURCE_RAW,
            action: ['v' => 7],
            ttl: 120
        );

        $this->assertTrue($result->is_success);
        $this->assertStringContainsString('saved to redis', $result->getMessage());
        $this->assertStringContainsString('TTL 120 seconds', $result->getMessage());
        $this->assertSame(['v' => 7], Cache::redisFetch('rule_ttl'));

        $ttl = Cache::getConnector()->getClient()->ttl('rule_ttl');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
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
