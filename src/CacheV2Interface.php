<?php

namespace Arris\Cache;

use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Практически, это враппер над редисом. Поэтому все действия с кэшем должны отражаться на редисе.
 *
 * Удаление ключа из кэша-репозитория = удалению ключа из редиса, потому что сам кэш это чисто отображение редиса на память
 * с фоллбэком из правил.
 *
 * Не знаю, нужны ли нам методы, удаляющие данные только из кэша. Скорее всего нет. Или только как кастомные методы в хэлпере.
 */
interface CacheV2Interface
{
    public const REDIS_DEFAULT_HOST = '127.0.0.1';
    public const REDIS_DEFAULT_PORT = 6379;
    public const REDIS_DEFAULT_DB   = 0;

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

    /**
     * Инициализирует репозиторий
     *
     * @param string $redis_host
     * @param int $redis_port
     * @param int $redis_database
     * @param bool $redis_enabled
     * @param null $PDO
     * @param LoggerInterface|null $logger
     *
     * @return mixed
     */
    public static function init(
        string  $redis_host = self::REDIS_DEFAULT_HOST,
        int     $redis_port = self::REDIS_DEFAULT_PORT,
        int     $redis_database = self::REDIS_DEFAULT_DB,
        bool    $redis_enabled = true,
        $PDO = null,
        ?LoggerInterface $logger = null
    );

    /**
     * Добавляет в репозиторий правило
     *
     * @param $rule_name
     * @param bool $enabled
     * @param string $source
     * @param $action
     * @param int $ttl
     * @return mixed
     */
    public static function addRule($rule_name, bool $enabled = true, string $source = '', $action = null, int $ttl = 0);

    /**
     * Получает данные из репозитория
     *
     * @param string $key
     * @param $default
     * @return mixed
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
     * Удаляет ключ из репозитория и редиса
     *
     * Допустимо указание маски в ключе:
     * `ar*`, `ar*[*`, `ar*\[*` и даже `*ore*`
     * Маска `*` означает, очевидно, все ключи.
     *
     * @param string $key
     * @param bool $redis_update
     */
    public static function drop(string $key, bool $redis_update = true);

    /**
     * Удаляет все ключи из репозитория и редиса
     */
    public static function dropAll(bool $redis_update = true);

    /* ТОЛЬКО с редисом */

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
     * @param bool $jsonize
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