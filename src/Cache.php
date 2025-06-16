<?php

namespace Arris\Cache;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Arris\Cache\Exceptions\CacheDatabaseException;
use Arris\Entity\Result;
use Arris\Toolkit\RedisClientException;
use RedisException;

class Cache implements CacheInterface
{
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

    public static LoggerInterface $logger;
    public static bool $is_redis_connected = false;
    public static mixed $pdo;

    public static array $repository = [];

    public static \Arris\Toolkit\RedisClient $redis;


    public static function init(string $redis_host = self::REDIS_DEFAULT_HOST, int $redis_port = self::REDIS_DEFAULT_PORT, int $redis_database = self::REDIS_DEFAULT_DB, bool $redis_enabled = true, $PDO = null, ?LoggerInterface $logger = null)
    {
        self::$logger = is_null($logger) ? new NullLogger() : $logger;
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
        self::$logger->info("[init] NanoRedis instantiated");

        if ($options['enabled']) {
            try {
                self::$redis->connect();
                self::$is_redis_connected = true;
                self::$logger->info("[init] REDIS Connected");
            } catch (RedisClientException|RedisException $e) {
                self::$logger->info("Connection error", [ $e->getCode(), $e->getMessage(), $e->getTraceAsString() ]);
            }
        }

        if (!\is_null($PDO)) {
            $PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        RedisHelper::init(self::$redis, self::$is_redis_connected, self::$logger);
    }


    public static function addRule($rule_name, bool $enabled = true, string $source = '', $action = null, int $ttl = 0):Result
    {
        $result = new Result();
        self::$logger->info("[addRule] called for `{$rule_name}`");

        if (self::$is_redis_connected) {
            $rule_value = self::$redis->get($rule_name, false);

            if ($rule_value !== false) {
                self::set(
                    $rule_name,
                    \json_decode($rule_value, true, 512, JSON_THROW_ON_ERROR)
                );
                $result->success("Loaded `{$rule_name}` from redis, stored to cache");
                self::$logger->info("[addRule] Loaded `{$rule_name}` from REDIS, stored to cache");
                return $result;
            }
        }

        // Именно так: если данные есть в редис - их просто кладем в кэш
        if ($enabled === false) {
            $result->error("Rule `{$rule_name}` disabled");
            self::$logger->info("[addRule] Rule `{$rule_name}` disabled");
            return $result;
        }

        if (empty($action)) {
            $result->success("[ERROR] Key found, but action is empty");
            self::$logger->info("[addRule] Error: empty action, pushed null to cache");
            self::set($rule_name, null);
            return $result;
        }

        $data = null;

        switch ($source) {
            case self::RULE_SOURCE_SQL: {
                self::$logger->info("[addRule] RULE SOURCE SQL");

                // коннекта к БД нет: кладем в репозиторий null и продолжаем
                if (\is_null(self::$pdo)) {
                    $result->error("[ERROR] Key {$rule_name} not found, action is SQL, but PDO not connected");
                    self::$logger->info("[addRule] Error: key {$rule_name} not found, action is SQL, but PDO not connected");
                    self::set($rule_name, null);
                    return $result;
                }

                try {
                    $sth = self::$pdo->query($action);
                    $data = $sth->fetchAll();
                    $message = "Data for {$rule_name} fetched from DB";
                    self::$logger->info("[addRule] Data fetched from DB");

                } catch (\PDOException $e) {
                    self::$logger->info("[addRule] Rule throws PDO Error", [ $e->getCode(), $e->getMessage() ]);
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

                break;
            }

            case self::RULE_SOURCE_RAW: {
                $data = $action;
                self::$logger->info("[addRule] RULE SOURCE RAW");
                $message = "Data for `{$rule_name}` fetched as RAW data";

                break;
            }

            // case self::RULE_SOURCE_CALLBACK
            default: {
                self::$logger->info("[addRule] RULE SOURCE CALLBACK");
                [$actor, $params] = CacheHelper::compileCallbackHandler($action, self::$logger);

                try {
                    $data = \call_user_func_array($actor, $params);
                    $message = "Data for {$rule_name} fetched from callback";
                    self::$logger->info("[addRule] Data fetched from callback");

                } catch (\PDOException $e) {
                    self::$logger->info("[addRule] Rule throws PDO Error", [ $e->getCode(), $e->getMessage() ]);
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

                break;
            }
        }

        // кладем результат в репозиторий
        self::set($rule_name, $data);
        self::$logger->info("[addRule] Pushed data to cache");

        if (self::$is_redis_connected) {
            self::$redis->set($rule_name, CacheHelper::jsonize($data));
            self::$logger->info("[addRule] Pushed to redis");

            $message .= ", stored to cache, saved to redis, ";

            if ($ttl > 0) {
                self::$redis->expire($rule_name, $ttl);
                self::$logger->info("[addRule] Setting TTL {$ttl} seconds");
                $message .= "TTL {$ttl} seconds";
            } else {
                self::$logger->info("[addRule] TTL unlimited");
                $message .= "TTL unlimited";
            }

        } else {
            $message .= ", stored to cache, redis disabled";
        }

        $result->success($message);

        return $result;
    }

    public static function get(string $key, $default = null):mixed
    {
        if (self::check($key)) {
            self::$logger->info("[get] Getting {$key} from cache");
            return self::$repository[ $key ];
        }
        return $default;
    }

    public static function set(string $key, $data)
    {
        self::unset($key);
        self::$repository[ $key ] = $data;
        self::$logger->info("[set] pushing `{$key}` data to cache");
    }


    public static function unset(string $key):void
    {
        if (self::check($key)) {
            self::$logger->info("[unset] removing {$key} from cache");
            unset(self::$repository[$key]);
        }
    }


    public static function check(string $key): bool
    {
        $is_present = array_key_exists($key, self::$repository);
        self::$logger->info("[check] checking key `{$key}` in cache", [ $is_present ]);
        return $is_present;
    }


    public static function drop(string $key, bool $redis_update = true)
    {
        self::unset($key);
        if ($redis_update) {
            self::$logger->info("[drop] removing {$key} from REDIS");
            self::redisDel($key);
        }
    }

    public static function dropAll(bool $redis_update = true)
    {
        self::$logger->info("[dropAll] clearing cache");
        self::$repository = [];
        if ($redis_update) {
            self::$logger->info("[dropAll] flushing REDIS database");
            self::$redis->flushDatabase();
        }
    }


    public static function redisFetch(string $key_name, bool $use_json_decode = true): mixed
    {
        self::$logger->info("[redisFetch] started");

        if (self::$is_redis_connected === false) {
            self::$logger->info("[redisFetch] ERROR: REDIS not connected");
            return null;
        }

        $value = self::$redis->get($key_name, false);
        self::$logger->info("[redisFetch] Data from REDIS recieved");

        if ($use_json_decode && !empty($value)) {
            self::$logger->info("[redisFetch] Decoding JSON data");
            $value = \json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    public static function push(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true):bool
    {
        self::set($key_name, $data);
        return self::redis()::push($key_name, $data, $ttl, $use_json_encode);
    }

    /* == REDIS ONLY == */

    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): bool
    {
        self::$logger->info("[redisPush] started");

        if (self::$is_redis_connected === false) {
            self::$logger->info("[redisPush] ERROR: REDIS not connected");
            return false;
        }

        self::$redis->set($key_name, ($use_json_encode ? CacheHelper::jsonize($data) : $data));
        self::$logger->info("[redisPush] Data pushed to REDIS");

        if ($ttl > 0) {
            self::$redis->expire($key_name, $ttl);
            self::$logger->info("[redisFetch] TTL {$ttl} seconds");
        } else {
            self::$logger->info("[redisFetch] TTL unlimited");
        }

        if (self::$redis->exists($key_name)) {
            self::$logger->info("[redisFetch] Post-push check: SUCCESS");
            return true;
        }
        self::$logger->info("[redisFetch] Post-push check: ERROR");

        return false;
    }

    public static function redisDel(string $key_name): bool|array
    {
        self::$logger->info("[redisDel] started");

        if (self::$is_redis_connected === false) {
            return false;
        }

        $deleted = self::$redis->delete($key_name);
        ksort($deleted);

        return $deleted;
    }

    public static function redisCheck(string $key_name): bool
    {
        if (self::$is_redis_connected === false) {
            return false;
        }

        return self::$redis->exists($key_name);
    }

    public static function addCounter(string $key, int $initial = 0, int $ttl = 0): int
    {
        self::$logger->info("[addCounter] started");

        self::set($key, $initial);

        if (self::$is_redis_connected) {
            self::$redis->set($key, $initial);

            if ($ttl > 0) {
                self::$redis->expire($key, $ttl);
            }

            return self::$redis->get($key);
        }
        return $initial;
    }


    public static function incrCounter(string $key, int $diff = 1): int
    {
        self::$logger->info("[incrCounter] started");

        if (!\array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }

        if (self::$is_redis_connected) {
            self::$redis->incrBy($key, $diff);
        }

        self::$repository[ $key ] += $diff;
        return self::$repository[ $key ];
    }


    public static function decrCounter(string $key, int $diff = 1): int
    {
        self::$logger->info("[decrCounter] started");

        if (!\array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }

        if (self::$is_redis_connected) {
            self::$redis->decrBy($key, $diff);
        }

        self::$repository[ $key ] -= $diff;
        return self::$repository[ $key ];
    }


    public static function getCounter(string $key, int $default = 0): int
    {
        self::$logger->info("[getCounter] started");

        if (self::$is_redis_connected) {
            return self::$redis->get($key);
        }

        return self::get($key, $default);
    }

    public static function redis():RedisHelper
    {
        return new RedisHelper();
    }

    public static function getConnector(): \Arris\Toolkit\RedisClient
    {
        return self::$redis;
    }
}