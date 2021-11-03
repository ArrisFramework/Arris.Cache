<?php

namespace Arris\Cache;

use JsonException;
use PDO;
use Psr\Log\LoggerInterface;

interface CacheInterface
{
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
     * @throws JsonException
     */

    public static function addRule($rule_name, $rule_definition);

    /**
     * Получает все ключи из репозитория
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
     */
    public static function flush($key);

    /**
     * Удаляет все ключи из репозитория и редиса
     */
    public static function flushAll();

}