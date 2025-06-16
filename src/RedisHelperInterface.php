<?php

namespace Arris\Cache;

use Arris\Toolkit\RedisClient;
use Psr\Log\LoggerInterface;

interface RedisHelperInterface
{
    public function __construct(RedisClient $redis_connector, bool $is_redis_connected, LoggerInterface $logger);
    public function fetch(string $key_name, bool $use_json_decode = true): mixed;
    public function push(string $key_name, $data, int $ttl = 0, bool $use_json_encode = true): bool;
    public function del(string $key_name):array;
    public function check(string $key_name): bool;
    public function keys(string $pattern = '*'):array;

}