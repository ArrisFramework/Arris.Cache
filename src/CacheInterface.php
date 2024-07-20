<?php

namespace Arris\Cache;

use JsonException;
use PDO;
use Psr\Log\LoggerInterface;

interface CacheInterface
{
    public const RULE_SOURCE_SQL = 'sql';
    public const RULE_SOURCE_CALLBACK = 'callback';
    public const RULE_SOURCE_RAW = 'raw';
    public const RULE_SOURCE_UNDEFINED = '';
    
    public const TIME_SECOND    = 1;
    public const TIME_MINUTE    = self::TIME_SECOND * 60;
    public const TIME_HOUR      = self::TIME_MINUTE * 60;
    public const TIME_DAY       = self::TIME_HOUR * 12;
    
    public const TIME_FULL_DAY  = self::TIME_HOUR * 24;
    public const TIME_MONTH     = self::TIME_FULL_DAY * 30;
    public const TIME_YEAR      = self::TIME_MONTH * 12;
    
    public const REDIS_DEFAULT_HOST     = '127.0.0.1';
    public const REDIS_DEFAULT_PORT     = 6379;
    public const REDIS_DEFAULT_DB       = 0;
    public const REDIS_DEFAULT_PASSWORD = null;
    
    /**
     *
     * @param array<string, int> $credentials
     * @param array<array> $rules
     * @param $PDO
     * @param LoggerInterface|null $logger
     * @throws JsonException
     */
    public static function init(array $credentials = [], array $rules = [], $PDO = null, ?LoggerInterface $logger = null);

    /**
     * Добавляет в репозиторий массив правил
     *
     * @param array $rules
     * @return void
     * @throws JsonException
     */
    public static function addRules(array $rules);

    /**
     * Добавляет в репозиторий новое ключ-значение (с логгированием)
     *
     * @param string $rule_name
     * @param $rule_definition
     * @return string
     * @throws JsonException
     */
    public static function addRule(string $rule_name, $rule_definition):string;

    /**
     * Получает список ключей из репозитория кэша
     * (имён ключей)
     *
     * @param bool $use_keys_from_redis
     * @return array
     */
    public static function getAllKeys(bool $use_keys_from_redis): array;

    /**
     * Получает значение из репозитория
     *
     * @param $key
     * @param null $default
     * @return mixed|null
     */
    public static function get(string $key, $default = null);

    /**
     * Добавляет значение в репозиторий, удаляя старое
     *
     * @param string $key
     * @param $data
     */
    public static function set(string $key, $data);

    /**
     * Проверяет наличие ключа в репозитории
     *
     * @param string $key
     * @return bool
     */
    public static function check(string $key): bool;

    /**
     * Удаляет ключ из репозитория
     *
     * @param string $key
     */
    public static function unset(string $key);
    
    /**
     * Удаляет ключ из репозитория и редиса
     * Допустимо указание маски в ключе:
     * `ar*`, `ar*[*`, `ar*\[*` и даже `*ore*`
     * Маска `*` означает, очевидно, все ключи.
     *
     * @param string $key
     * @param bool $clean_redis
     * @return string
     */
    public static function flush(string $key, bool $clean_redis = true):string;

    /**
     * Удаляет все ключи из репозитория и редиса
     */
    public static function flushAll(string $mask = '*');
    
    /**
     * Возвращает статус подключения к редису.
     *
     * @return bool
     */
    public static function isRedisConnected():bool;
    
    /**
     * Возвращает redis коннектор. Метод НЕ проверят, состоялось ли подключение к редису, то есть
     * вызывать его ОБЯЗАТЕЛЬНО после проверки isRedisConnected().
     *
     * В противном случае весьма вероятны эксепшены вида: call method on null или call undefined method
     *
     * Метод может вернуть NULL если коннект не установлен
     *
     * @return RedisClient|false|null
     */
    public static function getConnector();

    /**
     * Извлекает данные из редиса по ключу. Если передан второй аргумент false - не проводит json_decode
     *
     * @param string $key_name
     * @param bool $use_json_decode
     * @return mixed
     * @throws JsonException
     */
    public static function redisFetch(string $key_name, bool $use_json_decode = true);

    /**
     * Кладёт данные в редис (и только в редис), обязательно json-изируя их.
     *
     * Если TTL 0 - ключ не истекает
     *
     * Возвращает TRUE если удалось, FALSE - если редис отключен или данные в редисе "не оказались"
     *
     * @param string $key_name
     * @param $data
     * @param int $ttl
     * @return bool
     * @throws JsonException
     */
    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $jsonize = true):bool;
    
    /**
     * Удаляет данные в редисе по ключу
     *
     * Допустимо удаление по маске
     *
     * Возвращает список ключей, которые попытались удалить
     *
     * @param string $key_name
     * @return array|bool
     */
    public static function redisDel(string $key_name);
    
    /**
     * Проверяет существование ключа в редисе
     *
     * @param string $keyname
     * @return bool
     */
    public static function redisCheck(string $keyname):bool;
    
    /**
     * Добавляет счетчик (целое число) в кэш и редис (если подключен)
     * Если TTL 0 - ключ не истекает
     *
     * @param string $key
     * @param int $initial
     * @param int $ttl
     * @return int
     */
    public static function addCounter(string $key, int $initial = 0, int $ttl = 0):int;

    /**
     * Увеличивает счетчик в кэше и редисе (если подключен)
     *
     * @param string $key
     * @param int $diff
     * @return int
     */
    public static function incrCounter(string $key, int $diff = 1):int;

    /**
     * Уменьшает счетчик в кэше и редисе (если подключен)
     *
     * @param string $key
     * @param int $diff
     * @return int
     */
    public static function decrCounter(string $key, int $diff = 1):int;
    
    /**
     * Возвращает значение счетчика из редиса или кэша
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    public static function getCounter(string $key, int $default = 0):int;


}