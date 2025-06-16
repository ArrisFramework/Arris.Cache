<?php

namespace Arris\Cache;

use Arris\Toolkit\RedisClientException;
use Psr\Log\LoggerInterface;

class RedisHelper
{
    private static LoggerInterface $logger;

    private static bool $is_redis_connected;

    public static \Arris\Toolkit\RedisClient $redis;

    /**
     * @param \Arris\Toolkit\RedisClient $redis_connector
     * @param bool $is_redis_connected
     * @param LoggerInterface $logger
     * @return void
     */
    public static function init(\Arris\Toolkit\RedisClient $redis_connector, bool $is_redis_connected, LoggerInterface $logger): void
    {
        self::$logger = $logger;
        self::$is_redis_connected = $is_redis_connected;
        self::$redis = $redis_connector;
    }

    /**
     * @param string $key_name
     * @param bool $use_json_decode
     * @return mixed
     * @throws RedisClientException
     * @throws \JsonException
     * @throws \RedisException
     */
    public static function fetch(string $key_name, bool $use_json_decode = true): mixed
    {
        self::$logger->info("[redis][fetch] called");

        if (self::$is_redis_connected === false) {
            self::$logger->info("[redis][fetch] ERROR: REDIS not connected");
            return null;
        }

        $value = self::$redis->get($key_name, false);
        self::$logger->info("[redis][fetch] Data from REDIS received");

        if ($use_json_decode && !empty($value)) {
            self::$logger->info("[redis][fetch] Decoding JSON data");
            $value = \json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    /**
     * @param string $key_name
     * @param $data
     * @param int $ttl
     * @param bool $use_json_encode
     * @return bool
     * @throws RedisClientException
     * @throws \JsonException
     * @throws \RedisException
     */
    public static function push(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): bool
    {
        self::$logger->info("[redis][push] called");

        if (self::$is_redis_connected === false) {
            self::$logger->info("[redis][push] ERROR: REDIS not connected");
            return false;
        }

        self::$redis->set($key_name, ($use_json_encode ? CacheHelper::jsonize($data) : $data));
        self::$logger->info("[redis][push] Data pushed to REDIS");

        if ($ttl > 0) {
            self::$redis->expire($key_name, $ttl);
            self::$logger->info("[redis][push] TTL {$ttl} seconds");
        } else {
            self::$logger->info("[redis][push] TTL unlimited");
        }

        if (self::$redis->exists($key_name)) {
            self::$logger->info("[redis][push] Post-push check: SUCCESS");
            return true;
        }
        self::$logger->info("[redis][push] Post-push check: ERROR");

        return false;
    }

    /**
     *
     * @param string $key_name
     * @return array
     * @throws RedisClientException
     * @throws \RedisException
     */
    public static function del(string $key_name):array
    {
        self::$logger->info("[redis][del] started");

        if (self::$is_redis_connected === false) {
            return [];
        }

        $deleted = self::$redis->delete($key_name);
        ksort($deleted);

        return $deleted;
    }

    /**
     * @throws RedisClientException
     * @throws \RedisException
     */
    public static function check(string $key_name): bool
    {
        self::$logger->info("[redis][check] called");

        if (self::$is_redis_connected === false) {
            return false;
        }

        return self::$redis->exists($key_name);
    }

    /**
     * @param string $pattern
     * @return array
     * @throws RedisClientException
     * @throws \RedisException
     */
    public static function keys(string $pattern = '*'):array
    {
        if (self::$is_redis_connected === false) {
            return [];
        }

        return self::$redis->keys($pattern);
    }

}