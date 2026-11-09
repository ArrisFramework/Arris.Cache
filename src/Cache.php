<?php

namespace Arris\Cache;

use Arris\Cache\Exceptions\CacheCallbackException;
use Arris\Cache\Exceptions\CacheDatabaseException;
use Arris\Entity\Result;
use Arris\Toolkit\RedisClient;
use Arris\Toolkit\RedisClientException;
use JsonException;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RedisException;

use function array_key_exists;
use function array_keys;
use function call_user_func_array;
use function count;
use function fnmatch;
use function implode;
use function is_null;
use function json_decode;
use function strpbrk;

/**
 * Кэш = in-memory репозиторий + опциональный Redis.
 *
 * Redis — персистентный слой: при подключении значения читаются из него и
 * записываются в него; без подключения репозиторий (Cache) работает самостоятельно.
 *
 * ВАЖНО: TTL (`$ttl` в addRule/push/redisPush) применяется только к ключам Redis.
 * Репозиторий — обычный массив без времени жизни; get() не обращается к Redis и
 * не проверяет TTL. После экспирации ключа в Redis копия в памяти процесса
 * остаётся до addRule()/drop()/dropAll()/init().
 */
class Cache
{
    public const REDIS_DEFAULT_HOST = '127.0.0.1';
    public const REDIS_DEFAULT_PORT = 6379;
    public const REDIS_DEFAULT_DB   = 0;

    public const TIME_SECOND    = 1;
    public const TIME_MINUTE    = self::TIME_SECOND * 60;
    public const TIME_HOUR      = self::TIME_MINUTE * 60;
    public const TIME_DAY       = self::TIME_HOUR * 12;
    public const TIME_FULL_DAY  = self::TIME_HOUR * 24;
    public const TIME_MONTH     = self::TIME_FULL_DAY * 30;
    public const TIME_YEAR      = self::TIME_MONTH * 12;

    public const RULE_SOURCE_SQL = 'sql';
    public const RULE_SOURCE_CALLBACK = 'callback';
    public const RULE_SOURCE_RAW = 'raw';
    public const RULE_SOURCE_UNDEFINED = '';

    private static ?LoggerInterface $logger = null;

    private static PDO|null $pdo = null;

    private static array $repository = [];

    private static ?RedisClient $redis = null;

    private static ?RedisHelper $redis_helper = null;

    /**
     * Инициализирует кэш (репозиторий и, опционально, подключение к Redis).
     *
     * При каждом вызове очищает in-memory репозиторий; данные в Redis не трогаются.
     *
     * @param string $redis_host
     * @param int $redis_port
     * @param int $redis_database
     * @param bool $redis_enabled
     * @param PDO|null $PDO
     * @param LoggerInterface|null $logger
     * @param string|null $redis_password пароль Redis (передаётся клиенту как `auth`; пустая строка = без пароля)
     *
     * @return Result Ошибка подключения к Redis возвращается как `Result::error`, а не исключение.
     * @throws RedisClientException если не установлено расширение ext-redis (конструктор RedisClient)
     */
    public static function init(
        string $redis_host = self::REDIS_DEFAULT_HOST,
        int $redis_port = self::REDIS_DEFAULT_PORT,
        int $redis_database = self::REDIS_DEFAULT_DB,
        bool $redis_enabled = true,
        ?PDO $PDO = null,
        ?LoggerInterface $logger = null,
        ?string $redis_password = null
    ): Result
    {
        $result = new Result();

        self::$logger = is_null($logger) ? new NullLogger() : $logger;
        self::$pdo = $PDO;

        // Повторный init() начинает с чистого репозитория: старые значения могли быть загружены из другого Redis-подключения/БД.
        self::$repository = [];

        self::$redis = new RedisClient(
            host: $redis_host,
            port: $redis_port,
            database: $redis_database,
            auth: $redis_password === '' ? null : $redis_password,
            enabled: $redis_enabled
        );
        self::$logger?->info("[init] NanoRedis instantiated");

        if ($redis_enabled) {
            try {
                self::$redis->connect();
                self::$logger?->info("[init] REDIS Connected");
                $result->success("REDIS Connected");
            } catch (RedisClientException|RedisException $e) {
                self::$logger?->info("Connection error", [ $e->getCode(), $e->getMessage(), $e->getTraceAsString() ]);
                $result->error("REDIS connection error: " . $e->getMessage(), (int)$e->getCode());
            }
        } else {
            $result->success("Redis disabled");
        }

        if (!is_null($PDO)) {
            $PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        self::$redis_helper = new RedisHelper(self::$redis, self::$logger ?? new NullLogger());

        return $result;
    }


    /**
     * Добавляет ПРАВИЛО: значение в репозиторий — из Redis по ключу или из указанного источника.
     *
     * Если Redis подключён и ключ существует, значение берётся из Redis (его TTL
     * продолжает жить в Redis) и кладётся в репозиторий; источник не вычисляется.
     *
     * @param string $rule_name  ключ
     * @param bool $enabled      включено ли правило
     * @param string $source     источник данных: RULE_SOURCE_SQL, RULE_SOURCE_CALLBACK, RULE_SOURCE_RAW
     * @param mixed|null $action SQL-запрос, коллбэк/`[handler, params]` или сырое значение
     * @param int $ttl           TTL Redis-копии в секундах, 0 — вечно; на репозиторий не действует
     *
     * @return Result При подключённом Redis запись зеркалится атомарно (SETEX/SET);
     *                если Redis отклонил запись, возвращается ошибка, а значение
     *                остаётся в репозитории.
     *
     * @throws CacheDatabaseException если SQL-источник бросил PDOException
     * @throws CacheCallbackException если описание коллбэка некорректно
     * @throws JsonException если результат не удалось JSON-кодировать для Redis
     * @throws RedisException|RedisClientException если операция с Redis бросила исключение
     */
    public static function addRule(string $rule_name, bool $enabled = true, string $source = '', mixed $action = null, int $ttl = 0):Result
    {
        $result = new Result();
        self::$logger?->info("[addRule] called for `{$rule_name}`");

        if (self::isRedisConnected()) {
            $rule_value = self::$redis->get($rule_name, false);

            if ($rule_value !== false) {
                self::set(
                    $rule_name,
                    json_decode($rule_value, true, 512, JSON_THROW_ON_ERROR)
                );
                $result->success("Loaded `{$rule_name}` from redis, stored to cache");
                self::$logger?->info("[addRule] Loaded `{$rule_name}` from REDIS, stored to cache");
                return $result;
            }
        }

        // Именно так: если данные есть в редис - их просто кладем в кэш
        if ($enabled === false) {
            $result->error("Rule `{$rule_name}` disabled");
            self::$logger?->info("[addRule] Rule `{$rule_name}` disabled");
            return $result;
        }

        if (is_null($action)) {
            $result->error("Rule `{$rule_name}` has empty action, nothing cached");
            self::$logger?->info("[addRule] Rule `{$rule_name}` has empty action, nothing cached");
            return $result;
        }

        $data = null;

        switch ($source) {
            case self::RULE_SOURCE_SQL: {
                self::$logger?->info("[addRule] RULE SOURCE SQL");

                // Коннекта к БД нет: правило не выполняется и ничего не кэшируется
                if (is_null(self::$pdo)) {
                    $result->error("[ERROR] Key {$rule_name} not found, action is SQL, but PDO not connected");
                    self::$logger?->info("[addRule] Error: key {$rule_name} not found, action is SQL, but PDO not connected");
                    return $result;
                }

                try {
                    $sth = self::$pdo->query($action);
                    $data = $sth->fetchAll();
                    $message = "Data for {$rule_name} fetched from DB";
                    self::$logger?->info("[addRule] Data fetched from DB");

                } catch (PDOException $e) {
                    self::$logger?->info("[addRule] Rule throws PDO Error", [ $e->getCode(), $e->getMessage() ]);
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

                break;
            }

            case self::RULE_SOURCE_RAW: {
                $data = $action;
                self::$logger?->info("[addRule] RULE SOURCE RAW");
                $message = "Data for `{$rule_name}` fetched as RAW data";

                break;
            }

            // Пустой source — осознанный дефолт: правило-коллбэк.
            case self::RULE_SOURCE_CALLBACK:
            case self::RULE_SOURCE_UNDEFINED: {
                self::$logger?->info("[addRule] RULE SOURCE CALLBACK");
                [$actor, $params] = CacheHelper::compileCallbackHandler($action, self::$logger ?? new NullLogger());

                try {
                    $data = call_user_func_array($actor, $params);
                    $message = "Data for {$rule_name} fetched from callback";
                    self::$logger?->info("[addRule] Data fetched from callback");

                } catch (PDOException $e) {
                    self::$logger?->info("[addRule] Rule throws PDO Error", [ $e->getCode(), $e->getMessage() ]);
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

                break;
            }

            default: {
                $result->error("Rule `{$rule_name}` has unknown source `{$source}`");
                self::$logger?->error("[addRule] Unknown source `{$source}` for rule `{$rule_name}`");
                return $result;
            }
        }

        // кладем результат в репозиторий
        self::set($rule_name, $data);
        self::$logger?->info("[addRule] Pushed data to cache");

        if (self::isRedisConnected()) {
            // Единственная точка записи в Redis: атомарный SETEX/SET с проверкой результата.
            // Ошибка Redis не откатывает репозиторий, но и не маскируется под success.
            if (!self::redis()->push($rule_name, $data, $ttl)) {
                self::$logger?->error("[addRule] REDIS write failed, value kept in cache only", [
                    'key' => $rule_name,
                    'ttl' => $ttl,
                ]);
                $result->error("Rule `{$rule_name}` stored to cache, but REDIS write failed");
                return $result;
            }

            self::$logger?->info("[addRule] Pushed to REDIS", ['key' => $rule_name, 'ttl' => $ttl]);
            $message .= ", stored to cache, saved to redis, ";
            $message .= $ttl > 0 ? "TTL {$ttl} seconds" : "TTL unlimited";

        } else {
            $message .= ", stored to cache, redis disabled";
        }

        $result->success($message);

        return $result;
    }

    /**
     * Возвращает данные из репозитория кэша.
     *
     * @param string $key
     * @param mixed|null $default значение при отсутствии ключа
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null):mixed
    {
        if (self::check($key)) {
            self::$logger?->info("[get] Getting {$key} from cache");
            return self::$repository[ $key ];
        }
        return $default;
    }

    /**
     * Добавляет значение в репозиторий кэша, заменяя старое.
     *
     * @param string $key
     * @param mixed $data
     * @return void
     */
    public static function set(string $key, mixed $data): void
    {
        self::unset($key);
        self::$repository[ $key ] = $data;
        self::$logger?->info("[set] pushing `{$key}` data to cache");
    }


    /**
     * Удаляет значение из репозитория кэша.
     *
     * @param string $key
     * @return void
     */
    public static function unset(string $key):void
    {
        if (self::check($key)) {
            self::$logger?->info("[unset] removing {$key} from cache");
            unset(self::$repository[$key]);
        }
    }


    /**
     * Проверяет наличие данных в репозитории кэша.
     *
     * @param string $key
     * @return bool
     */
    public static function check(string $key): bool
    {
        $is_present = array_key_exists($key, self::$repository);
        self::$logger?->info("[check] checking key `{$key}` in cache", [ $is_present ]);
        return $is_present;
    }


    /**
     * Удаляет ключ из репозитория и Redis.
     *
     * Без подключённого Redis удаляется только из репозитория.
     * Допустима glob-маска: `article*`, `*rticl*`; маска `*` — все ключи.
     *
     * @param string $key
     * @param bool $redis_update
     * @return Result
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function drop(string $key, bool $redis_update = true): Result
    {
        $result = new Result();
        $dropped = self::dropFromRepository($key);
        $dropped_message = count($dropped) . " cache key(s) removed";

        if ($redis_update && self::isRedisConnected()) {
            self::$logger?->info("[drop] removing {$key} from REDIS");
            $deleted = self::redisDel($key);
            $result->success("Key `{$key}` dropped from cache, {$dropped_message}, " . count($deleted->raw_array) . " redis key(s) removed");
        } elseif ($redis_update) {
            $result->success("Key `{$key}` dropped from cache, {$dropped_message}, REDIS not connected");
        } else {
            $result->success("Key `{$key}` dropped from cache, {$dropped_message}, redis untouched");
        }

        return $result;
    }

    /**
     * Удаляет ключи из репозитория по имени или маске (glob: `*`, `?`, `[...]`).
     *
     * Маска трактуется так же, как в Redis: `drop('article*')` удаляет все
     * подходящие ключи, `drop('article')` — только точное совпадение.
     *
     * Полный перебор ключей выполняется только если в шаблоне есть glob-символы
     * (`*`, `?`, `[`); для точного имени используется прямое обращение по ключу.
     *
     * @param string $pattern
     * @return array Список удалённых ключей
     */
    private static function dropFromRepository(string $pattern): array
    {
        $dropped = [];

        // проверяем, содержит ли паттерн спецсимволы отвечающие за паттерн перебора
        // Если нет - это ключ с "прямым" именем, его и удаляем.
        if (strpbrk($pattern, '*?[') === false) {
            if (array_key_exists($pattern, self::$repository)) {
                unset(self::$repository[$pattern]);
                $dropped[] = $pattern;
            }
        } else {
            foreach (array_keys(self::$repository) as $repository_key) {
                if (!fnmatch($pattern, (string)$repository_key)) {
                    continue;
                }

                unset(self::$repository[$repository_key]);
                $dropped[] = $repository_key;
            }
        }

        if (!empty($dropped)) {
            self::$logger?->info("[drop] removed from cache repository", [ $dropped ]);
        }

        return $dropped;
    }

    /**
     * Удаляет все ключи из репозитория и Redis.
     *
     * @param bool $redis_update
     * @return Result
     * @throws RedisClientException
     * @throws RedisException
     */
    public static function dropAll(bool $redis_update = true): Result
    {
        $result = new Result();
        self::$logger?->info("[dropAll] clearing cache");
        self::$repository = [];

        if ($redis_update && self::isRedisConnected()) {
            self::$logger?->info("[dropAll] flushing REDIS database");
            self::$redis->flushDatabase();
            $result->success("Cache cleared, REDIS database flushed");
        } elseif ($redis_update) {
            $result->success("Cache cleared, REDIS not connected");
        } else {
            $result->success("Cache cleared, REDIS untouched");
        }

        return $result;
    }


    /**
     * Подключён ли Redis.
     *
     * Фасад не держит собственного флага соединения: состояние читается
     * у RedisClient, поэтому не может с ним разойтись.
     */
    public static function isRedisConnected(): bool
    {
        return self::$redis?->is_connected === true;
    }

    /**
     * Извлекает данные из Redis по ключу, декодируя JSON.
     *
     * @param string $key_name
     * @param bool $use_json_decode false — не декодировать
     * @return mixed
     * @throws RedisException
     * @throws RedisClientException
     * @throws JsonException
     */
    public static function redisFetch(string $key_name, bool $use_json_decode = true): mixed
    {
        return self::redis()->fetch($key_name, $use_json_decode);
    }

    /**
     * Добавляет значение в репозиторий кэша и, если Redis подключён, в Redis.
     *
     * Без подключённого Redis значение остаётся только в репозитории.
     * Запись в Redis атомарна (SETEX при `$ttl > 0`, иначе SET).
     *
     * @param string $key_name
     * @param mixed $data
     * @param int $ttl TTL только для Redis-копии; значение в репозитории не истекает
     * @param bool $use_json_encode
     * @return Result Ошибка записи в Redis не откатывает репозиторий, но возвращается как `Result::error`.
     * @throws JsonException
     * @throws RedisClientException
     * @throws RedisException
     */
    public static function push(string $key_name, mixed $data, int $ttl = 0, bool $use_json_encode = true): Result
    {
        $result = new Result();
        self::set($key_name, $data);

        // Redis не инициализирован: кэш работает, в Redis ничего не пишем.
        if (!self::isRedisConnected()) {
            $result->success("Pushed `{$key_name}` to cache only, REDIS not connected");
            return $result;
        }

        $pushed = self::redis()->push($key_name, $data, $ttl, $use_json_encode);

        if ($pushed) {
            $result->success("Pushed `{$key_name}` to cache and REDIS");
        } else {
            $result->error("Pushed `{$key_name}` to cache, but REDIS push failed");
        }

        return $result;
    }

    /* == REDIS ACCESS HELPERS == */

    /**
     * Кладёт данные в Redis (и только в Redis), JSON-кодируя их.
     *
     * При `$ttl = 0` ключ не истекает.
     *
     * @param string $key_name
     * @param mixed $data
     * @param int $ttl
     * @param bool $use_json_encode
     * @return Result `is_success` — удалось ли записать в Redis
     * @throws JsonException
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisPush(string $key_name, mixed $data, int $ttl = 0, bool $use_json_encode = true): Result
    {
        $pushed = self::redis()->push($key_name, $data, $ttl, $use_json_encode);

        $result = new Result();
        if ($pushed) {
            $result->success("Pushed `{$key_name}` to REDIS");
        } else {
            $result->error("Push `{$key_name}` to REDIS failed");
        }

        return $result;
    }

    /**
     * Удаляет данные в Redis по ключу; допустима маска.
     *
     * Список удалённых ключей — в `$result->raw_array`.
     *
     * @param string $key_name
     * @return Result
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisDel(string $key_name): Result
    {
        $result = new Result();
        $deleted = self::redis()->del($key_name);
        $result->raw_array = $deleted;

        if (empty($deleted)) {
            $result->success("No keys matched mask `{$key_name}`");
        } else {
            $result->success("Removed " . count($deleted) . " keys by mask `{$key_name}`: " . implode(', ', $deleted));
        }

        return $result;
    }

    /**
     * Проверяет существование ключа в Redis.
     *
     * @param string $key_name
     * @return bool
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisCheck(string $key_name): bool
    {
        return self::redis()->check($key_name);
    }

    /**
     * Создаёт (или переинициализирует) счётчик — целое число — в кэше и Redis
     * (если подключён). Счётчики создаются навечно: TTL не выставляется.
     *
     * @param string $key
     * @param int $initial
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function addCounter(string $key, int $initial = 0): int
    {
        self::$logger?->info("[addCounter] started");

        // Счётчики создаются навечно: TTL не выставляется, а Redis SET
        // заодно снимает возможный старый TTL с существующего ключа.
        if (self::isRedisConnected()) {
            if (!self::$redis->set($key, $initial)) {
                throw new RedisClientException("Failed to initialize counter `{$key}` in REDIS");
            }

            self::$repository[ $key ] = $initial;
            return $initial;
        }

        self::set($key, $initial);
        return $initial;
    }


    /**
     * Увеличивает счётчик в кэше и Redis (если подключён).
     *
     * @param string $key
     * @param int $diff
     * @return int
     * @throws RedisException
     * @throws RedisClientException если Redis не смог выполнить incrBy
     */
    public static function incrCounter(string $key, int $diff = 1): int
    {
        self::$logger?->info("[incrCounter] started");

        if (self::isRedisConnected()) {
            $value = self::$redis->incrBy($key, $diff);

            if ($value === false) {
                throw new RedisClientException("Failed to increment counter `{$key}` in REDIS");
            }

            $value = (int)$value;
            self::$repository[ $key ] = $value;
            return $value;
        }

        if (!array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }

        self::$repository[ $key ] += $diff;
        return self::$repository[ $key ];
    }


    /**
     * Уменьшает счётчик в кэше и Redis (если подключён).
     *
     * @param string $key
     * @param int $diff
     * @return int
     * @throws RedisException
     * @throws RedisClientException если Redis не смог выполнить decrBy
     */
    public static function decrCounter(string $key, int $diff = 1): int
    {
        self::$logger?->info("[decrCounter] started");

        if (self::isRedisConnected()) {
            $value = self::$redis->decrBy($key, $diff);

            if ($value === false) {
                throw new RedisClientException("Failed to decrement counter `{$key}` in REDIS");
            }

            $value = (int)$value;
            self::$repository[ $key ] = $value;
            return $value;
        }

        if (!array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }

        self::$repository[ $key ] -= $diff;
        return self::$repository[ $key ];
    }


    /**
     * Возвращает значение счётчика из Redis или кэша.
     *
     * Если Redis подключён и ключа там нет, возвращается `$default`,
     * а устаревшая копия в репозитории очищается.
     *
     * @param string $key
     * @param int $default
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function getCounter(string $key, int $default = 0): int
    {
        self::$logger?->info("[getCounter] started");

        // Redis — источник истины, когда подключён: отсутствие ключа
        // означает отсутствие счётчика, копия в репозитории очищается.
        if (self::isRedisConnected()) {
            $value = self::$redis->get($key);

            if ($value === false) {
                unset(self::$repository[ $key ]);
                return $default;
            }

            $value = (int)$value;
            self::$repository[ $key ] = $value;
            return $value;
        }

        return self::get($key, $default);
    }


    /**
     * Удаляет счётчик из репозитория и Redis (если подключён).
     *
     * Алиас `drop()` для одного ключа: счётчик — обычный ключ кэша.
     *
     * @param string $key
     * @return Result
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function delCounter(string $key): Result
    {
        return self::drop($key);
    }

    /**
     * Хелпер для работы с Redis.
     *
     * Предоставляет методы, аналогичные методам фасада:
     *
     * check() — аналог redisCheck()
     * fetch() — аналог redisFetch()
     * push() — аналог redisPush()
     * del() — аналог redisDel()
     * keys() — аналога нет
     *
     * @return RedisHelper
     * @throws RedisClientException если Redis не подключён (init с redis_enabled=true
     *                              не вызывался или соединение не установилось)
     */
    public static function redis(): RedisHelper
    {
        $redis = self::getConnector();

        return self::$redis_helper ??= new RedisHelper($redis, self::$logger ?? new NullLogger());
    }

    /**
     * Прямой коннектор к Redis.
     *
     * @throws RedisClientException Если Redis не подключён.
     */
    public static function getConnector(): RedisClient
    {
        $redis = self::$redis;

        if ($redis === null || !$redis->is_connected) {
            throw new RedisClientException("REDIS is not connected: call Cache::init() with redis_enabled=true");
        }

        return $redis;
    }
}