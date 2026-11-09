<?php

declare(strict_types=1);

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

    private RedisClient $redis;

    /**
     * @param RedisClient $redis_connector
     * @param LoggerInterface $logger
     * @return void
     */
    public function __construct(RedisClient $redis_connector, LoggerInterface $logger)
    {
        $this->redis = $redis_connector;
        $this->logger = $logger;
    }

    /**
     * Прямой коннектор к Redis.
     */
    public function getConnector(): RedisClient
    {
        return $this->redis;
    }

    /**
     * @param string $key_name
     * @param bool $use_json_decode
     * @return mixed
     * @throws RedisClientException
     * @throws JsonException
     * @throws RedisException
     *
     * При `$use_json_decode = true` (по умолчанию) любое непустое значение
     * декодируется как JSON; «сырые» не-JSON строки, записанные с
     * `use_json_encode = false`, нужно читать с `$use_json_decode = false`.
     */
    public function fetch(string $key_name, bool $use_json_decode = true): mixed
    {
        $this->logger->info("[redis][fetch] called");

        $value = $this->redis->get($key_name, false);

        if ($value === false) {
            $this->logger->info("[redis][fetch] Key not found in REDIS");
            return null;
        }

        $this->logger->info("[redis][fetch] Data from REDIS received");

        // Пустая строка — не JSON; пропускаем декодирование, чтобы не бросать
        // JsonException на значениях, записанных с use_json_encode=false.
        if ($use_json_decode && $value !== '') {
            $this->logger->info("[redis][fetch] Decoding JSON data");
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    /**
     * @param string $key_name
     * @param mixed $data
     * @param int $ttl
     * @param bool $use_json_encode
     * @return bool
     * @throws RedisClientException
     * @throws JsonException
     * @throws RedisException
     *
     * `$use_json_encode = false` — для скаляров: значение хранится как есть,
     * без JSON-обёртки (строка без кавычек, bool → "1", null → "").
     * Массивы/объекты всё равно JSON-кодируются внутри RedisClient, потому что
     * Redis хранит только строки.
     */
    public function push(string $key_name, mixed $data, int $ttl = 0, bool $use_json_encode = true): bool
    {
        $this->logger->info("[redis][push] called", ['key' => $key_name, 'ttl' => $ttl]);

        // Атомарная запись одной командой: SETEX при TTL > 0, иначе SET.
        // Раньше это были set() + expire(): между ними ключ жил без TTL,
        // а неудачный expire() оставлял ключ вечным.
        $pushed = $this->redis->set(
            $key_name,
            ($use_json_encode ? CacheHelper::jsonize($data) : $data),
            $ttl > 0 ? $ttl : null
        );

        if (!$pushed) {
            $this->logger->error("[redis][push] ERROR: set failed", ['key' => $key_name, 'ttl' => $ttl]);
            return false;
        }

        $this->logger->info(
            $ttl > 0
                ? "[redis][push] Data pushed to REDIS, TTL {$ttl} seconds"
                : "[redis][push] Data pushed to REDIS, TTL unlimited",
            ['key' => $key_name]
        );

        return true;
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

        $deleted = $this->redis->delete($key_name);
        sort($deleted);

        return $deleted;
    }

    /**
     * @throws RedisClientException
     * @throws RedisException
     */
    public function check(string $key_name): bool
    {
        $this->logger->info("[redis][check] called");

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

        return $this->redis->keys($pattern);
    }

}