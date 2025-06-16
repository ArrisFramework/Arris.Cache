<?php

use Arris\AppLogger;
use Arris\AppLogger\Monolog\Logger;
use Arris\Cache\Cache;

require_once __DIR__ . '/vendor/autoload.php';

$pdo_config = (new \Arris\Database\Config())
    ->setUsername('root')
    ->setPassword('password')
    ->setDatabase('test');
$pdo = $pdo_config->connect();

\Arris\AppLogger::init('test', '', [
    'default_logfile_path'  =>  __DIR__,
]);
AppLogger::addScope('main', [
    [ 'redis.log', AppLogger::DEBUG ]
]);

AppLogger::addScopeLevel(
    scope: 'console',
    target: 'php://stdout',
    log_level: Logger::WARNING,
    handler: static function()
    {
        $formatter = new \Arris\AppLogger\LineFormatterColored("[%datetime%]: %message%\n", "Y-m-d H:i:s", false, true);
        $handler = new \Arris\AppLogger\Monolog\Handler\StreamHandler('php://stdout', Logger::INFO);
        $handler->setFormatter($formatter);
        return $handler;
    },
    // enable: false
);

AppLogger::scope('main')->debug('Message');

Cache::init(PDO: $pdo, logger: AppLogger::scope('main'));
/*Cache::addRule(
    'districts',
    enabled: true,
    source: Cache::RULE_SOURCE_SQL, action: 'SELECT id, name FROM districts WHERE hidden = 0 ORDER BY id ASC',
    ttl: 3600
);

$d = Cache::get('districts');

Cache::addCounter('counter', 15);

var_dump( Cache::getCounter('counter') );

Cache::incrCounter('counter', 5);

var_dump( Cache::getCounter('counter') );

var_dump( Cache::get('counter'));

for ($i = 100; $i < 199; $i++) {
    Cache::push("key_{$i}", mt_rand(1000, 100*$i));
}

Cache::redis()::del("key_1?5");*/

$v = 5;

Cache::addRule(
    'districts',
    source: Cache::RULE_SOURCE_SQL,
    action: 'SELECT id, name FROM districts WHERE hidden = 0 ORDER BY id ASC',
    ttl: Cache::TIME_FULL_DAY
);
Cache::addRule(
    'rubrics',
    source: Cache::RULE_SOURCE_SQL,
    action: 'SELECT id, name, url FROM rubrics WHERE hidden = 0 ORDER BY sorder',
    ttl: Cache::TIME_FULL_DAY
);
Cache::addRule(
    'data',
    source: Cache::RULE_SOURCE_CALLBACK,
    action: static function() use ($v){ return $v; },
);

Cache::addRule(
    'raw',
    source: Cache::RULE_SOURCE_RAW,
    action: [ 1 => 2, 3 => "b"]
);

var_dump(
    Cache::get('rubrics')
);


var_dump(
    Cache::redis()->keys('*')
);

Cache::redisPush("something", [ 1 => 2, 3 => "b"]);
Cache::redis()->push("something_2", [ 1 => 2, 3 => "b"]);
