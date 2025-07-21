<?php

namespace Bot;

use Bot\JsonDB;
class DB
{
    private static $instances = [];

    public static function table(string $tableName): JsonDB
    {
        if (!isset(self::$instances[$tableName])) {
            self::$instances[$tableName] = new JsonDB($tableName);
        }
        return self::$instances[$tableName];
    }

    private function __construct()
    {
    }
}