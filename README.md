# Arris.Cache

Arris µFramework Cache Engine.

Кэш-движок Arris: статический фасад `Cache` — репозиторий вычисленных значений в памяти + опциональное зеркало в Redis.

Redis (через `karelwintersky/arris.toolkit.nanoredis`) — слой персистентности: при подключении значения берутся из / сохраняются в него; при отсутствии подключения значения просто живут в ключах репозитория. **Репозиторий — это и есть кэш; Redis необязателен.**

## Установка

```bash
composer require karelwintersky/arris.cache
```

Требования: PHP `^8.2`, `ext-redis`, `ext-pdo`, `ext-json`, `ext-mbstring`.

## Быстрый старт

```php
use Arris\Cache\Cache;

// PDO-подключение (опционально, нужно только для RULE_SOURCE_SQL)
$pdo = (new \Arris\Database\Config())
    ->setUsername('root')
    ->setPassword('password')
    ->setDatabase('testdatabase')
    ->connect();

// Редис выключен — значения живут только в репозитории
Cache::init(redis_enabled: false, PDO: $pdo);

// Редис включён — значения хранятся в редисе и зеркалятся в репозиторий
Cache::init(
    redis_host: '127.0.0.1',
    redis_port: 6379,
    redis_database: 0,
    redis_enabled: true,
    PDO: $pdo
);

// С паролем: значение уходит в RedisClient как auth (пустая строка = без пароля)
Cache::init(
    redis_host: '127.0.0.1',
    redis_database: 0,
    redis_enabled: true,
    redis_password: getenv('REDIS_PASSWORD') ?: null,
);

// SQL-источник
Cache::addRule(
    'districts',
    source: Cache::RULE_SOURCE_SQL,
    action: 'SELECT id, name FROM districts WHERE hidden = 0 ORDER BY id ASC',
    ttl: Cache::TIME_FULL_DAY
);

// Коллбэк
Cache::addRule(
    'data',
    source: Cache::RULE_SOURCE_CALLBACK,
    action: static fn() => 5,
);

Cache::addRule(
    'callback',
    source: Cache::RULE_SOURCE_CALLBACK,
    action: [ "TestClass@getData", [ 1000000 ] ]
);

// Сырое значение
Cache::addRule(
    'raw',
    source: Cache::RULE_SOURCE_RAW,
    action: [ 1 => 2, 3 => "b" ]
);

$districts = Cache::get('districts');
```

> `redis_password` уходит в RedisClient как `auth`; пустая строка = без пароля. Пароль требует, чтобы клиент выполнял `AUTH` **до** `SELECT`: в `arris.toolkit.nanoredis` 1.0.0 порядок был обратный и сервер с `requirepass` отвечал `NOAUTH Authentication required`; исправлено в 1.0.1, пакет требует `^1.0.1`.

## Правила: `Cache::addRule($rule_name, $enabled = true, $source = '', $action = null, $ttl = 0): Result`

- Если Redis подключён и ключ существует — значение берётся из Redis и кладётся в репозиторий (его TTL продолжает жить в Redis).
- Иначе значение вычисляется по источнику:
  - `RULE_SOURCE_SQL` (`'sql'`) — `$pdo->query($action)->fetchAll()`; `PDOException` оборачивается в `CacheDatabaseException`;
  - `RULE_SOURCE_CALLBACK` (`'callback'`) — вызов коллбэка;
  - `RULE_SOURCE_RAW` (`'raw'`) — значение as-is.
- `$source` по умолчанию — `''` (`RULE_SOURCE_UNDEFINED`), это то же самое, что `RULE_SOURCE_CALLBACK`. Любой другой непустой источник возвращает `Result` с ошибкой и ничего не кэширует.
- Результат попадает в репозиторий и зеркалится в Redis одной атомарной командой (`SETEX` при `ttl > 0`, иначе `SET`) с `$ttl` (0 — вечно). Значения в Redis хранятся в JSON.
- Если Redis подключён, но запись отклонена, `addRule()` возвращает `Result` с ошибкой «stored to cache, but REDIS write failed»; значение остаётся в репозитории (частичный успех), но вызывающий видит проблему. `push()`/`redisPush()` в этом случае также возвращают `Result` с ошибкой.

> **TTL действует только на Redis.** Репозиторий — обычный массив в памяти процесса, у него нет времени жизни. `Cache::get()` никогда не обращается к Redis и не проверяет TTL: после экспирации ключа в Redis копия в памяти остаётся. Для long-running воркеров это означает, что значение может жить в памяти неограниченно долго; чтобы получить свежее, нужно снова вызвать `addRule()` (он прочитает Redis или пересчитает правило), либо очистить память через `drop()`/`dropAll()`/повторный `init()`.

Коллбэк может быть:

- instance of `Closure`
- `Class@method` — динамический вызов метода экземпляра
- `Class::method` — статический вызов
- `customFunction`
- массив `[handler, params]` — параметры передаются **всегда** массивом; `handler` может быть строкой или `Closure`

## Репозиторий (кэш над редисом)

```php
Cache::set(string $key, $data): void          // записать значение
Cache::get(string $key, $default = null): mixed // прочитать (или $default)
Cache::check(string $key): bool               // есть ли ключ
Cache::unset(string $key): void               // удалить из репозитория
Cache::drop(string $key, bool $redis_update = true): Result // удалить из репозитория (+ Redis)
Cache::dropAll(bool $redis_update = true): Result           // очистить всё (+ Redis)
```

В `drop()` допустима glob-маска (`item_*`, `item_?`, `*rticl*`); маска применяется и к репозиторию, и к Redis.

Движок работает и без Redis: репозиторий, счётчики и правила-callback/RAW не требуют подключения. `push()` в этом режиме пишет только в репозиторий и возвращает success с пометкой «REDIS not connected»; `drop()`/`dropAll()` просто пропускают Redis; SQL-правила возвращают ошибку «PDO not connected». `Cache::get()`/`set()`/`check()` до `init()` не меняют глобальное состояние (логирование идёт через nullsafe).

`Cache::init()` при каждом вызове очищает in-memory репозиторий, но **не трогает данные в Redis**.

## Redis

Все методы этого раздела, кроме `isRedisConnected()`, требуют установленного подключения к Redis. Если `Cache::init(redis_enabled: true)` не вызывался или соединение не установилось, они бросают `Arris\Toolkit\RedisClientException` с сообщением «REDIS is not connected…».

```php
Cache::redisFetch(string $key, bool $use_json_decode = true): mixed // прочитать (JSON-декод)
Cache::redisPush(string $key, $data, int $ttl = 0, bool $use_json_encode = true): Result // записать только в Redis
Cache::push(string $key, $data, int $ttl = 0, bool $use_json_encode = true): Result      // в репозиторий И Redis (без Redis — только репозиторий)
Cache::redisDel(string $key): Result          // удалить (допустима маска, напр. 'article*'); список удалённых ключей — в $result->raw_array
Cache::redisCheck(string $key): bool          // есть ли ключ в Redis

Cache::redis()->fetch(...)                    // хелпер, аналог redisFetch
Cache::redis()->push(...)                     // хелпер, аналог redisPush
Cache::redis()->del(...)                      // хелпер, аналог redisDel
Cache::redis()->check(...)                    // хелпер, аналог redisCheck
Cache::redis()->keys(string $pattern = '*'): array

Cache::getConnector(): RedisClient             // прямой коннектор (Arris\Toolkit\RedisClient)
Cache::isRedisConnected(): bool                // подключён ли Redis (не бросает исключения)
```

`push()` кладёт значение в репозиторий (без TTL) и в Redis (с TTL); `$ttl` применяется только к Redis-копии. `Cache::get()` после экспирации Redis продолжит отдавать значение из памяти процесса — см. предупреждение в разделе про `addRule()`.

## Счётчики

```php
Cache::addCounter(string $key, int $initial = 0): int
Cache::incrCounter(string $key, int $diff = 1): int
Cache::decrCounter(string $key, int $diff = 1): int
Cache::getCounter(string $key, int $default = 0): int
Cache::delCounter(string $key): Result
```

Счётчики создаются **навечно**: TTL не выставляется, повторный `addCounter()` снимает старый TTL (Redis `SET`). Когда Redis подключён — он источник истины (`incrBy`/`decrBy`/`get`), репозиторий зеркалит значение; без Redis счётчики живут только в репозитории. `incrCounter()`/`decrCounter()` бросают `RedisClientException`, если Redis вернул `false` (например, ключ содержит не число). `getCounter()` при отсутствии ключа в Redis возвращает `$default` и очищает устаревшую копию в репозитории. `delCounter()` — алиас `drop($key)`: удаляет и из репозитория, и из Redis (если подключён).

## `Result`

Методы-действия возвращают `Arris\Entity\Result` (`karelwintersky/arris.entity`):
`init`, `addRule`, `drop`, `dropAll`, `push`, `redisPush`, `redisDel`, `delCounter`.

Проверка результата:

```php
$result = Cache::drop('key');
if ($result->is_success) {
    echo $result->getMessage();
}
```

## Константы времени

| Константа       | Значение | Комментарий                     |
|-----------------|----------|---------------------------------|
| `TIME_SECOND`   | 1        |                                 |
| `TIME_MINUTE`   | 60       |                                 |
| `TIME_HOUR`     | 3600     |                                 |
| `TIME_DAY`      | 43200    | «день» = 12 часов               |
| `TIME_FULL_DAY` | 86400    | «сутки» = 24 часа               |
| `TIME_MONTH`    | 2592000  |                                 |
| `TIME_YEAR`     | 31104000 |                                 |

## Утилиты: `CacheHelper`

- `searchHashLike($source, $field, $pattern, $case_sensitive)` — фильтр строк 2D-массива по подстроке в колонке;
- `searchHashAsKeyValue($array, $key, $value, $strict)` — найти подмассив по значению колонки;
- `sortHashBySubkey($dataset, $order_by, $strict)` — usort по ключу подмассива;
- `raiseFlag($flag, $value, $ttl)` — тонкая обёртка над `redisPush`;
- `jsonize($data)` — `json_encode` (UNICODE | PRESERVE_ZERO_FRACTION | INVALID_UTF8_SUBSTITUTE | THROW_ON_ERROR);
- `overrideDefaults($defaults, $options)` — дефолты, перезаписанные опциями;
- `fetchOptionBool($option, $if_present, $if_not_present_or_zero)` — чтение 0/1-флага из Redis;
- `compileCallbackHandler($actor, $logger)` — компиляция коллбэка в `[callable, params]`; `params` обязан быть массивом, handler — непустым `Closure`/строкой, иначе `CacheCallbackException`.

## Исключения

- `Arris\Cache\Exceptions\CacheCallbackException` — неверный коллбэк в правиле;
- `Arris\Cache\Exceptions\CacheDatabaseException` — `PDOException`, обёрнутый в правиле;
- `Arris\Toolkit\RedisClientException` — Redis-метод вызван без подключения (или ошибка самого клиента).

## Тесты

```bash
composer install
vendor/bin/phpunit
```

107 тестов / 274 assertions (phpunit `^10.5`). Redis-тесты работают в изолированной БД 15 и автоматически пропускаются, если Redis недоступен — набор проходит и без Redis. SQL-тесты используют SQLite `:memory:`, MySQL не требуется.

## TODO

- нужен ли кастомный декодер json?
- сделать алиас `flush()` как аналог `drop()`
