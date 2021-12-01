<?php

use Arris\Cache\Exceptions\RedisClientException;
use Arris\Cache\RedisClient;

require_once __DIR__ . '/vendor/autoload.php';

function jsonize($data) { return json_encode($data, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR); }

$options = [
    'enabled'   =>  false,
    'host'      =>  '127.0.0.1',
    'port'      =>  6379,
    'timeout'   =>  null,
    'persistent'=>  '',
    'database'  =>  0,
    'password'  =>  null
];

try {
    $redis = new RedisClient($options['host'], $options['port'], $options['timeout'], $options['persistent'], $options['database'], $options['password']);
    $redis->connect();
} catch (RedisClientException $e){
}

// $redis->set('test', jsonize($options));

// $redis->set('bool', false);
// $redis->expire('bool', 5);

var_dump($redis->get('bool'));


