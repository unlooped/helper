<?php

namespace Unlooped\Helper;

use function Symfony\Component\String\u;

class ConstantHelper {

    private static array $cache = [];

    public static function getList(string $startWith): array
    {
        $class = debug_backtrace(false, 2)[1]['class'];

        return self::getListForClass($class, $startWith);
    }

    public static function getListForClass(string $class, string $startWith): array
    {
        $cacheKey = $class.'_'.$startWith;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $fr = [];
        try {
            $oClass = new \ReflectionClass($class);
            $res = array_filter($oClass->getConstants(), static function($el) use ($startWith) {
                return u($el)->startsWith($startWith . '_');
            }, ARRAY_FILTER_USE_KEY);
            $fr = array_combine($res, $res);
        } catch (\ReflectionException $e) {
        }

        self::$cache[$cacheKey] = $fr;

        return $fr;
    }
}
