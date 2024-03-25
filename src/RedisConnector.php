<?php

namespace Arris\Cache;

use Arris\Cache\Exceptions\RedisClientException;

class RedisConnector extends RedisClient implements RedisDefaultCredentials
{
    public RedisClient $connector;

    public function __construct($credentials = [])
    {
        parent::__construct();

        $options = [
            'enabled'   =>  false,
            'host'      =>  self::REDIS_DEFAULT_HOST,
            'port'      =>  self::REDIS_DEFAULT_PORT,
            'timeout'   =>  null,
            'persistent'=>  '',
            'database'  =>  self::REDIS_DEFAULT_DB,
            'password'  =>  self::REDIS_DEFAULT_PASSWORD
        ];

        $options = CacheHelper::overrideDefaults($options, $credentials);

        $this->connector = new RedisClient($options['host'], $options['port'], $options['timeout'], $options['persistent'], $options['database'], $options['password']);
        if ($options['enabled']) {
            try {
                $this->connector->connect();
            } catch (RedisClientException $e){
            }
        }
    }

    public function getConnector()
    {
        return $this->connector;
    }

    public function __call($name, $args)
    {
        if ($this->connector->isConnected()) {
            return parent::__call($name, $args);
        }
        return null;
    }

}