<?php

namespace Arris\Cache\Tests;

use Arris\Cache\Cache;

class CacheCounterTest extends AbstractCacheTestCase
{
    public function testAddCounterStoresInitialValue(): void
    {
        $this->assertSame(10, Cache::addCounter('cnt', 10));
        $this->assertSame(10, Cache::getCounter('cnt'));
        $this->assertSame(10, Cache::get('cnt'));
    }

    public function testAddCounterDefaultsToZero(): void
    {
        $this->assertSame(0, Cache::addCounter('cnt'));
        $this->assertSame(0, Cache::getCounter('cnt'));
    }

    public function testIncrement(): void
    {
        Cache::addCounter('cnt', 15);
        $this->assertSame(20, Cache::incrCounter('cnt', 5));
        $this->assertSame(20, Cache::getCounter('cnt'));
    }

    public function testDecrement(): void
    {
        Cache::addCounter('cnt', 20);
        $this->assertSame(17, Cache::decrCounter('cnt', 3));
        $this->assertSame(17, Cache::getCounter('cnt'));
    }

    public function testIncrementOnAbsentKeyStartsFromZero(): void
    {
        $this->assertSame(1, Cache::incrCounter('cnt'));
    }

    public function testDecrementOnAbsentKeyStartsFromZero(): void
    {
        $this->assertSame(-1, Cache::decrCounter('cnt'));
    }

    public function testGetCounterFallsBackToDefault(): void
    {
        $this->assertSame(0, Cache::getCounter('cnt'));
        $this->assertSame(99, Cache::getCounter('cnt', 99));
    }

    public function testGetCounterIgnoresDefaultWhenKeyExists(): void
    {
        Cache::addCounter('cnt', 5);
        $this->assertSame(5, Cache::getCounter('cnt', 99));
    }
}
