<?php

namespace Arris\Cache;

/**
 * Interface, not trait ('cause constants in traits supported since PHP 8.2)
 */
interface RedisDefaultCredentials
{
    public const REDIS_DEFAULT_HOST     = '127.0.0.1';
    public const REDIS_DEFAULT_PORT     = 6379;
    public const REDIS_DEFAULT_DB       = 0;
    public const REDIS_DEFAULT_PASSWORD = null;
}