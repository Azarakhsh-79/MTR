<?php

namespace Bot;

use Bot\Jsondb;
class DB
{
    private static $instances = [];

    public static function table(string $tableName): Jsondb
    {
        if (!isset(self::$instances[$tableName])) {
            self::$instances[$tableName] = new Jsondb($tableName);
        }
        return self::$instances[$tableName];
    }

    private function __construct()
    {
    }
}