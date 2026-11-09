<?php

declare(strict_types=1);

namespace Arris\Cache;

use Arris\Toolkit\RedisClient;

interface RedisHelperInterface
{
    public function fetch(string $key_name, bool $use_json_decode = true): mixed;
    /**
     * Атомарная запись: SETEX при `$ttl > 0`, иначе SET.
     *
     * `$use_json_encode = false` — для скаляров: значение хранится как есть.
     * Массивы всё равно JSON-кодируются внутри RedisClient (Redis хранит строки).
     *
     * @return bool false — Redis отклонил запись (ключ не изменён)
     */
    public function push(string $key_name, mixed $data, int $ttl = 0, bool $use_json_encode = true): bool;
    public function del(string $key_name):array;
    public function check(string $key_name): bool;
    public function keys(string $pattern = '*'):array;

    /**
     * Прямой коннектор к Redis.
     */
    public function getConnector(): RedisClient;
}