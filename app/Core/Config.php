<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $items = [];

    public static function load(array $items): void
    {
        self::$items = $items;
    }

    /** Accès en notation pointée : Config::get('db.dsn') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$items;
        foreach (explode('.', $key) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return $default;
            }
            $v = $v[$k];
        }
        return $v;
    }
}
