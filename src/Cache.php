<?php

namespace Arris\Cache;

use PDO;
use JsonException;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Arris\Cache\Exceptions\CacheDatabaseException;
use Arris\Cache\Exceptions\CacheCallbackException;
use Arris\Cache\Exceptions\RedisClientException;

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
     * @var PDO|null $pdo
     */
    private static $pdo;

    /**
     * @var
     */
    public static $log_rules_define = [];

    /**
     * @var array
     */
    /*private static $connection_options = [];*/

    /*private static $connectors = [];*/

    /**
     * @var array
     */
    public static $repository = [];

    public static function init(array $credentials = [], array $rules = [], $PDO = null, LoggerInterface $logger = null)
    {
        self::$logger = is_null($logger) ? new NullLogger() : $logger;
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
        /*self::$connection_options = */$options = self::overrideDefaults($options, $credentials);

        if ($options['enabled']) {
            try {
                self::$redis_connector = new RedisClient($options['host'], $options['port'], $options['timeout'], $options['persistent'], $options['database'], $options['password']);
                self::$redis_connector->connect();
                self::$is_redis_connected = true;
            } catch (RedisClientException $e){
            }
        }

        if (!is_null($PDO)) {
            $PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        // теперь перебираем $rules
        foreach ($rules as $rule_name => $rule_definition) {
            $message = self::defineRule($rule_name, $rule_definition);
            self::$log_rules_define[ $rule_name ] = $message;

            if ($logger instanceof LoggerInterface) {
                $logger->debug($message);
            }
        }

    } // init
    
    public static function getConnector()
    {
        return self::$redis_connector;
    }

    /*public static function switchDatabase($database):RedisClient
    {
        $options = self::$connection_options;

        if ($options['database'] !== $database) {
            $connector = new RedisClient($options['host'], $options['port'], $options['timeout'], $options['persistent'], $database, $options['password']);
            $connector->connect();
        } else {
            $connector = self::$redis_connector;
        }

        return $connector;
    }*/

    public static function addRule(string $rule_name, $rule_definition):string
    {
        $message = self::defineRule($rule_name, $rule_definition);
        self::$log_rules_define[ $rule_name ] = $message;

        if (self::$logger instanceof LoggerInterface) {
            self::$logger->debug($message);
        }
        return $message;
    }

    public static function getAllLocalKeys():array
    {
        return array_keys(self::$repository);
    }

    public static function getAllRedisKeys():array
    {
        if (!self::$is_redis_connected) {
            self::$logger->debug("[getAllRedisKeys] Redis not connected");
            return [];
        }

        $keys = self::$redis_connector->keys('*');
        $keys_count = count($keys);

        self::$logger->debug("[getAllRedisKeys] Returned {$keys_count} key(s)");

        return $keys;
    }

    public static function getAllKeys(bool $use_keys_from_redis):array
    {
        if (!self::$is_redis_connected) {
            self::$logger->debug("[getAllKeys] Redis not connected");
            return [];
        }

        $keys = $use_keys_from_redis ? self::$redis_connector->keys('*') : array_keys(self::$repository);
        $keys_count = count($keys);

        self::$logger->debug("[getAllKeys] Returned {$keys_count} key(s)");

        return $keys;
    }

    public static function get(string $key, $default = null)
    {
        if (self::check($key)) {
            self::$logger->debug("Received `{$key}` from cache");
            return self::$repository[ $key ];
        }
        return $default;
    }

    public static function set(string $key, $data)
    {
        self::unset($key);
        self::$repository[ $key ] = $data;
    }

    public static function check(string $key): bool
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

    public static function flush(string $key, bool $clean_redis = true):string
    {
        if (strpos($key, '*') === false) {
            self::unset($key);
            if (self::$redis_connector && $clean_redis) {
                self::$redis_connector->del($key);
            }
            return $key;
        } else {
            $custom_mask = self::createMask($key);
            $all_keys = $clean_redis ? self::getAllRedisKeys() : self::getAllLocalKeys();
            $custom_list = preg_grep($custom_mask, $all_keys);
            foreach ($custom_list as $k) {
                self::flush($k, $clean_redis);
            }
            return $custom_mask;
        }
    }
    
    public static function redisFetch(string $key_name, bool $use_json_decode = true)
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
    
    public static function redisPush(string $key_name, $data, int $ttl = 0):bool
    {
        if (self::$is_redis_connected === false) {
            return false;
        }
    
        self::$redis_connector->set($key_name, CacheHelper::jsonize($data));
        
        if ($ttl > 0) {
            self::$redis_connector->expire($key_name, $ttl);
        }
        
        if (self::$redis_connector->exists($key_name)) {
            return true;
        }
        
        return false;
    }
    
    public static function redisDel(string $key_name)
    {
        if (self::$is_redis_connected === false) {
            return false;
        }
        
        $deleted = [];

        if ($key_name === '*') {
            self::$redis_connector->flushDb();
            $deleted = [ '*' ];
        } elseif (strpos($key_name, '*') === false) {
            self::$redis_connector->del($key_name);
            $deleted = [ $key_name ];
        } else {
            $custom_mask = self::createMask($key_name);
            $custom_list = preg_grep($custom_mask, self::$redis_connector->keys('*'));

            foreach ($custom_list as $k) {
                $deleted[] = self::redisDel($k);
            }

        }
        return $deleted;

        // return !(self::$redis_connector->exists($key_name));
    }
    
    /**
     * Проверяет существование ключа в редисе
     *
     * @param string $keyname
     * @return bool
     */
    public static function redisCheck(string $keyname):bool
    {
        if (self::$is_redis_connected === false) {
            return false;
        }
        
        return self::$redis_connector->exists($keyname);
    }
    
    // работа со счетчиками + добавить в репозиторий!
    
    public static function addCounter(string $key, int $initial = 0, int $ttl = 0):int
    {
        self::set($key, $initial);
        if (self::$is_redis_connected) {
            self::$redis_connector->set($key, $initial);
            
            if ($ttl > 0) {
                self::$redis_connector->expire($key, $ttl);
            }
            
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
    
    public static function getCounter(string $key, int $default = 0):int
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
                return "[INFO] Loaded `{$rule_name}` from redis, stored to cache";
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
            $message = "[ERROR] Key found, but action is empty";
            self::set($rule_name, null);
            return $message;
        }

        switch ($rule_definition['source']) {
            case self::RULE_SOURCE_SQL: {
                // коннекта к БД нет: кладем в репозиторий null и продолжаем
                if (is_null(self::$pdo)) {
                    $message = '[ERROR] Key not found, Action is SQL, but PDO not connected';
                    self::set($rule_name, null);
                    return $message;
                }

                try {
                    $sth = self::$pdo->query($rule_definition['action']);
                    $data = $sth->fetchAll();
                    $message = "Data for `{$rule_name}` fetched from DB";

                } catch (\PDOException $e) {
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

                break;
            }
            case self::RULE_SOURCE_RAW: {
                $data = $rule_definition['data'];
                $message = "Data for `{$rule_name}` fetched as RAW data";

                break;
            }

            // case self::RULE_SOURCE_CALLBACK
            default: {
                [$actor, $params] = self::compileCallbackHandler($rule_definition['action']);

                try {
                    $data = call_user_func_array($actor, $params);
                    $message = "Data for `{$rule_name}` fetched from callback";

                } catch (\PDOException $e) {
                    throw new CacheDatabaseException("[ERROR] Rule [{$rule_name}] throws PDO Error: " . $e->getMessage(), (int)$e->getCode());
                }

            }
        }

        // кладем результат в репозиторий
        self::set($rule_name, $data);

        // и в редис, если он запущен
        if (self::$is_redis_connected) {
            self::$redis_connector->set($rule_name, CacheHelper::jsonize($data));
            if ($ttl > 0) {
                self::$redis_connector->expire($rule_name, $ttl);
            }
            $message .= ", stored to cache, saved to redis, TTL: {$ttl} seconds";
        } else {
            $message .= ", stored to cache, redis disabled";
        }

        return $message;
    }
    
    public static function isRedisConnected():bool
    {
        return self::$is_redis_connected;
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
    
    /**
     * @param $mask
     * @return string
     */
    private static function createMask($mask)
    {
        $mask = str_replace('**', '*', $mask);
        $mask = str_replace("\\", '\\\\', $mask); // должно быть первым
        $mask = str_replace('/', '\/', $mask);
        $mask = str_replace('.', '\.', $mask);
        $mask = str_replace('*', '.*', $mask);
        $mask = str_replace('[', '\[', $mask);
        $mask = str_replace(']', '\]', $mask);
        
        return '/^' . $mask . '/m';
    }

}

# -eof-
