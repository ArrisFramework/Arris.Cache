<?php

namespace Arris\Cache;

use Arris\Cache\Exceptions\CacheCallbackException;
use JsonException;
use function class_exists;
use function count;
use function explode;
use function is_string;
use function mb_strpos;
use function array_filter;
use function method_exists;
use function strpos;

class CacheHelper
{
    /**
     * Перебирает весь двумерный массив $source и возвращает те строки, в столбце $field которых есть $pattern
     *
     * Если $pattern пуст - возвращается весь массив $source
     *
     * @param array $source
     * @param string $field
     * @param string $pattern
     * @param bool $case_sensitive
     * @return array
     */
    public static function searchHashLike(array $source, string $field, string $pattern = '', bool $case_sensitive = false):array
    {
        if (empty($pattern) || empty($field)) {
            return $source;
        }
        
        if ($case_sensitive) {
            return array_filter($source, static function($row) use ($field, $pattern){
                return mb_strpos($row[ $field ], $pattern) !== false;
            });
        } else {
            return array_filter($source, static function($row) use ($field, $pattern){
                return mb_stripos( $row[ $field ], $pattern ) !== false;
            });
        }
    }
    
    /**
     * Ищет в массиве $array подмассив, ключ $key которого == $value
     * Бывший Arr::array_filter_subject
     *
     * @param array $array
     * @param string $key
     * @param string $value
     * @param bool $strict -- использовать ли строгое сравнение?
     * @return array|null
     */
    public static function searchHashAsKeyValue(array $array, string $key, string $value, bool $strict = false): ?array
    {
        if (empty($array)) {
            return null;
        }
        if ($strict) {
            $r = array_filter($array, static function($sub) use ($value, $key) {
                return $sub[$key] === $value;
            });
        } else {
            $r = array_filter($array, static function($sub) use ($value, $key) {
                return $sub[$key] == $value;
            });
        }
        
        if (!empty($r)) {
            return array_pop($r);
        }
        return null;
    }
    
    /**
     * Сортирует двумерный массив по ключу подмассива
     *
     * @todo: требуется тестирование потому что, похоже, не работает
     *
     * @param array $dataset
     * @param string $order_by
     * @param bool $strict
     * @return array
     */
    public static function sortHashBySubkey(array $dataset, string $order_by, bool $strict = false):array
    {
        usort($dataset, static function ($left, $right) use ($order_by, $strict){
            if (!isset($left[$order_by], $right[$order_by])) {
                return 0;
            }
            
            if ($strict) {
                if ($left[$order_by] === $right[$order_by]) {
                    return 0;
                }
            } else {
                if ($left[$order_by] == $right[$order_by]) {
                    return 0;
                }
            }
            
            return ($left[$order_by] < $right[$order_by]) ? -1 : 1;
        });
        return $dataset;
    }
    
    /**
     * Устанавливает флаг
     *
     * @param string $flag
     * @param int $value
     * @param int $ttl
     * @throws JsonException
     */
    public static function raiseFlag(string $flag, int $value = 1, $ttl = 86400)
    {
        Cache::redisPush($flag, $value, $ttl);
    }

    /**
     * Конвертирует в JSON
     *
     * @param $data
     * @return false|string
     * @throws JsonException
     */
    public static function jsonize($data)
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Перезаписывает набор дефолтных значений на основе переданного списка опций
     * @todo: -> Arris helpers
     *
     * @param $defaults
     * @param $options
     * @return mixed
     */
    public static function overrideDefaults($defaults, $options): mixed
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
     * Хелпер: извлекает значение булевой опции из редиса.
     * Опция принимает только три допустимых значения: <not exist>, 0, 1
     *
     * @param string $option
     * @param int $if_present
     * @param int $if_not_present_or_zero
     * @return int
     * @throws JsonException
     */
    public static function fetchOptionBool(string $option = '', int $if_present = 1, int $if_not_present_or_zero = 0):int
    {
        if (empty($option)) {
            return $if_not_present_or_zero;
        }

        // редис ключ может не существовать или содержать 0 или 1
        // если он не существует - условие ложно, вернем $if_not_present
        // если он существует - приводим его к bool
        // 0 приводится к false - вернем $if_not_present
        // 1 приводится к true - вернем $if_present

        return (Cache::redisCheck($option) && (bool)Cache::redisFetch($option)) ? $if_present : $if_not_present_or_zero;
    }

    /**
     * "Компилирует" параметры коллбэка
     * (@param $actor
     * @param $logger
     * @return array
     * @todo: обновить в Arris\Router ?)
     *
     */
    public static function compileCallbackHandler($actor, $logger): array
    {
        if ($actor instanceof \Closure) {
            return [
                $actor, []
            ];
        }

        $params = null;

        // 0 - имя класса + метода
        // 1 - массив параметров
        if (is_array($actor)) {
            $handler = $actor[0];
            $params
                = count($actor) > 1
                ? $actor[1]
                : [];

            if (!is_string($handler)) {
                throw new CacheCallbackException("First argument of callback array is NOT a string");
            }

            if (strpos($handler, '@') > 0) {
                // dynamic class
                [$class, $method] = explode('@', $handler, 2);

                if (!class_exists($class)) {
                    $logger->error("Class {$class} not defined.", [ $class ]);
                    throw new CacheCallbackException("Class {$class} not defined.", 500);
                }

                if (!method_exists($class, $method)) {
                    $logger->error("Method {$method} not declared at {$class} class.", [ $class, $method ]);
                    throw new CacheCallbackException("Method {$method} not declared at {$class} class", 500);
                }

                $actor = [ new $class, $method ];
            } elseif (strpos($handler, '::') > 0) {
                [$class, $method] = explode('::', $handler, 2);

                if (!class_exists($class)) {
                    $logger->error("Class {$class} not defined.", [ $class ]);
                    throw new CacheCallbackException("Class {$class} not defined.", 500);
                }

                if (!method_exists($class, $method)) {
                    $logger->error("Static method {$method} not declared at {$class} class.", [ $class, $method ]);
                    throw new CacheCallbackException("Static method {$method} not declared at {$class} class", 500);
                }

                $actor = [ $class, $method ];
            } else {
                // function
                if (!function_exists($handler)){
                    $logger->error("Handler function {$handler} not found", [ ]);
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