<?php

namespace Arris\Cache;

use JsonException;
use PDO;
use Arris\Cache\Exceptions\CacheCallbackException;
use Arris\Cache\Exceptions\RedisClientException;
use Psr\Log\LoggerInterface;

use function array_key_exists, array_keys, array_walk, is_array;
use function json_decode, json_encode;
use function class_exists, method_exists, function_exists, call_user_func_array;
use function is_null, explode, strpos;

class Cache implements CacheInterface
{
    /**
     * @var RedisClient $redis_connector
     */
    private static $redis_connector;

    /**
     * @var bool $is_redis_connected
     */
    public static $is_redis_connected = false;

    /**
     * @var LoggerInterface|null $logger
     */
    private static $logger;

    /**
     * @var PDO $pdo
     */
    private static $pdo;

    /**
     * @var array
     */
    public static $repository = [];

    public static function init(array $credentials = [], array $rules = [], PDO $PDO = null, LoggerInterface $logger = null)
    {
        self::$logger = $logger;
        self::$is_redis_connected = false;
        self::$pdo = $PDO;

        $options = [
            'enabled'   =>  false,
            'host'      =>  self::REDIS_DEFAULT_HOST,
            'port'      =>  self::REDIS_DEFAULT_PORT,
            'timeout'   =>  null,
            'persistent'=>  '',
            'database'  =>  self::REDIS_DEFAULT_DB,
            'password'  =>  self::REDIS_DEFAULT_PASSWORD
        ];
        $options = self::overrideDefaults($options, $credentials);

        if ($options['enabled']) {
            try {
                self::$redis_connector = new RedisClient($options['host'], $options['port'], $options['timeout'], $options['persistent'], $options['database'], $options['password']);
                self::$redis_connector->connect();
                self::$is_redis_connected = true;
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
    
    public static function getConnector():RedisClient
    {
        return self::$redis_connector;
    }

    public static function addRule(string $rule_name, $rule_definition):string
    {
        $message = self::defineRule($rule_name, $rule_definition);
        if (self::$logger instanceof LoggerInterface) {
            self::$logger->debug($message);
        }
        return $message;
    }

    public static function getAllKeys():array
    {
        return array_keys(self::$repository);
    }

    public static function get(string $key, $default = null)
    {
        if (self::check($key)) {
            self::$logger->debug("Recieved `{$key}` from cache");
            return self::$repository[ $key ];
        }
        return $default;
    }

    public static function set(string $key, $data)
    {
        self::unset($key);
        self::$repository[ $key ] = $data;
    }

    public static function check(string $key)
    {
        return array_key_exists($key, self::$repository);
    }

    public static function unset(string $key)
    {
        if (self::check($key)) {
            unset( self::$repository[$key]);
        }
    }

    public static function flushAll(string $mask = '*')
    {
        foreach (self::$repository as $key => $v) {
            self::flush($key);
        }
    }

    public static function flush(string $key, bool $clean_redis = true)
    {
        self::unset($key);
        if (self::$redis_connector && $clean_redis) {
            self::$redis_connector->del($key);
        }
    }
    
    public static function redisFetch(string $key_name, $use_json_decode = true)
    {
        if (self::$is_redis_connected === false) {
            return null;
        }
        
        $value = self::$redis_connector->get($key_name);
        
        if ($use_json_decode && !empty($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        
        return $value;
    }
    
    public static function redisPush(string $key_name, $data, $ttl = 0):bool
    {
        if (self::$is_redis_connected === false) {
            return false;
        }
    
        self::$redis_connector->set($key_name, self::jsonize($data));
        self::$redis_connector->expire($key_name, $ttl);
        
        if (self::$redis_connector->exists($key_name)) {
            return true;
        }
        
        return false;
    }
    
    public static function redisDel(string $key_name):bool
    {
        if (self::$is_redis_connected === false) {
            return false;
        }
        self::$redis_connector->del($key_name);
        
        return !(self::$redis_connector->exists($key_name));
    }
    
    // работа со счетчиками + добавить в репозиторий!
    
    public static function addCounter(string $key, int $initial = 0, $ttl = 0):int
    {
        self::set($key, $initial);
        if (self::$is_redis_connected) {
            self::$redis_connector->set($key, $initial);
            self::$redis_connector->expire($key, $ttl);
            return self::$redis_connector->get($key);
        }
        return $initial;
    }
    
    public static function incrCounter(string $key, int $diff = 1):int
    {
        if (!array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }
        
        if (self::$is_redis_connected) {
            self::$redis_connector->incrBy($key, $diff);
        }
        
        self::$repository[ $key ] += $diff;
        return self::$repository[ $key ];
    }
    
    public static function decrCounter(string $key, int $diff = 1):int
    {
        if (!array_key_exists($key, self::$repository)) {
            self::set($key, 0);
        }
        
        if (self::$is_redis_connected) {
            self::$redis_connector->decrBy($key, $diff);
        }
        
        self::$repository[ $key ] -= $diff;
        return self::$repository[ $key ];
    }
    
    public static function getCounter(string $key, $default = 0):int
    {
        if (self::$is_redis_connected) {
            return self::$redis_connector->get($key);
        }
        return self::get($key, $default);
    }
    
    /* ================================================================================================================= */
    /* ================================================================================================================= */
    /* ================================================================================================================= */
    /* ============================================ PRIVATE METHODS ==================================================== */
    /* ================================================================================================================= */
    /* ================================================================================================================= */
    /* ================================================================================================================= */
    
    
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

        if (self::$is_redis_connected) {
            $rule_value = self::$redis_connector->get($rule_name);
            
            if ($rule_value !== false) {
                self::set($rule_name, json_decode($rule_value, true, 512, JSON_THROW_ON_ERROR));
                $message = "Loaded `{$rule_name}` from redis, stored to cache";
                return $message;
            }
        }

        // определяем action и TTL
        $enabled = array_key_exists('enabled', $rule_definition) ? $rule_definition['enabled'] : true;
        $ttl = array_key_exists('ttl', $rule_definition) ? $rule_definition['ttl'] : 0;
        $action = array_key_exists('action', $rule_definition) ? $rule_definition['action'] : null; // оператор `??` менее нагляден, поэтому оставлено так

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
        if ($rule_definition['source'] === self::RULE_SOURCE_SQL) {

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

        if ($rule_definition['source'] === self::RULE_SOURCE_CALLBACK) {
            [$actor, $params] = self::compileCallbackHandler($rule_definition['action']);
            $data = call_user_func_array($actor, $params);
            $message = "Data for `{$rule_name}` fetched from callback,";
        }
        
        if ($rule_definition['source'] === self::RULE_SOURCE_RAW) {
            $data = $rule_definition['data'];
            $message = "Data for `{$rule_name}` fetched raw data,";
        }
        
        // кладем результат в репозиторий
        self::set($rule_name, $data);

        // и в редис, если он запущен
        if (self::$is_redis_connected) {
            self::$redis_connector->set($rule_name, self::jsonize($data));
            self::$redis_connector->expire($rule_name, $ttl);
            $message .= " stored to cache, saved to redis, TTL: {$ttl} seconds";
        } else {
            $message .= " stored to cache, redis disabled";
        }

        return $message;
    }
    
    public static function isRedisConnected():bool
    {
        return self::$is_redis_connected;
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

        // что делать, если инстанс класса уже создан и проинициализирован и лежит в APP::REPO ?
        // $app->set(Banners::class, new Banners(....) );
        // То есть $handler может быть не строкой, а массивом [ $app->get(Banners::class), 'loadBanners' ]
        // Ничего не делать :) Вызывать его через коллбэк!

        // 0 - имя класса + метода
        // 1 - массив параметров
        if (is_array($actor)) {
            $handler = $actor[0];
            $params = (count($actor) > 1) ? $actor[1] : [];

            //@todo: нужна проверка is_string() - но зачем?
            if (!is_string($handler)) {
                throw new CacheCallbackException("First argument of callback array is NOT a string");
            }
            
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

    } // compileCallbackHandler

}

# -eof-
