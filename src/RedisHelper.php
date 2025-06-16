<?php

namespace Arris\Cache;

use Arris\Toolkit\RedisClient;
use Arris\Toolkit\RedisClientException;
use JsonException;
use Psr\Log\LoggerInterface;
use RedisException;
use function json_decode;

class RedisHelper implements RedisHelperInterface
{
    private LoggerInterface $logger;

    private bool $is_redis_connected;

    public RedisClient $redis;

    /**
     * @param RedisClient $redis_connector
     * @param bool $is_redis_connected
     * @param LoggerInterface $logger
     * @return void
     */
    public function __construct(RedisClient $redis_connector, bool $is_redis_connected, LoggerInterface $logger)
    {
        $this->redis = $redis_connector;
        $this->is_redis_connected = $is_redis_connected;
        $this->logger = $logger;
    }

    /**
     * @param string $key_name
     * @param bool $use_json_decode
     * @return mixed
     * @throws RedisClientException
     * @throws JsonException
     * @throws RedisException
     */
    public function fetch(string $key_name, bool $use_json_decode = true): mixed
    {
        $this->logger->info("[redis][fetch] called");

        if ($this->is_redis_connected === false) {
            $this->logger->info("[redis][fetch] ERROR: REDIS not connected");
            return null;
        }

        $value = $this->redis->get($key_name, false);
        $this->logger->info("[redis][fetch] Data from REDIS received");

        if ($use_json_decode && !empty($value)) {
            $this->logger->info("[redis][fetch] Decoding JSON data");
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
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
     * @throws JsonException
     * @throws RedisException
     */
    public function push(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): bool
    {
        $this->logger->info("[redis][push] called");

        if ($this->is_redis_connected === false) {
            $this->logger->info("[redis][push] ERROR: REDIS not connected");
            return false;
        }

        $this->redis->set($key_name, ($use_json_encode ? CacheHelper::jsonize($data) : $data));
        $this->logger->info("[redis][push] Data pushed to REDIS");

        if ($ttl > 0) {
            $this->redis->expire($key_name, $ttl);
            $this->logger->info("[redis][push] TTL {$ttl} seconds");
        } else {
            $this->logger->info("[redis][push] TTL unlimited");
        }

        if ($this->redis->exists($key_name)) {
            $this->logger->info("[redis][push] Post-push check: SUCCESS");
            return true;
        }
        $this->logger->info("[redis][push] Post-push check: ERROR");

        return false;
    }

    /**
     *
     * @param string $key_name
     * @return array
     * @throws RedisClientException
     * @throws RedisException
     */
    public function del(string $key_name):array
    {
        $this->logger->info("[redis][del] started");

        if ($this->is_redis_connected === false) {
            return [];
        }

        $deleted = $this->redis->delete($key_name);
        ksort($deleted);

        return $deleted;
    }

    /**
     * @throws RedisClientException
     * @throws RedisException
     */
    public function check(string $key_name): bool
    {
        $this->logger->info("[redis][check] called");

        if ($this->is_redis_connected === false) {
            return false;
        }

        return $this->redis->exists($key_name);
    }

    /**
     * @param string $pattern
     * @return array
     * @throws RedisClientException
     * @throws RedisException
     */
    public function keys(string $pattern = '*'):array
    {
        $this->logger->info("[redis][keys] called");

        if ($this->is_redis_connected === false) {
            return [];
        }

        return $this->redis->keys($pattern);
    }

}