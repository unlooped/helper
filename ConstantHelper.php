<?php

namespace Unlooped\Helper;

class ConstantHelper {

    private static $cache = [];

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
                return StringHelper::startsWith($el, $startWith . '_');
            }, ARRAY_FILTER_USE_KEY);
            $fr = array_combine($res, $res);
        } catch (\ReflectionException $e) {
        }

        self::$cache[$cacheKey] = $fr;

        return $fr;
    }
}
