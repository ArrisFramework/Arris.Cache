<?php

namespace Arris\Cache\Exceptions;

use RedisException;
use RuntimeException;
use function str_starts_with;
use function strtolower;

class RedisClientException extends RuntimeException
{
    const CODE_TIMED_OUT = 1;
    const CODE_DISCONNECTED = 2;

    public function __construct($message, $code = 0, $exception = NULL)
    {
        if ($exception instanceof RedisException && str_starts_with(strtolower($message), 'read error on connection')) {
            $code = self::CODE_DISCONNECTED;
        }
        parent::__construct($message, $code, $exception);
    }
}