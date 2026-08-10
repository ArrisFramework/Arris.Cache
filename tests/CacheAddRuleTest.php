<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;
use Arris\Cache\Exceptions\CacheDatabaseException;
use Arris\Entity\Result;
use PDO;
use PDOException;

class Callbacks
{
    public function dynamicMethod(string $prefix): string
    {
        return $prefix . '-dyn';
    }

    public static function staticMethod(): string
    {
        return 'static-result';
    }

    public static function withArgs(string $a, string $b): string
    {
        return $a . '|' . $b;
    }
}

class CacheAddRuleTest extends AbstractCacheTestCase
{
    private const CB = 'Arris\Cache\Tests\Callbacks';

    public function testAddRuleRawArray(): void
    {
        $result = Cache::addRule('rule', source: Cache::RULE_SOURCE_RAW, action: [1, 2, 3]);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success);
        $this->assertSame([1, 2, 3], Cache::get('rule'));
    }

    public function testAddRuleRawScalar(): void
    {
        Cache::addRule('rule', source: Cache::RULE_SOURCE_RAW, action: 'hello');

        $this->assertSame('hello', Cache::get('rule'));
    }

    public function testAddRuleDisabled(): void
    {
        $result = Cache::addRule('rule', enabled: false, source: Cache::RULE_SOURCE_RAW, action: 'x');

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('disabled', $result->getMessage());
        $this->assertFalse(Cache::check('rule'));
    }

    public function testAddRuleNullAction(): void
    {
        $result = Cache::addRule('rule', source: Cache::RULE_SOURCE_RAW, action: null);

        $this->assertTrue($result->is_success);
        $this->assertStringContainsString('action is empty', $result->getMessage());
        $this->assertTrue(Cache::check('rule'));
        $this->assertNull(Cache::get('rule'));
    }

    public function testAddRuleCallbackClosure(): void
    {
        $result = Cache::addRule(
            'rule',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: static fn() => 'closure-result'
        );

        $this->assertTrue($result->is_success);
        $this->assertSame('closure-result', Cache::get('rule'));
    }

    public function testAddRuleCallbackClosureWithParams(): void
    {
        $result = Cache::addRule(
            'rule',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: [static fn(int $a, int $b) => $a + $b, [2, 3]]
        );

        $this->assertTrue($result->is_success);
        $this->assertSame(5, Cache::get('rule'));
    }

    public function testAddRuleCallbackPlainFunction(): void
    {
        $result = Cache::addRule(
            'rule',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: ['strtoupper', ['abc']]
        );

        $this->assertTrue($result->is_success);
        $this->assertSame('ABC', Cache::get('rule'));
    }

    public function testAddRuleCallbackDynamicInstanceMethod(): void
    {
        $result = Cache::addRule(
            'rule',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: [self::CB . '@dynamicMethod', ['p']]
        );

        $this->assertTrue($result->is_success);
        $this->assertSame('p-dyn', Cache::get('rule'));
    }

    public function testAddRuleCallbackStaticMethod(): void
    {
        $result = Cache::addRule(
            'rule',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: self::CB . '::staticMethod'
        );

        $this->assertTrue($result->is_success);
        $this->assertSame('static-result', Cache::get('rule'));
    }

    public function testAddRuleCallbackStaticMethodWithParams(): void
    {
        $result = Cache::addRule(
            'rule',
            source: Cache::RULE_SOURCE_CALLBACK,
            action: [self::CB . '::withArgs', ['x', 'y']]
        );

        $this->assertTrue($result->is_success);
        $this->assertSame('x|y', Cache::get('rule'));
    }

    public function testAddRuleSql(): void
    {
        $pdo = $this->createSqlite();
        $pdo->exec('INSERT INTO t (name) VALUES (\'a\'), (\'b\')');

        Cache::init(redis_enabled: false, PDO: $pdo);
        $result = Cache::addRule('rows', source: Cache::RULE_SOURCE_SQL, action: 'SELECT * FROM t');

        $this->assertTrue($result->is_success);
        $rows = Cache::get('rows');
        $this->assertCount(2, $rows);
        $this->assertSame(['a', 'b'], array_map(static fn($row) => $row['name'], $rows));
    }

    public function testAddRuleSqlWithoutPdo(): void
    {
        Cache::init(redis_enabled: false, PDO: null);
        $result = Cache::addRule('rows', source: Cache::RULE_SOURCE_SQL, action: 'SELECT 1');

        $this->assertFalse($result->is_success);
        $this->assertStringContainsString('PDO not connected', $result->getMessage());
        $this->assertNull(Cache::get('rows'));
    }

    public function testAddRuleSqlThrowsCacheDatabaseException(): void
    {
        Cache::init(redis_enabled: false, PDO: $this->createSqlite());

        $this->expectException(CacheDatabaseException::class);
        $this->expectExceptionMessage('throws PDO Error');

        Cache::addRule('rows', source: Cache::RULE_SOURCE_SQL, action: 'SELECT * FROM missing_table');
    }

    public function testAddRuleCallbackThrowsCacheDatabaseException(): void
    {
        $result = null;
        try {
            Cache::addRule(
                'rule',
                source: Cache::RULE_SOURCE_CALLBACK,
                action: static function (): void {
                    throw new PDOException('boom');
                }
            );
        } catch (CacheDatabaseException $e) {
            $result = $e;
        }

        $this->assertNotNull($result);
        $this->assertSame('[ERROR] Rule [rule] throws PDO Error: boom', $result->getMessage());
    }

    private function createSqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        return $pdo;
    }
}
