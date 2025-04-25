<?php

namespace Arris\Cache;

use Arris\Cache\Exceptions\CacheDatabaseException;
use Arris\Cache\Exceptions\RedisClientException;
use Arris\Entity\Result;
use JsonException;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RedisException;

class CacheV2 implements CacheV2Interface
{
    public static LoggerInterface $logger;
    public static bool $is_redis_connected = false;
    public static mixed $pdo;

    public static array $repository = [];

    public static \Arris\Toolkit\RedisClient $redis;

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
     * @throws \Arris\Toolkit\RedisClientException
     */
    public static function init(string $redis_host = self::REDIS_DEFAULT_HOST, int $redis_port = self::REDIS_DEFAULT_PORT, int $redis_database = self::REDIS_DEFAULT_DB, bool $redis_enabled = true, $PDO = null, ?LoggerInterface $logger = null)
    {
        self::$logger = \is_null($logger) ? new NullLogger() : $logger;
        self::$is_redis_connected = false;
        self::$pdo = $PDO;

        $options = CacheHelper::overrideDefaults([
            'enabled'   =>  false,
            'host'      =>  self::REDIS_DEFAULT_HOST,
            'port'      =>  self::REDIS_DEFAULT_PORT,
            'timeout'   =>  null,
            'persistent'=>  '',
            'database'  =>  self::REDIS_DEFAULT_DB,
            'password'  =>  ''
        ], [
            'enabled'   =>  $redis_enabled,
            'host'      =>  $redis_host,
            'port'      =>  $redis_port,
            'database'  =>  $redis_database
        ]);

        self::$redis = new \Arris\Toolkit\RedisClient(
            host: $options['host'],
            port: $options['port'],
            database: $options['database'],
            enabled: $options['enabled']
        );

        if ($options['enabled']) {
            try {
                self::$redis->connect();
                self::$is_redis_connected = true;
            } catch (\Arris\Toolkit\RedisClientException|RedisException $e) {
            }
        }

        if (!\is_null($PDO)) {
            $PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }


    public static function addRule($rule_name, bool $enabled = true, string $source = '', $action = null, int $ttl = 0):Result
    {
        $message = '';
        $result = new Result();

        if (self::$is_redis_connected) {
            $rule_value = self::$redis->get($rule_name, false);

            if ($rule_value !== false) {
                self::set(
                    $rule_name,
                    \json_decode($rule_value, true, 512, JSON_THROW_ON_ERROR)
                );
                $result->success("[INFO] Loaded `{$rule_name}` from redis, stored to cache");
                return $result;
            }
        }

        if ($enabled === false) {
            $result->error("Rule `{$rule_name}` disabled");
            return $result;
        }

        if (empty($action)) {
            $result->success("[ERROR] Key found, but action is empty");
            self::set($rule_name, null);
            return $result;
        }

        $data = null;

        switch ($source) {
            case self::RULE_SOURCE_SQL: {

                // коннекта к БД нет: кладем в репозиторий null и продолжаем
                if (\is_null(self::$pdo)) {
                    $result->error("[ERROR] Key {$rule_name} not found, action is SQL, but PDO not connected");
                    self::set($rule_name, null);
                    return $result;
                }

                try {
                    $sth = self::$pdo->query($action);
                    $data = $sth->fetchAll();
                    $message = "Data for {$rule_name} fetched from DB";

                } catch (\PDOException $e) {
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

                break;
            }

            case self::RULE_SOURCE_RAW: {
                $data = $action;
                $message = "Data for `{$rule_name}` fetched as RAW data";

                break;
            }

            // case self::RULE_SOURCE_CALLBACK
            default: {
                [$actor, $params] = CacheHelper::compileCallbackHandler($action, self::$logger);

                try {
                    $data = \call_user_func_array($actor, $params);
                    $message = "Data for {$rule_name} fetched from callback {$actor}";

                } catch (\PDOException $e) {
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

                break;
            }
        }

        // кладем результат в репозиторий
        self::set($rule_name, $data);

        if (self::$is_redis_connected) {
            self::$redis->set($rule_name, CacheHelper::jsonize($data));

            $message .= ", stored to cache, saved to redis, ";

            if ($ttl > 0) {
                self::$redis->expire($rule_name, $ttl);
                $message .= "TTL {$ttl} seconds";
            } else {
                $message .= "TTL unlimited";
            }

        } else {
            $message .= ", stored to cache, redis disabled";
        }

        $result->success($message);

        return $result;
    }

    public static function get(string $key, $default = null)
    {
        // TODO: Implement get() method.
    }

    /**
     * Добавляет значение в репозиторий, заменяет старое
     *
     * @param string $key
     * @param $data
     */
    public static function set(string $key, $data)
    {
        self::unset($key);
        self::$repository[ $key ] = $data;
    }

    /**
     * Приватный метод удаления ключа из репозитория
     *
     * @param string $key
     * @return void
     */
    private static function unset(string $key):void
    {
        if (self::check($key)) {
            unset(self::$repository[$key]);
        }
    }

    /**
     * Проверяет наличие ключа в репозитории
     *
     * @param string $key
     * @return bool
     */
    public static function check(string $key): bool
    {
        return array_key_exists($key, self::$repository);
    }

    /**
     * Удаляет ключ из репозитория и редиса
     *
     * Допустимо указание маски в ключе:
     * `article*`, и даже `*rticl*`
     * Маска `*` означает, очевидно, все ключи.
     *
     * @param string $key
     * @param bool $redis_update
     * @throws RedisException
     * @throws \Arris\Toolkit\RedisClientException
     */
    public static function drop(string $key, bool $redis_update = true)
    {
        self::unset($key);
        if ($redis_update) {
            self::redisDel($key);
        }
    }

    public static function dropAll(bool $redis_update = true)
    {
        // TODO: Implement dropAll() method.
    }

    /**
     *  Извлекает данные из редиса по ключу, декодируя JSON
     *
     * @param string $key_name
     * @param bool $use_json_decode
     * @return bool|mixed|string|null
     * @throws RedisException
     * @throws \Arris\Toolkit\RedisClientException
     * @throws \JsonException
     */
    public static function redisFetch(string $key_name, bool $use_json_decode = true)
    {
        if (self::$is_redis_connected === false) {
            return null;
        }

        $value = self::$redis->get($key_name, false);

        if ($use_json_decode && !empty($value)) {
            $value = \json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

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
     * @throws \Arris\Toolkit\RedisClientException
     */
    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): bool
    {
        if (self::$is_redis_connected === false) {
            return false;
        }

        self::$redis->set($key_name, ($use_json_encode ? CacheHelper::jsonize($data) : $data));

        if ($ttl > 0) {
            self::$redis->expire($key_name, $ttl);
        }

        if (self::$redis->exists($key_name)) {
            return true;
        }

        return false;
    }

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
     * @throws \Arris\Toolkit\RedisClientException
     */
    public static function redisDel(string $key_name): bool|array
    {
        if (self::$is_redis_connected === false) {
            return false;
        }

        $deleted = self::$redis->delete($key_name);
        ksort($deleted);

        return $deleted;
    }

    /**
     * Проверяет существование ключа в редисе
     *
     * @param string $key_name
     * @return bool
     * @throws RedisException
     * @throws \Arris\Toolkit\RedisClientException
     */
    public static function redisCheck(string $key_name): bool
    {
        if (self::$is_redis_connected === false) {
            return false;
        }

        return self::$redis->exists($key_name);
    }

    public static function addCounter(string $key, int $initial = 0, int $ttl = 0): int
    {
        // TODO: Implement addCounter() method.
    }

    public static function incrCounter(string $key, int $diff = 1): int
    {
        // TODO: Implement incrCounter() method.
    }

    public static function decrCounter(string $key, int $diff = 1): int
    {
        // TODO: Implement decrCounter() method.
    }

    public static function getCounter(string $key, int $default = 0): int
    {
        // TODO: Implement getCounter() method.
    }
}