<?php

namespace Arris\Cache;

use Arris\Exceptions\CacheRoutingException;
use JsonException;
use PDO;
use Psr\Log\LoggerInterface;

class Cache
{
    /**
     * @var RedisClient $redis
     */
    private static $redis;

    /**
     * @var bool $is_connected
     */
    private static $is_connected = false;

    /**
     * @var LoggerInterface|null $logger
     */
    private static $logger = null;

    /**
     * @var array
     */
    public static $repository = [];

    /**
     * @param array<string, int> $credentials
     * @param array $rules
     * @param PDO|null $PDO
     * @param LoggerInterface|null $logger
     * @throws JsonException
     */
    public static function init($credentials = [], $rules = [], PDO $PDO = null, LoggerInterface $logger = null)
    {
        self::$logger = $logger;
        self::$is_connected = false;

        $debug = [];
        $options = [
            'enabled'   =>  false,
            'host'      =>  '127.0.0.1',
            'port'      =>  6379,
            'timeout'   =>  null,
            'persistent'=>  '',
            'db'        =>  0,
            'password'  =>  null
        ];
        $options = self::overrideDefaults($options, $credentials);

        if ($options['enabled']) {
            try {
                self::$redis = new RedisClient($options['host'], $options['port'], $options['timeout'], $options['persistent'], $options['db'], $options['password']);
                self::$redis->connect();
                self::$is_connected = true;
            } catch (RedisClientException $e){
            }
        }

        // теперь перебираем $rules

        foreach ($rules as $rule_name => $rule_definition) {
            // если редис запущен и ключ присутствует - вытаскиваем значение, кладём в репозиторий и продолжаем
            if (self::$is_connected && self::$redis->exists($rule_name)) {
                self::set($rule_name, json_decode(self::$redis->get($rule_name), true, 512, JSON_THROW_ON_ERROR));
                $debug[$rule_name] = 'Redis: loaded';
                continue;
            }

            // определяем action и TTL
            $ttl = array_key_exists('ttl', $rule_definition) ? $rule_definition['ttl'] : 0;
            $action = array_key_exists('action', $rule_definition) ? $rule_definition['action'] : null;

            // если ACTION пуст - кладем в репозиторий NULL и продолжаем
            if (empty($action)) {
                $debug[$rule_name] = 'Redis: null, Action: empty';
                self::set($rule_name, null);
                continue;
            }

            $data = '';

            // если источник данных SQL
            if ($rule_definition['source'] === 'sql') {

                // коннекта к БД нет: кладем в репозиторий null и продолжаем
                if (is_null($PDO)) {
                    $debug[$rule_name] = 'Redis: null, Action: present, PDO: null';
                    self::set($rule_name, null);
                    continue;
                }

                $sth = $PDO->query($rule_definition['action']);
                if (false === $sth) {
                    $debug[$rule_name] = 'Redis: null, Action: present, PDO: present, Query: false';
                    self::set($rule_definition, null);
                    continue;
                }
                $data = $sth->fetchAll();
                $debug[$rule_name] = 'Redis: null, Action: present, PDO: present, Query: present, Fetch: recieved';
            }

            if ($rule_definition['source'] === 'callback') {
                [$actor, $params] = self::detectCallbackHandler($rule_definition['action']);
                $data = call_user_func_array($actor, $params);
                $debug[$rule_name] = "Redis: null, Action: present, Callback: present, Data: recieved";
            }

            // кладем результат в репозиторий
            self::set($rule_name, $data);

            // и в редис, если он запущен
            if (self::$is_connected) {
                self::$redis->set($rule_name, self::jsonize($data));
                self::$redis->expire($rule_name, $ttl);
                $debug[$rule_name] .= " Redis: saved (TTL: {$ttl})";
            } else {
                $debug[$rule_name] .= " Redis: DISABLED";
            }

        } // foreach

        if ($logger instanceof LoggerInterface) {
            $logger->debug("", $debug);
        }

    } // init

    public static function get($key, $default = null)
    {
        if (self::check($key)) {
            return self::$repository[ $key ];
        }
        return $default;
    }

    public static function set($key, $data)
    {
        if (self::check($key)) {
            unset( self::$repository[$key]);
        }
        self::$repository[ $key ] = $data;
    }

    public static function check($key)
    {
        return array_key_exists($key, self::$repository);
    }

    /**
     * @param $data
     * @return false|string
     * @throws JsonException
     */
    private static function jsonize($data)
    {
        return json_encode($data, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param $defaults
     * @param $options
     * @return mixed
     */
    private static function overrideDefaults($defaults, $options)
    {
        $source = $defaults;
        array_walk($source, static function (&$default, $key) use ($options) {
            if (array_key_exists($key, $options)) {
                $default = $options[$key];
            }
        } );
        return $source;
    }

    /**
     * @param $actor
     * @return array
     */
    private static function detectCallbackHandler($actor)
    {
        if ($actor instanceof \Closure) {
            return [
                $actor, []
            ];
        }

        // 0 - имя класса + метода
        // 1 - массив параметров
        if (is_array($actor) && count($actor) === 2) {
            $handler = $actor[0];
            $params = $actor[1];

            if (strpos($handler, '@') > 0) {
                // dynamic class
                [$class, $method] = explode('@', $handler, 2);

                if (!class_exists($class)) {
                    self::$logger->error("Class {$class} not defined.", [ $class ]);
                    throw new CacheRoutingException("Class {$class} not defined.", 500);
                }

                if (!method_exists($class, $method)) {
                    self::$logger->error("Method {$method} not declared at {$class} class.", [ $class, $method ]);
                    throw new CacheRoutingException("Method {$method} not declared at {$class} class", 500);
                }

                $actor = [ new $class, $method ];
            } elseif (strpos($handler, '::') > 0) {
                [$class, $method] = explode('@', $handler, 2);

                if (!class_exists($class)) {
                    self::$logger->error("Class {$class} not defined.", [ $class ]);
                    throw new CacheRoutingException("Class {$class} not defined.", 500);
                }

                if (!method_exists($class, $method)) {
                    self::$logger->error("Static method {$method} not declared at {$class} class.", [ $class, $method ]);
                    throw new CacheRoutingException("Static method {$method} not declared at {$class} class", 500);
                }

                $actor = [ $class, $method ];
            } else {
                // function
                if (!function_exists($handler)){
                    self::$logger->error("Handler function {$handler} not found", [ ]);
                    throw new CacheRoutingException("Handler function {$handler} not found", 500);
                }

                $actor = $handler;
            }
        } // is_array
        return [
            $actor, $params
        ];

    } // detectCallbackHandler
}