<?php

namespace Arris\Cache;

use JsonException;
use PDO;
use Arris\Cache\Exceptions\CacheCallbackException;
use Arris\Cache\Exceptions\RedisClientException;
use Psr\Log\LoggerInterface;

class Cache implements CacheInterface
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
     * @var PDO $pdo
     */
    private static $pdo;

    /**
     * @var array
     */
    public static $repository = [];

    public static function init($credentials = [], $rules = [], PDO $PDO = null, LoggerInterface $logger = null)
    {
        self::$logger = $logger;
        self::$is_connected = false;
        self::$pdo = $PDO;

        $options = [
            'enabled'   =>  false,
            'host'      =>  '127.0.0.1',
            'port'      =>  6379,
            'timeout'   =>  null,
            'persistent'=>  '',
            'database'  =>  0,
            'password'  =>  null
        ];
        $options = self::overrideDefaults($options, $credentials);

        if ($options['enabled']) {
            try {
                self::$redis = new RedisClient($options['host'], $options['port'], $options['timeout'], $options['persistent'], $options['database'], $options['password']);
                self::$redis->connect();
                self::$is_connected = true;
            } catch (RedisClientException $e){
            }
        }

        // теперь перебираем $rules
        foreach ($rules as $rule_name => $rule_definition) {
            $message = self::defineRule($rule_name, $rule_definition);
            if ($logger instanceof LoggerInterface) {
                $logger->debug($message);
            }
        }

    } // init

    public static function addRule($rule_name, $rule_definition)
    {
        $message = self::defineRule($rule_name, $rule_definition);
        if (self::$logger instanceof LoggerInterface) {
            self::$logger->debug($message);
        }
    }

    public static function getAllKeys()
    {
        return array_keys(self::$repository);
    }

    public static function get($key, $default = null)
    {
        if (self::check($key)) {
            self::$logger->debug("Recieved `{$key}` from cache");
            return self::$repository[ $key ];
        }
        return $default;
    }

    public static function set($key, $data)
    {
        self::unset($key);
        self::$repository[ $key ] = $data;
    }

    public static function check($key)
    {
        return array_key_exists($key, self::$repository);
    }

    public static function unset($key)
    {
        if (self::check($key)) {
            unset( self::$repository[$key]);
        }
    }

    public static function flushAll()
    {
        foreach (self::$repository as $key => $v) {
            self::flush($key);
        }
    }

    public static function flush($key)
    {
        self::unset($key);
        if (self::$redis) {
            self::$redis->del($key);
        }
    }

    /**
     * Определяет ключ-значение для кэша
     *
     * @param $rule_name
     * @param $rule_definition
     * @return string
     * @throws JsonException
     */
    private static function defineRule($rule_name, $rule_definition)
    {
        $message = '';

        // если редис запущен и ключ присутствует - вытаскиваем значение, кладём в репозиторий и продолжаем
        if (self::$is_connected && self::$redis->exists($rule_name)) {
            self::set($rule_name, json_decode(self::$redis->get($rule_name), true, 512, JSON_THROW_ON_ERROR));
            $message = "Loaded `{$rule_name}` from redis, stored to cache";
            return $message;
        }

        // определяем action и TTL
        $enabled = array_key_exists('enabled', $rule_definition) ? $rule_definition['enabled'] : true;
        $ttl = array_key_exists('ttl', $rule_definition) ? $rule_definition['ttl'] : 0;
        $action = array_key_exists('action', $rule_definition) ? $rule_definition['action'] : null; // ?? оператор менее нагляден

        if ($enabled === false) {
            return "Rule `{$rule_name}` disabled";
        }

        // если ACTION пуст - кладем в репозиторий NULL и продолжаем
        if (empty($action)) {
            $message = '[ERROR] Key not found, but action is empty';
            self::set($rule_name, null);
            return $message;
        }

        $data = '';

        // если источник данных SQL
        if ($rule_definition['source'] === 'sql') {

            // коннекта к БД нет: кладем в репозиторий null и продолжаем
            if (is_null(self::$pdo)) {
                $message = '[ERROR] Key not found, PDO not defined';
                self::set($rule_name, null);
                return $message;
            }

            $sth = self::$pdo->query($rule_definition['action']);
            if (false === $sth) {
                $message = '[ERROR] Key not found, PDO present, PDO Answer invalid';
                self::set($rule_definition, null);
                return $message;
            }
            $data = $sth->fetchAll();
            $message = "Data for `{$rule_name}` fetched from DB,";
        }

        if ($rule_definition['source'] === 'callback') {
            [$actor, $params] = self::compileCallbackHandler($rule_definition['action']);
            $data = call_user_func_array($actor, $params);
            $message = "Data for `{$rule_name}` fetched from callback,";
        }

        //@todo: добавить тип источника 'RAW', в котором `action` не используется, а данные берутся из ключа `data`

        // кладем результат в репозиторий
        self::set($rule_name, $data);

        // и в редис, если он запущен
        if (self::$is_connected) {
            self::$redis->set($rule_name, self::jsonize($data));
            self::$redis->expire($rule_name, $ttl);
            $message .= " stored to cache, saved to redis, TTL: {$ttl} seconds";
        } else {
            $message .= " stored to cache, redis disabled";
        }

        return $message;
    }

    /**
     *
     * @param $data
     * @return false|string
     * @throws JsonException
     */
    private static function jsonize($data)
    {
        return json_encode($data, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Перезаписывает набор дефолтных значений на основе переданного списка опций
     * @todo: -> Arris helpers
     *
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
     * "Компилирует" параметры коллбэка
     * (@todo: обновить в Arris\Router)
     *
     * @param $actor
     * @return array
     */
    private static function compileCallbackHandler($actor)
    {
        if ($actor instanceof \Closure) {
            return [
                $actor, []
            ];
        }

        //@todo: что делать, если инстанс класса уже создан и проинициализирован и лежит в APP::REPO
        // $app->set(Banners::class, new Banners(....) );
        // То есть $handler может быть не строкой, а массивом [ $app->get(Banners::class), 'loadBanners' ]

        // 0 - имя класса + метода
        // 1 - массив параметров
        if (is_array($actor)) {
            $handler = $actor[0];
            $params = (count($actor) > 1) ? $actor[1] : [];

            //@todo: нужна проверка is_string()
            if (strpos($handler, '@') > 0) {
                // dynamic class
                [$class, $method] = explode('@', $handler, 2);

                if (!class_exists($class)) {
                    self::$logger->error("Class {$class} not defined.", [ $class ]);
                    throw new CacheCallbackException("Class {$class} not defined.", 500);
                }

                if (!method_exists($class, $method)) {
                    self::$logger->error("Method {$method} not declared at {$class} class.", [ $class, $method ]);
                    throw new CacheCallbackException("Method {$method} not declared at {$class} class", 500);
                }

                $actor = [ new $class, $method ];
            } elseif (strpos($handler, '::') > 0) {
                [$class, $method] = explode('::', $handler, 2);

                if (!class_exists($class)) {
                    self::$logger->error("Class {$class} not defined.", [ $class ]);
                    throw new CacheCallbackException("Class {$class} not defined.", 500);
                }

                if (!method_exists($class, $method)) {
                    self::$logger->error("Static method {$method} not declared at {$class} class.", [ $class, $method ]);
                    throw new CacheCallbackException("Static method {$method} not declared at {$class} class", 500);
                }

                $actor = [ $class, $method ];
            } else {
                // function
                if (!function_exists($handler)){
                    self::$logger->error("Handler function {$handler} not found", [ ]);
                    throw new CacheCallbackException("Handler function {$handler} not found", 500);
                }

                $actor = $handler;
            }
        } // is_array
        return [
            $actor, $params
        ];

    } // detectCallbackHandler


}

# -eof-
