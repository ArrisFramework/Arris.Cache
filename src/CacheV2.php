<?php

namespace Arris\Cache;

use Arris\Cache\Exceptions\CacheDatabaseException;
use Arris\Cache\Exceptions\RedisClientException;
use Arris\Entity\Result;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RedisException;

class CacheV2 implements CacheV2Interface
{
    public static LoggerInterface $logger;
    public static bool $is_redis_connected = false;
    public static mixed $pdo;

    public static \Arris\Toolkit\RedisClient $redis;

    /**
     * @throws RedisException|\Arris\Toolkit\RedisClientException
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
            } catch (RedisClientException $e){
            }
        }

        if (!\is_null($PDO)) {
            $PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    /**
     * @throws \Arris\Toolkit\RedisClientException
     * @throws RedisException
     * @throws \JsonException
     */
    public static function addRule($rule_name, bool $enabled = true, string $source = '', $action = null, int $ttl = 0)
    {
        $message = '';
        $result = new Result();

        if (self::$is_redis_connected) {
            $rule_value = self::$redis->get($rule_name, false);

            if ($rule_value !== false) {
                self::set(
                    $rule_name,
                    \json_decode($rule_value, true, 512, JSON_THROW_ON_ERROR),
                    false
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
            self::set($rule_name, null, false);
            return $message;
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

    public static function set(string $key, $data)
    {
        // TODO: Implement set() method.
    }

    public static function check(string $key): bool
    {
        // TODO: Implement check() method.
    }

    public static function drop(string $key, bool $redis_update = true)
    {
        // TODO: Implement drop() method.
    }

    public static function dropAll(bool $redis_update = true)
    {
        // TODO: Implement dropAll() method.
    }

    public static function redisFetch(string $key_name, bool $use_json_decode = true)
    {
        // TODO: Implement redisFetch() method.
    }

    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $jsonize = true): bool
    {
        // TODO: Implement redisPush() method.
    }

    public static function redisDel(string $key_name)
    {
        // TODO: Implement redisDel() method.
    }

    public static function redisCheck(string $keyname): bool
    {
        // TODO: Implement redisCheck() method.
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