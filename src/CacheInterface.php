<?php

namespace Arris\Cache;

use Arris\Entity\Result;
use Arris\Toolkit\RedisClient;
use Arris\Toolkit\RedisClientException;
use JsonException;
use Psr\Log\LoggerInterface;
use RedisException;

/**
 * Кэш = in-memory репозиторий + опциональный Redis.
 *
 * Redis — персистентный слой: при подключении значения читаются из него и
 * записываются в него; без подключения репозиторий работает самостоятельно.
 *
 * ВАЖНО: TTL (`$ttl` в addRule/push/redisPush) применяется только к ключам Redis.
 * Репозиторий — обычный массив без времени жизни; get() не обращается к Redis и
 * не проверяет TTL. После экспирации ключа в Redis копия в памяти процесса
 * остаётся до addRule()/drop()/dropAll()/init().
 */
interface CacheInterface
{
    public const REDIS_DEFAULT_HOST = '127.0.0.1';
    public const REDIS_DEFAULT_PORT = 6379;
    public const REDIS_DEFAULT_DB   = 0;

    /**
     * Инициализирует кэш (репозиторий).
     *
     * При каждом вызове очищает in-memory репозиторий; данные в Redis не трогаются.
     *
     * @param string $redis_host
     * @param int $redis_port
     * @param int $redis_database
     * @param bool $redis_enabled
     * @param null $PDO
     * @param LoggerInterface|null $logger
     * @param string|null $redis_password Пароль Redis (передаётся клиенту как `auth`; пустая строка = без пароля)
     *
     * @return Result
     * @throws RedisClientException
     */
    public static function init(
        string  $redis_host = self::REDIS_DEFAULT_HOST,
        int     $redis_port = self::REDIS_DEFAULT_PORT,
        int     $redis_database = self::REDIS_DEFAULT_DB,
        bool    $redis_enabled = true,
        $PDO = null,
        ?LoggerInterface $logger = null,
        ?string $redis_password = null
    ): Result;

    /**
     * Добавляет ПРАВИЛО: значение в репозиторий - по ключу из редиса или значение из fallback-функции
     *
     * @param $rule_name - ключ
     * @param bool $enabled - включено ли правило
     * @param string $source - источник данных: RULE_SOURCE_SQL, RULE_SOURCE_CALLBACK, RULE_SOURCE_RAW
     * @param null $action - действие: SQL-запрос, коллбэк-функция или коллбэк-запись, сырое значение
     * @param int $ttl - время жизни значения в Redis в секундах, 0 - вечно.
     *                   На in-memory репозиторий не действует: его копия не истекает.
     *
     * @return Result При подключённом Redis запись зеркалится атомарно (SETEX/SET);
     *                если Redis отклонил запись, возвращается ошибка (значение
     *                при этом остаётся в репозитории).
     * @throws JsonException
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function addRule($rule_name, bool $enabled = true, string $source = '', $action = null, int $ttl = 0):Result;

    /**
     * Получает данные из кэша
     *
     * @param string $key
     * @param $default
     * @return mixed
     */
    public static function get(string $key, $default = null): mixed;

    /**
     * Добавляет значение в репозиторий кэша, заменяет старое
     *
     * @param string $key
     * @param $data
     */
    public static function set(string $key, $data);

    /**
     * Проверяет наличие данных в репозитории кеша
     *
     * @param string $key
     * @return bool
     */
    public static function check(string $key): bool;

    /**
     * Удаляем значение из репозитория кэша
     *
     * @param string $key
     * @return void
     */
    public static function unset(string $key):void;

    /**
     * Добавляет значение в репозиторий кэша и, если Redis подключён, в Redis.
     * Без подключённого Redis значение остаётся только в репозитории.
     *
     * @param string $key_name
     * @param $data
     * @param int $ttl - TTL только для Redis-копии; значение в репозитории не истекает
     * @param bool $use_json_encode
     * @return Result
     * @throws JsonException
     * @throws RedisClientException
     * @throws RedisException
     */
    public static function push(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): Result;

    /**
     * Удаляет ключ из репозитория И редиса.
     * Без подключённого Redis удаляется только из репозитория.
     *
     * Допустимо указание маски в ключе:
     * `article*`, и даже `*rticl*`
     * Маска `*` означает, очевидно, все ключи.
     *
     * @param string $key
     * @param bool $redis_update
     * @return Result
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function drop(string $key, bool $redis_update = true): Result;

    /**
     * Удаляет все ключи из репозитория и редиса
     *
     * @param bool $redis_update
     * @return Result
     * @throws RedisClientException
     * @throws RedisException
     */
    public static function dropAll(bool $redis_update = true): Result;

    /* === РЕДИС ===  */

    /**
     *  Извлекает данные из редиса по ключу, декодируя JSON
     *
     * @param string $key_name
     * @param bool $use_json_decode - false - не декодируем
     * @return bool|mixed|string|null
     * @throws RedisException
     * @throws RedisClientException
     * @throws \JsonException
     */
    public static function redisFetch(string $key_name, bool $use_json_decode = true): mixed;

    /**
     * Кладёт данные в редис (и только в редис), обязательно json-изируя их.
     *
     * Если TTL 0 - ключ не истекает
     *
     * Возвращает Result: is_success - удалось ли записать в редис
     *
     * @param string $key_name
     * @param $data
     * @param int $ttl
     * @param bool $use_json_encode
     * @return Result
     *
     * @throws JsonException
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): Result;

    /**
     * Удаляет данные в редисе по ключу
     *
     * Допустимо удаление по маске
     *
     * Возвращает Result, в `raw_array` — список удалённых ключей
     *
     * @param string $key_name
     * @return Result
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisDel(string $key_name): Result;

    /**
     * Проверяет существование ключа в редисе
     *
     * @param string $key_name
     * @return bool
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisCheck(string $key_name):bool;

    /* === СЧЕТЧИКИ === */

    /**
     * Создаёт (или переинициализирует) счетчик — целое число — в кэше и редисе
     * (если подключен). Счётчики создаются навечно: TTL не выставляется.
     *
     * @param string $key
     * @param int $initial
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function addCounter(string $key, int $initial = 0):int;

    /**
     * Увеличивает счетчик в кэше и редисе (если подключен).
     * Бросает исключение, если Redis не смог выполнить incrBy.
     *
     * @param string $key
     * @param int $diff
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function incrCounter(string $key, int $diff = 1):int;

    /**
     * Уменьшает счетчик в кэше и редисе (если подключен).
     * Бросает исключение, если Redis не смог выполнить decrBy.
     *
     * @param string $key
     * @param int $diff
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function decrCounter(string $key, int $diff = 1):int;

    /**
     * Возвращает значение счетчика из редиса или кэша.
     * Если Redis подключён и ключа там нет, возвращается $default,
     * а устаревшая копия в репозитории очищается.
     *
     * @param string $key
     * @param int $default
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function getCounter(string $key, int $default = 0):int;

    /**
     * Удаляет счетчик из репозитория и Redis (если подключён).
     * Алиас drop() для одного ключа; счётчик — обычный ключ кэша.
     *
     * @param string $key
     * @return Result
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function delCounter(string $key): Result;

    /**
     * Подключён ли Redis.
     *
     * Единственный публичный доступ к состоянию соединения: фасад не держит
     * собственного флага, а читает его у RedisClient.
     *
     * @return bool
     */
    public static function isRedisConnected(): bool;

    /**
     * Хелпер для работы с Redis.
     *
     * Предоставляет методы, полные аналоги соответствующих методов из основного класса:
     *
     * Cache::redis()->fetch()
     *
     * check() - аналог redisCheck()
     * fetch() - аналог redisFetch()
     * push() - аналог redisPush()
     * del() - аналог redisDel()
     * keys() - аналога нет
     *
     * @return RedisHelper
     * @throws RedisClientException если Redis не подключён (init с redis_enabled=true не вызывался
     *                                или соединение не установилось).
     */
    public static function redis(): RedisHelper;

    /**
     * Возвращает прямой коннектор к редис-клиенту
     *
     * @return RedisClient
     * @throws RedisClientException если Redis не подключён
     */
    public static function getConnector(): RedisClient;

}