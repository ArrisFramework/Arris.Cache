# Arris.Cache

Arris µFramework Cache Redis wrapper

```php
use Arris\Cache\Cache;
use Arris\DB;
use Arris\AppLogger;

$pdo_connection = new PDO('');
// or $pdo_connection = DB::getConnection();

Cache::init([
    'enabled'   =>  getenv('REDIS.ENABLED'),
    'host'      =>  getenv('REDIS.HOST'),
    'port'      =>  getenv('REDIS.PORT'),
    'password'  =>  getenv('REDIS.PASSWORD'),
    'database'  =>  getenv('REDIS.DATABASE')
], [
    'districts' =>  [
        'source'    =>  'sql',
        'action'    =>  'SELECT id, name FROM districts WHERE hidden = 0 ORDER BY id ASC',
        'ttl'       =>  86400
    ],
    'rubrics'   =>  [
        'source'    =>  'sql',
        'action'    =>  'SELECT id, name, url FROM rubrics WHERE hidden = 0 ORDER BY sorder',
        'ttl'       =>  86400
    ],
    'Articles.getLatest100' =>  [
        'source'    =>  'callback',
        'action'    =>  [ "\FSNews\Units\Articles@getLeftLatestArticles", [ 100 ] ],
        'ttl'       =>  50
    ],
], $pdo_connection, AppLogger::scope('redis'));
```

Правило типа 'callback' может быть:

- `Class@method`
- `Class::method`
- `customFunction`
- instance of `Closure`

Параметры передаются **всегда** массивом.
