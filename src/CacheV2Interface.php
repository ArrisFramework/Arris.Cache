<?php

namespace Arris\Cache;

use Arris\Entity\Result;
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


    public static function init(
        string  $redis_host = self::REDIS_DEFAULT_HOST,
        int     $redis_port = self::REDIS_DEFAULT_PORT,
        int     $redis_database = self::REDIS_DEFAULT_DB,
        bool    $redis_enabled = true,
        $PDO = null,
        ?LoggerInterface $logger = null
    );


    public static function addRule($rule_name, bool $enabled = true, string $source = '', $action = null, int $ttl = 0):Result;

    /**
     * Получает данные из репозитория
     *
     * @param string $key
     * @param $default
     * @return mixed
     */
    public static function get(string $key, $default = null);

    public static function set(string $key, $data);

    public static function check(string $key): bool;


    public static function drop(string $key, bool $redis_update = true);


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


    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true):bool;


    public static function redisDel(string $key_name);


    public static function redisCheck(string $key_name):bool;


    public static function addCounter(string $key, int $initial = 0, int $ttl = 0):int;


    public static function incrCounter(string $key, int $diff = 1):int;


    public static function decrCounter(string $key, int $diff = 1):int;


    public static function getCounter(string $key, int $default = 0):int;


}