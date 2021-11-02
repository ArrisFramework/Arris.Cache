<?php

namespace Arris\Cache;

class CredisException extends \RuntimeException
{
    const CODE_TIMED_OUT = 1;
    const CODE_DISCONNECTED = 2;

    public function __construct($message, $code = 0, $exception = NULL)
    {
        if ($exception && $exception instanceof \RedisException && strpos($message,'read error on connection') === 0) {
            $code = self::CODE_DISCONNECTED;
        }
        parent::__construct($message, $code, $exception);
    }
}