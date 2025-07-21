<?php
require_once __DIR__ . '/config/AppConfig.php';

use Config\AppConfig;

$config = AppConfig::getConfig();
$db = $config['database'];

$mysqli = new mysqli($db['host'], $db['username'], $db['password'], $db['database']);
if ($mysqli->connect_errno) {
    die("<b>خطا در اتصال به دیتابیس:</b> " . $mysqli->connect_error);
}


$sqlFile = __DIR__ . '/sql/01_database_schema.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    die("<b>فایل SQL پیدا نشد.</b>");
}

if ($mysqli->multi_query($sql)) {
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->next_result());
    echo "<b>✅ ساختار دیتابیس و جداول با موفقیت ساخته شد.</b><br>";
} else {
    echo "<b>❌ خطا در اجرای SQL:</b> " . $mysqli->error;
}

$mysqli->close();