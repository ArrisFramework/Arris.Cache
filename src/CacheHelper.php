<?php

namespace Arris\Cache;

use function mb_strpos;
use function array_filter;

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
    public static function searchHashAsKeyValue(array $array, string $key, string $value, bool $strict = false)
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
            
            if ($left[$order_by] == $right[$order_by]) {
                return 0;
            }
            return ($left[$order_by] < $right[$order_by]) ? -1 : 1;
        });
        return $dataset;
    }
    
}