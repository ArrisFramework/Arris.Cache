# Arris.Cache

Arris µFramework Cache Engine

```php
use Arris\Cache\Cache;
use Arris\DB;
use Arris\AppLogger;

$pdo_config = (new \Arris\Database\Config())
    ->setUsername('root')
    ->setPassword('password')
    ->setDatabase('testdatabase');
$pdo = $pdo_config->connect();

Cache::init(PDO: $pdo);

$v = 5;

Cache::addRule(
    'districts',
    source: Cache::RULE_SOURCE_SQL,
    action: 'SELECT id, name FROM districts WHERE hidden = 0 ORDER BY id ASC',
    ttl: Cache::TIME_FULL_DAY
);

Cache::addRule(
    'rubrics',
    source: Cache::RULE_SOURCE_SQL,
    action: 'SELECT id, name, url FROM rubrics WHERE hidden = 0 ORDER BY sorder',
    ttl: Cache::TIME_FULL_DAY
);

Cache::addRule(
    'data',
    source: Cache::RULE_SOURCE_CALLBACK,
    action: static function() use ($v){ return $v; },
);

Cache::addRule(
    'callback',
    source: Cache::RULE_SOURCE_CALLBACK,
    action: [ "TestClass@getData", [ 1000000 ] ]
);

Cache::addRule(
    'raw',
    source: Cache::RULE_SOURCE_RAW,
    action: [ 1 => 2, 3 => "b"]
);

var_dump();

var_dump(
    Cache::redis()->keys('*')
);

Cache::redisPush("something", [ 1 => 2, 3 => "b"]);
Cache::redis()->push("something_2", [ 1 => 2, 3 => "b"]);


```

Правило типа 'callback' может быть:

- `Class@method`
- `Class::method`
- `customFunction`
- instance of `Closure`

Параметры передаются **всегда** массивом.

# TODO

- нужен ли кастомный декодер json?


--- 

# ToDo - legacy code

```php

public static function flush(string $key, bool $clean_redis = true)
{
    $deleted = [];
    if (strpos($key, '*') === false) {
        self::unset($key);
        if (self::$redis_connector && $clean_redis) {
            self::$redis_connector->del($key);
        }
        return $key;
    } else {
        $custom_mask = self::createMask($key);
        $custom_list = preg_grep($custom_mask, self::getAllKeys());
        foreach ($custom_list as $k) {
            $deleted[] = self::flush($k, $clean_redis);
        }
        // return $custom_mask;
        return $deleted;
    }
}

```

```php
namespace Arris\Cache;

/**
 * Interface, not trait ('cause constants in traits supported since PHP 8.2)
 */
interface RedisDefaultCredentials
{
    public const REDIS_DEFAULT_HOST     = '127.0.0.1';
    public const REDIS_DEFAULT_PORT     = 6379;
    public const REDIS_DEFAULT_DB       = 0;
    public const REDIS_DEFAULT_PASSWORD = null;
}
```