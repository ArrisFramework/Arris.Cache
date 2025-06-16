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

```

Правило типа 'callback' может быть:

- `Class@method`
- `Class::method`
- `customFunction`
- instance of `Closure`

Параметры передаются **всегда** массивом.


--- 
NB: Да, в PHP возможен механизм A::B()::C()