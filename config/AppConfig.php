<?php

namespace Config;

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;

class AppConfig
{
    private static $config = [];

    public static function load(): void
    {
        if (!empty(self::$config)) {
            return;
        }

        try {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
            $dotenv->safeLoad();

            self::$config = [
                'database' => [
                    'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'localhost',
                    'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? '',
                    'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? '',
                    'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? '',
                ],
                'bot' => [
                    'token' => $_ENV['BOT_TOKEN'] ?? $_SERVER['BOT_TOKEN'],
                    'merchant_id' => $_ENV['MERCHANT_ID'],
                    'bot_link' => $_ENV['BOT_LINK'],
                ],
                'ai' => [
                    'gpt' => [
                        'api_key' => $_ENV['GPT_API_KEY'] ?? '',
                        'model' => $_ENV['GPT_MODEL'] ?? 'gpt-4',
                        'temperature' => $_ENV['GPT_TEMPERATURE'] ?? 0.7,
                    ],
                    'deepseek' => [
                        'api_key' => $_ENV['DEEPSEEK_API_KEY'] ?? 'sk-58148101dd31423b9df1329c9c8b5d13',
                        'model' => $_ENV['DEEPSEEK_MODEL'] ?? 'deepseek-chat',
                        'temperature' => $_ENV['DEEPSEEK_TEMPERATURE'] ?? 0.7,
                    ],
                    'qwen' => [
                        'api_key' => $_ENV['QWEN_API_KEY'] ?? '',
                        'model' => $_ENV['QWEN_MODEL'] ?? 'qwen-chat',
                        'temperature' => $_ENV['QWEN_TEMPERATURE'] ?? 0.7,
                    ]
                ]
            ];

        } catch (InvalidPathException $e) {
            error_log("⚠️ Warning: .env file not found in project root.");
        }
    }

    public static function getConfig(): array
    {
        if (empty(self::$config)) {
            self::load();
        }
        return self::$config;
    }
}

AppConfig::load();
