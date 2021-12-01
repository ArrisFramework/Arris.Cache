<?php

use Arris\AppLogger;
use Arris\Cache\Cache;
use Arris\DB;

require_once __DIR__ . '/vendor/autoload.php';

Cache::init([
    'enabled'   =>  1,
], [], DB::getConnection(), AppLogger::scope('redis'));

Cache::addCounter('views', 100, 5);

var_dump( Cache::getCounter('views'));

Cache::incrCounter('views', 200);

var_dump( Cache::getCounter('views'));

Cache::decrCounter('views', 500);

var_dump( Cache::getCounter('views'));


