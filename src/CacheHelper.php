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
    
}