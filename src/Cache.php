<?php

namespace Arris\Cache;

use Arris\Toolkit\RedisClient;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Arris\Cache\Exceptions\CacheDatabaseException;
use Arris\Entity\Result;
use Arris\Toolkit\RedisClientException;
use RedisException;

use function array_key_exists;
use function call_user_func_array;
use function count;
use function implode;
use function is_null;
use function json_decode;

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

    public static RedisClient $redis;

    private static RedisHelper $redis_helper;


    public static function init(string $redis_host = self::REDIS_DEFAULT_HOST, int $redis_port = self::REDIS_DEFAULT_PORT, int $redis_database = self::REDIS_DEFAULT_DB, bool $redis_enabled = true, $PDO = null, ?LoggerInterface $logger = null): Result
    {
        $result = new Result();

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

        self::$redis = new RedisClient(
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
                $result->success("REDIS Connected");
            } catch (RedisClientException|RedisException $e) {
                self::$logger->info("Connection error", [ $e->getCode(), $e->getMessage(), $e->getTraceAsString() ]);
                $result->error("REDIS connection error: " . $e->getMessage(), (int)$e->getCode());
            }
        } else {
            $result->success("Redis disabled");
        }

        if (!is_null($PDO)) {
            $PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        self::$redis_helper = new RedisHelper(self::$redis, self::$is_redis_connected, self::$logger);

        return $result;
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
                    json_decode($rule_value, true, 512, JSON_THROW_ON_ERROR)
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

        if (is_null($action)) {
            $result->success("[ERROR] Key found, but action is empty");
            self::$logger->info("[addRule] Error: empty action, pushed null to cache");
            self::set($rule_name, null);
            return $result;
        }

        $data = null;

        switch ($source) {
            case self::RULE_SOURCE_SQL: {
                self::$logger->info("[addRule] RULE SOURCE SQL");

                // Коннекта к БД нет: кладем в репозиторий null и продолжаем
                if (is_null(self::$pdo)) {
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

                } catch (PDOException $e) {
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
                    $data = call_user_func_array($actor, $params);
                    $message = "Data for {$rule_name} fetched from callback";
                    self::$logger->info("[addRule] Data fetched from callback");

                } catch (PDOException $e) {
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

    public static function set(string $key, $data): void
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


    public static function drop(string $key, bool $redis_update = true): Result
    {
        $result = new Result();
        self::unset($key);

        if ($redis_update) {
            self::$logger->info("[drop] removing {$key} from REDIS");
            $deleted = self::redisDel($key);

            if ($deleted->is_success) {
                $result->success("Key `{$key}` dropped from cache, " . count($deleted->raw_array) . " redis key(s) removed");
            } else {
                $result->error("Key `{$key}` dropped from cache, but redis removal failed: " . $deleted->getMessage());
            }
        } else {
            $result->success("Key `{$key}` dropped from cache, redis untouched");
        }

        return $result;
    }

    public static function dropAll(bool $redis_update = true): Result
    {
        $result = new Result();
        self::$logger->info("[dropAll] clearing cache");
        self::$repository = [];

        if ($redis_update && self::$is_redis_connected) {
            self::$logger->info("[dropAll] flushing REDIS database");
            self::$redis->flushDatabase();
            $result->success("Cache cleared, REDIS database flushed");
        } elseif ($redis_update) {
            $result->success("Cache cleared, REDIS not connected");
        } else {
            $result->success("Cache cleared, REDIS untouched");
        }

        return $result;
    }


    public static function redisFetch(string $key_name, bool $use_json_decode = true): mixed
    {
        return self::redis()->fetch($key_name, $use_json_decode);
    }

    public static function push(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): Result
    {
        $result = new Result();
        self::set($key_name, $data);

        $pushed = self::redis()->push($key_name, $data, $ttl, $use_json_encode);

        if ($pushed) {
            $result->success("Pushed `{$key_name}` to cache and REDIS");
        } else {
            $result->error("Pushed `{$key_name}` to cache, but REDIS push failed");
        }

        return $result;
    }

    /* == REDIS ACCESS HELPERS == */

    public static function redisPush(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): Result
    {
        $pushed = self::redis()->push($key_name, $data, $ttl, $use_json_encode);

        $result = new Result();
        if ($pushed) {
            $result->success("Pushed `{$key_name}` to REDIS");
        } else {
            $result->error("Push `{$key_name}` to REDIS failed");
        }

        return $result;
    }

    public static function redisDel(string $key_name): Result
    {
        $result = new Result();

        if (!self::$is_redis_connected) {
            $result->error("REDIS not connected");
            return $result;
        }

        $deleted = self::redis()->del($key_name);
        $result->raw_array = $deleted;

        if (empty($deleted)) {
            $result->success("No keys matched mask `{$key_name}`");
        } else {
            $result->success("Removed " . count($deleted) . " keys by mask `{$key_name}`: " . implode(', ', $deleted));
        }

        return $result;
    }

    public static function redisCheck(string $key_name): bool
    {
        return self::redis()->check($key_name);
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

            return (int)self::$redis->get($key);
        }
        return $initial;
    }


    public static function incrCounter(string $key, int $diff = 1): int
    {
        self::$logger->info("[incrCounter] started");

        if (!array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }

        if (self::$is_redis_connected) {
            $value = (int)self::$redis->incrBy($key, $diff);
            self::$repository[ $key ] = $value;
            return $value;
        }

        self::$repository[ $key ] += $diff;
        return self::$repository[ $key ];
    }


    public static function decrCounter(string $key, int $diff = 1): int
    {
        self::$logger->info("[decrCounter] started");

        if (!array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }

        if (self::$is_redis_connected) {
            $value = (int)self::$redis->decrBy($key, $diff);
            self::$repository[ $key ] = $value;
            return $value;
        }

        self::$repository[ $key ] -= $diff;
        return self::$repository[ $key ];
    }


    public static function getCounter(string $key, int $default = 0): int
    {
        self::$logger->info("[getCounter] started");

        if (self::$is_redis_connected) {
            $value = self::$redis->get($key);

            if ($value !== false) {
                $value = (int)$value;
                self::$repository[ $key ] = $value;
                return $value;
            }
        }

        return self::get($key, $default);
    }

    public static function redis():RedisHelper
    {
        // return new RedisHelper(self::$redis, self::$is_redis_connected, self::$logger);
        return self::$redis_helper;
    }

    public static function getConnector(): RedisClient
    {
        return self::$redis;
    }
}