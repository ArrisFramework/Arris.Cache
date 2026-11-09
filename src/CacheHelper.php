<?php

declare(strict_types=1);

namespace Arris\Cache;

use Arris\Cache\Exceptions\CacheCallbackException;
use JsonException;
use Psr\Log\LoggerInterface;
use function array_key_exists;
use function array_filter;
use function class_exists;
use function count;
use function explode;
use function is_array;
use function is_scalar;
use function is_string;
use function mb_strpos;
use function mb_stripos;
use function method_exists;
use function strpos;

class CacheHelper
{
    /**
     * Перебирает весь двумерный массив $source и возвращает те строки, в столбце $field которых есть $pattern
     *
     * Если $pattern или $field — пустая строка, возвращается весь массив $source
     * (значение "0" пустым не считается).
     *
     * Строки без поля $field, не-массивы и значения-массивы/объекты пропускаются
     * без warning'ов.
     *
     * @param array $source
     * @param string $field
     * @param string $pattern
     * @param bool $case_sensitive
     * @return array
     */
    public static function searchHashLike(array $source, string $field, string $pattern = '', bool $case_sensitive = false):array
    {
        if ($field === '' || $pattern === '') {
            return $source;
        }

        return array_filter($source, static function ($row) use ($field, $pattern, $case_sensitive): bool {
            if (!is_array($row) || !array_key_exists($field, $row)) {
                return false;
            }

            $value = $row[ $field ];

            // null и скаляры сравниваем как строки; массивы/объекты пропускаем:
            // для них поиск подстроки не имеет смысла (и дал бы warning конверсии).
            if ($value !== null && !is_scalar($value)) {
                return false;
            }

            $haystack = (string)$value;
            $position = $case_sensitive
                ? mb_strpos($haystack, $pattern)
                : mb_stripos($haystack, $pattern);

            return $position !== false;
        });
    }
    
    /**
     * Ищет в массиве $array подмассив, ключ $key которого == $value
     * Бывший Arr::array_filter_subject
     *
     * Отсутствующий ключ никогда не совпадает (в т.ч. при поиске 0/false/''/null
     * в нестрогом режиме). Если совпадений несколько — возвращается первое.
     *
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @param bool $strict -- использовать ли строгое сравнение?
     * @return array|null
     */
    public static function searchHashAsKeyValue(array $array, string $key, mixed $value, bool $strict = false): ?array
    {
        if (empty($array)) {
            return null;
        }

        $matches = array_filter($array, static function ($sub) use ($key, $value, $strict): bool {
            if (!is_array($sub) || !array_key_exists($key, $sub)) {
                return false;
            }

            return $strict ? $sub[$key] === $value : $sub[$key] == $value;
        });

        return empty($matches) ? null : reset($matches);
    }
    
    /**
     * Сортирует двумерный массив по ключу подмассива.
     *
     * Строки без ключа $order_by, со значением null и не-массивы помещаются
     * в конец, сохраняя относительный порядок между собой.
     * Пустой $order_by — сортировка не выполняется, возвращается исходный набор.
     *
     * @param array $dataset
     * @param string $order_by
     * @param bool $strict -- строгое сравнение при определении равенства элементов
     * @return array
     */
    public static function sortHashBySubkey(array $dataset, string $order_by, bool $strict = false):array
    {
        if ($order_by === '') {
            return $dataset;
        }

        usort($dataset, static function ($left, $right) use ($order_by, $strict): int {
            $left_has_value = is_array($left) && array_key_exists($order_by, $left) && $left[$order_by] !== null;
            $right_has_value = is_array($right) && array_key_exists($order_by, $right) && $right[$order_by] !== null;

            // Строки без значения — в конец (между собой равны, sort стабилен в PHP 8+).
            if (!$left_has_value && !$right_has_value) {
                return 0;
            }
            if (!$left_has_value) {
                return 1;
            }
            if (!$right_has_value) {
                return -1;
            }

            $is_equal = $strict
                ? $left[$order_by] === $right[$order_by]
                : $left[$order_by] == $right[$order_by];

            return $is_equal ? 0 : $left[$order_by] <=> $right[$order_by];
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
    public static function raiseFlag(string $flag, int $value = 1, int $ttl = 86400): void
    {
        Cache::redisPush($flag, $value, $ttl);
    }

    /**
     * Конвертирует в JSON
     *
     * @param mixed $data
     * @return string
     * @throws JsonException
     */
    public static function jsonize(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
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
     * "Компилирует" обработчик коллбэка в пару [callable, params].
     *
     * Допустимые формы: Closure, 'function', 'Class@method', 'Class::method',
     * [handler, params]. Второй элемент массива обязан быть массивом параметров;
     * пустой handler запрещён.
     *
     * @param mixed $actor
     * @param \Psr\Log\LoggerInterface $logger
     * @return array{0: mixed, 1: array}
     * @throws CacheCallbackException
     * @todo: обновить в Arris\Router ?)
     */
    public static function compileCallbackHandler(mixed $actor, LoggerInterface $logger): array
    {
        if ($actor instanceof \Closure) {
            return [
                $actor, []
            ];
        }

        // Строка-имя ('customFunction', 'Class@method', 'Class::method')
        // нормализуется в форму массива
        if (is_string($actor)) {
            $actor = [ $actor ];
        }

        if (!is_array($actor)) {
            throw new CacheCallbackException("Callback handler MUST be a Closure, a string or an array");
        }

        // 0 - имя класса + метода
        // 1 - массив параметров
        $handler = $actor[0] ?? null;
        $params = $actor[1] ?? [];

        if ($handler === null || $handler === '') {
            throw new CacheCallbackException("Callback handler is empty");
        }

        if (!is_array($params)) {
            throw new CacheCallbackException("Second argument of callback array MUST be an array of params");
        }

        if ($handler instanceof \Closure) {
            return [
                $handler, $params
            ];
        }

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

        return [
            $actor, $params
        ];

    } // compileCallbackHandler



}