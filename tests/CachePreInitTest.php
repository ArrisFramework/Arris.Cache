<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use Arris\Toolkit\RedisClientException;
use PHPUnit\Framework\TestCase;

/**
 * The static facade must be usable before Cache::init(): typed static
 * properties are reset here to emulate a fresh process.
 */
class CachePreInitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Closure::bind(static function (): void {
            Cache::$logger = null;
            Cache::$redis = null;
            Cache::$redis_helper = null;
            Cache::$is_redis_connected = false;
            Cache::$pdo = null;
            Cache::$repository = [];
        }, null, Cache::class)();
    }

    protected function tearDown(): void
    {
        Cache::init(redis_enabled: false);
        Cache::dropAll(redis_update: false);

        parent::tearDown();
    }

    public function testRepositoryApiWorksBeforeInit(): void
    {
        $this->assertFalse(Cache::check('missing'));
        $this->assertNull(Cache::get('missing'));
        $this->assertSame('def', Cache::get('missing', 'def'));

        Cache::set('key', 'value');
        $this->assertSame('value', Cache::get('key'));

        Cache::unset('key');
        $this->assertFalse(Cache::check('key'));
    }

    public function testDropAndDropAllWorkBeforeInit(): void
    {
        Cache::set('key', 1);

        $result = Cache::drop('key');
        $this->assertTrue($result->is_success);
        $this->assertStringContainsString('REDIS not connected', $result->getMessage());
        $this->assertFalse(Cache::check('key'));

        $result = Cache::dropAll(redis_update: false);
        $this->assertTrue($result->is_success);
    }

    public function testAddRuleCallbackWorksBeforeInit(): void
    {
        $result = Cache::addRule('rule', action: static fn() => 'value');

        $this->assertTrue($result->is_success);
        $this->assertSame('value', Cache::get('rule'));
    }

    public function testAddRuleSqlBeforeInitReturnsError(): void
    {
        $result = Cache::addRule('rule', source: Cache::RULE_SOURCE_SQL, action: 'SELECT 1');

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('PDO not connected', $result->getMessage());
        $this->assertFalse(Cache::check('rule'));
    }

    public function testRepositoryReadBeforeInitDoesNotInitializeLogger(): void
    {
        $this->assertNull(Cache::$logger);

        Cache::get('missing');
        Cache::check('missing');
        Cache::set('key', 1);
        Cache::unset('key');

        $this->assertNull(Cache::$logger);
    }

    public function testPushBeforeInitWritesRepositoryOnly(): void
    {
        $result = Cache::push('key', ['a' => 1]);

        $this->assertTrue($result->is_success);
        $this->assertStringContainsString('REDIS not connected', $result->getMessage());
        $this->assertSame(['a' => 1], Cache::get('key'));
        $this->assertNull(Cache::$redis);
    }

    public function testRedisHelperThrowsBeforeInit(): void
    {
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('REDIS is not connected');

        Cache::redis();
    }

    public function testGetConnectorThrowsBeforeInit(): void
    {
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('REDIS is not connected');

        Cache::getConnector();
    }

    public function testRedisFacadeMethodsThrowBeforeInit(): void
    {
        $this->assertThrowsRedisNotConnected(static fn() => Cache::redisFetch('key'));
        $this->assertThrowsRedisNotConnected(static fn() => Cache::redisPush('key', 1));
        $this->assertThrowsRedisNotConnected(static fn() => Cache::redisDel('key'));
        $this->assertThrowsRedisNotConnected(static fn() => Cache::redisCheck('key'));
    }

    private function assertThrowsRedisNotConnected(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected RedisClientException');
        } catch (RedisClientException $e) {
            $this->assertStringContainsString('REDIS is not connected', $e->getMessage());
        }
    }
}
