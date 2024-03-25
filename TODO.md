Add ?

```php

public static function flush(string $key, bool $clean_redis = true)
{
    $deleted = [];
    if (strpos($key, '*') === false) {
        self::unset($key);
        if (self::$redis_connector && $clean_redis) {
            self::$redis_connector->del($key);
        }
        return $key;
    } else {
        $custom_mask = self::createMask($key);
        $custom_list = preg_grep($custom_mask, self::getAllKeys());
        foreach ($custom_list as $k) {
            $deleted[] = self::flush($k, $clean_redis);
        }
        // return $custom_mask;
        return $deleted;
    }
}



```