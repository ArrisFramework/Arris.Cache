<?php

namespace Arris\Cache;

use Arris\Entity\Result;
use Arris\Toolkit\RedisClient;
use Arris\Toolkit\RedisClientException;
use JsonException;
use Psr\Log\LoggerInterface;
use RedisException;

/**
 * Практически, это враппер над редисом. Поэтому все действия с кэшем должны отражаться на редисе.
 *
 * Удаление ключа из кэша-репозитория = удалению ключа из редиса, потому что сам кэш это чисто отображение редиса на память
 * с фоллбэком из правил.
 *
 * Не знаю, нужны ли нам методы, удаляющие данные только из кэша. Скорее всего нет. Или только как кастомные методы в хэлпере.
 */
interface CacheInterface
{
    public const REDIS_DEFAULT_HOST = '127.0.0.1';
    public const REDIS_DEFAULT_PORT = 6379;
    public const REDIS_DEFAULT_DB   = 0;

    /**
     * Инициализирует кэш (репозиторий)
     *
     * @param string $redis_host
     * @param int $redis_port
     * @param int $redis_database
     * @param bool $redis_enabled
     * @param null $PDO
     * @param LoggerInterface|null $logger
     *
     * @throws RedisClientException
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
     * Добавляет ПРАВИЛО: значение в репозиторий - по ключу из редиса или значение из fallback-функции
     *
     * @param $rule_name - ключ
     * @param bool $enabled - включено ли правило
     * @param string $source - источник данных: RULE_SOURCE_SQL, RULE_SOURCE_CALLBACK, RULE_SOURCE_RAW
     * @param null $action - действие: SQL-запрос, коллбэк-функция или коллбэк-запись, сырое значение
     * @param int $ttl - время жизни значения в секундах, 0 - вечно
     *
     * @return Result
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
     * Добавляет значение в редис и репозиторий кэша
     *
     * @param string $key_name
     * @param $data
     * @param int $ttl
     * @param bool $use_json_encode
     * @return bool
     * @throws JsonException
     * @throws RedisClientException
     * @throws RedisException
     */
    public static function push(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true):bool;

    /**
     * Удаляет ключ из репозитория И редиса
     *
     * Допустимо указание маски в ключе:
     * `article*`, и даже `*rticl*`
     * Маска `*` означает, очевидно, все ключи.
     *
     * @param string $key
     * @param bool $redis_update
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function drop(string $key, bool $redis_update = true);

    /**
     * Удаляет все ключи из репозитория и редиса
     *
     * @throws RedisClientException
     * @throws RedisException
     */
    public static function dropAll(bool $redis_update = true);

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
     * Возвращает TRUE если удалось, FALSE - если редис отключен или данные в редисе "не оказались"
     *
     * @param string $key_name
     * @param $data
     * @param int $ttl
     * @param bool $use_json_encode
     * @return bool
     *
     * @throws JsonException
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true):bool;

    /**
     * Удаляет данные в редисе по ключу
     *
     * Допустимо удаление по маске
     *
     * Возвращает список ключей, которые попытались удалить
     *
     * @param string $key_name
     * @return array|bool
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function redisDel(string $key_name): bool|array;

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
     * Добавляет счетчик (целое число) в кэш и редис (если подключен)
     * Если TTL 0 - ключ не истекает
     *
     * @param string $key
     * @param int $initial
     * @param int $ttl
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function addCounter(string $key, int $initial = 0, int $ttl = 0):int;

    /**
     * Увеличивает счетчик в кэше и редисе (если подключен)
     *
     * @param string $key
     * @param int $diff
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function incrCounter(string $key, int $diff = 1):int;

    /**
     * Уменьшает счетчик в кэше и редисе (если подключен)
     *
     * @param string $key
     * @param int $diff
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function decrCounter(string $key, int $diff = 1):int;

    /**
     * Возвращает значение счетчика из редиса или кэша
     *
     * @param string $key
     * @param int $default
     * @return int
     * @throws RedisException
     * @throws RedisClientException
     */
    public static function getCounter(string $key, int $default = 0):int;

    /**
     * РЕДИС-хелпер
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
     */
    public static function redis(): RedisHelper;

    /**
     * Возвращает прямой коннектор к редис-клиенту
     *
     * @return RedisClient
     */
    public static function getConnector(): RedisClient;

}