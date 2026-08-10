<?php

namespace Arris\Cache\Tests;

use Arris\Cache\CacheHelper;
use Arris\Cache\Exceptions\CacheCallbackException;
use JsonException;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class CacheHelperTest extends TestCase
{
    private const CB = 'Arris\Cache\Tests\Callbacks';

    public function testSearchHashLikeFiltersRowsBySubstring(): void
    {
        $source = [
            ['name' => 'apple'],
            ['name' => 'banana'],
            ['name' => 'cherry'],
        ];

        $this->assertSame(
            [1 => ['name' => 'banana']],
            CacheHelper::searchHashLike($source, 'name', 'nan')
        );
    }

    public function testSearchHashLikeIsCaseInsensitiveByDefault(): void
    {
        $source = [
            ['name' => 'Apple'],
            ['name' => 'pear'],
        ];

        $this->assertSame(
            [0 => ['name' => 'Apple']],
            CacheHelper::searchHashLike($source, 'name', 'apple')
        );
    }

    public function testSearchHashLikeCaseSensitive(): void
    {
        $source = [
            ['name' => 'Apple'],
            ['name' => 'apple'],
        ];

        $this->assertSame(
            [1 => ['name' => 'apple']],
            CacheHelper::searchHashLike($source, 'name', 'apple', case_sensitive: true)
        );
    }

    public function testSearchHashLikeEmptyPatternReturnsWholeSource(): void
    {
        $source = [['name' => 'a'], ['name' => 'b']];

        $this->assertSame($source, CacheHelper::searchHashLike($source, 'name'));
    }

    public function testSearchHashAsKeyValueFindsRow(): void
    {
        $source = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];

        $this->assertSame(['id' => 2, 'name' => 'b'], CacheHelper::searchHashAsKeyValue($source, 'id', '2'));
    }

    public function testSearchHashAsKeyValueStrictMode(): void
    {
        $source = [['id' => 1, 'name' => 'a']];

        $this->assertNull(CacheHelper::searchHashAsKeyValue($source, 'id', '1', strict: true));
        $this->assertSame(['id' => 1, 'name' => 'a'], CacheHelper::searchHashAsKeyValue($source, 'id', 1, strict: true));
    }

    public function testSearchHashAsKeyValueReturnsNullOnMissOrEmpty(): void
    {
        $this->assertNull(CacheHelper::searchHashAsKeyValue([], 'id', '1'));
        $this->assertNull(CacheHelper::searchHashAsKeyValue([['id' => 1]], 'id', 'nope'));
    }

    public function testSortHashBySubkey(): void
    {
        $dataset = [
            ['id' => 3, 'name' => 'c'],
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];

        $sorted = CacheHelper::sortHashBySubkey($dataset, 'id');

        $this->assertSame([1, 2, 3], array_column($sorted, 'id'));
    }

    public function testJsonizePreservesUnicodeAndZeroFraction(): void
    {
        $this->assertSame('{"привет":"мир"}', CacheHelper::jsonize(['привет' => 'мир']));
        $this->assertSame('1.0', CacheHelper::jsonize(1.0));
    }

    public function testJsonizeThrowsOnResource(): void
    {
        $this->expectException(JsonException::class);
        CacheHelper::jsonize(fopen('php://memory', 'r'));
    }

    public function testOverrideDefaults(): void
    {
        $defaults = ['a' => 1, 'b' => 2];
        $options = ['b' => 20, 'c' => 30];

        $this->assertSame(['a' => 1, 'b' => 20], CacheHelper::overrideDefaults($defaults, $options));
    }

    public function testCompileCallbackClosure(): void
    {
        $closure = static fn() => 'x';
        [$callable, $params] = CacheHelper::compileCallbackHandler($closure, new NullLogger());

        $this->assertSame($closure, $callable);
        $this->assertSame([], $params);
    }

    public function testCompileCallbackClosureWithParams(): void
    {
        $closure = static fn(int $a, int $b) => $a + $b;
        [$callable, $params] = CacheHelper::compileCallbackHandler([$closure, [2, 3]], new NullLogger());

        $this->assertSame($closure, $callable);
        $this->assertSame([2, 3], $params);
    }

    public function testCompileCallbackFunctionName(): void
    {
        [$callable, $params] = CacheHelper::compileCallbackHandler('strtoupper', new NullLogger());

        $this->assertSame('strtoupper', $callable);
        $this->assertSame([], $params);
    }

    public function testCompileCallbackDynamicInstanceMethod(): void
    {
        [$callable, $params] = CacheHelper::compileCallbackHandler(self::CB . '@dynamicMethod', new NullLogger());

        $this->assertInstanceOf(Callbacks::class, $callable[0]);
        $this->assertSame('dynamicMethod', $callable[1]);
        $this->assertSame([], $params);
    }

    public function testCompileCallbackStaticMethod(): void
    {
        [$callable, $params] = CacheHelper::compileCallbackHandler(self::CB . '::staticMethod', new NullLogger());

        $this->assertSame(self::CB, $callable[0]);
        $this->assertSame('staticMethod', $callable[1]);
        $this->assertSame([], $params);
    }

    public function testCompileCallbackWithParams(): void
    {
        [$callable, $params] = CacheHelper::compileCallbackHandler(
            [self::CB . '::withArgs', ['x', 'y']],
            new NullLogger()
        );

        $this->assertSame(self::CB, $callable[0]);
        $this->assertSame(['x', 'y'], $params);
    }

    public function testCompileCallbackNonStringFirstArgumentThrows(): void
    {
        $this->expectException(CacheCallbackException::class);
        $this->expectExceptionMessage('First argument of callback array is NOT a string');

        CacheHelper::compileCallbackHandler([42], new NullLogger());
    }

    public function testCompileCallbackUnknownClassThrows(): void
    {
        $this->expectException(CacheCallbackException::class);
        $this->expectExceptionCode(500);

        CacheHelper::compileCallbackHandler('No\Such\Class@method', new NullLogger());
    }

    public function testCompileCallbackUnknownMethodThrows(): void
    {
        $this->expectException(CacheCallbackException::class);
        $this->expectExceptionCode(500);

        CacheHelper::compileCallbackHandler(self::CB . '::noSuchMethod', new NullLogger());
    }

    public function testCompileCallbackUnknownFunctionThrows(): void
    {
        $this->expectException(CacheCallbackException::class);
        $this->expectExceptionCode(500);

        CacheHelper::compileCallbackHandler('no_such_function_exists_xyz', new NullLogger());
    }
}
