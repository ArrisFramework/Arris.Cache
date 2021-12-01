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
    
    /**
     *
     * @param array<string, int> $credentials
     * @param array<array> $rules
     * @param PDO|null $PDO
     * @param LoggerInterface|null $logger
     * @throws JsonException
     */
    public static function init($credentials = [], $rules = [], PDO $PDO = null, LoggerInterface $logger = null);

    /**
     * Добавляет в репозиторий новое ключ-значение (с логгированием)
     *
     * @param $rule_name
     * @param $rule_definition
     * @return string
     * @throws JsonException
     */
    public static function addRule($rule_name, $rule_definition):string;

    /**
     * Получает список ключей из репозитория кэша
     * (имён ключей)
     *
     * @return array
     */
    public static function getAllKeys();

    /**
     * Получает значение из репозитория
     *
     * @param $key
     * @param null $default
     * @return mixed|null
     */
    public static function get($key, $default = null);

    /**
     * Добавляет значение в репозиторий, удаляя старое
     *
     * @param $key
     * @param $data
     */
    public static function set($key, $data);

    /**
     * Проверяет наличие ключа в репозитории
     *
     * @param $key
     * @return bool
     */
    public static function check($key);

    /**
     * Удаляет ключ из репозитория
     *
     * @param $key
     */
    public static function unset($key);
    
    /**
     * Удаляет ключ из репозитория и редиса
     *
     * @param $key
     * @param bool $clean_redis
     */
    public static function flush($key, bool $clean_redis = true);

    /**
     * Удаляет все ключи из репозитория и редиса
     */
    public static function flushAll();
    
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
     * @return RedisClient|false
     */
    public static function getConnector():RedisClient;
    
    /**
     * Извлекает данные из редиса по ключу. Если передан второй аргумент false - не проводит json_decode
     *
     * @param $key_name
     * @param bool $use_json_decode
     * @throws JsonException
     * @return mixed
     */
    public static function redisFetch($key_name, $use_json_decode = true);
    
    /**
     * Кладёт данные в редис (и только в редис), обязательно json-изируя их.
     *
     * Возвращает TRUE если удалось, FALSE - если редис отключен или данные в редисе "не оказались"
     *
     * @param $key_name
     * @param $data
     * @param int $ttl
     * @return bool
     * @throws JsonException
     */
    public static function redisPush($key_name, $data, $ttl = 0):bool;
    
    /**
     * Удаляет данные в редисе по ключу
     *
     * @param $key_name
     * @return bool
     */
    public static function redisDel($key_name):bool;
    
    /**
     * Добавляет счетчик (целое число) в кэш и редис (если подключен)
     *
     * @param $key
     * @param int $initial
     * @return int
     */
    public static function addCounter(string $key, int $initial = 0):int;
    
    /**
     * Увеличивает счетчик в кэше и редисе (если подключен)
     *
     * @param $key
     * @param int $diff
     * @return int
     */
    public static function incrCounter(string $key, int $diff = 1):int;
    
    /**
     * Уменьшает счетчик в кэше и редисе (если подключен)
     *
     * @param $key
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
    public static function getCounter(string $key, $default = 0):int;
}