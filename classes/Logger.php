<?php

namespace Bot;

use Config\AppConfig;
use Throwable;

class Logger
{
    private static $config;
    private const LOG_DIR = __DIR__ . '/../log';
    private const MAX_TELEGRAM_MESSAGE_LENGTH = 4096;

    public static function log(
        string $level,
        string $title,
        string $message,
        array $context = [],
        bool $sendToTelegram = false
    ): void {
        if (self::$config === null) {
            self::$config = AppConfig::getConfig();
        }

        self::logToFile($level, $title, $message, $context);

        if ($sendToTelegram) {
            self::logToTelegram($level, $title, $message, $context);
        }
    }

    private static function logToFile(string $level, string $title, string $message, array $context): void
    {
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0775, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $logFile = self::LOG_DIR . '/log_' . date('Y-m-d') . '.log';
        $sanitizedContext = self::sanitizeContext($context);
        $logContext = !empty($sanitizedContext) ? ' | ' . json_encode($sanitizedContext, JSON_UNESCAPED_UNICODE) : '';

        $logText = "[$timestamp] [$level] $title - $message" . $logContext . PHP_EOL;

        file_put_contents($logFile, $logText, FILE_APPEND);
    }

    private static function logToTelegram(string $level, string $title, string $message, array $context): void
    {
        $botToken = self::$config['bot']['token'] ?? null;
        $logChannel = self::$config['bot']['log_channel'] ?? null;

        if (!$botToken || !$logChannel) {
            self::logToFile('error', 'Logger Error', 'Token or Log Channel not set in config.', []);
            return;
        }

        $telegramMessage = self::formatTelegramMessage($level, $title, $message, $context);

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.telegram.org/bot{$botToken}/sendMessage",
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_POSTFIELDS => [
                    "chat_id" => $logChannel,
                    "text" => $telegramMessage,
                    "parse_mode" => "HTML",
                    "disable_web_page_preview" => true
                ]
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) {
            self::logToFile('error', 'Telegram Send Error', $e->getMessage(), []);
        }
    }

    private static function formatTelegramMessage(string $level, string $title, string $message, array $context): string
    {
        $emojis = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'error' => '❌'];
        $emoji = $emojis[strtolower($level)] ?? '📝';

        $contextLines = '';
        foreach (self::sanitizeContext($context) as $key => $value) {
            $prettyValue = is_array($value) || is_object($value)
                ? '<pre>' . htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>'
                : '<code>' . htmlspecialchars((string)$value) . '</code>';
            $contextLines .= "🔹 <b>" . htmlspecialchars($key) . ":</b>\n{$prettyValue}\n";
        }

        $fullMessage = "$emoji <b>" . htmlspecialchars($title) . "</b>\n\n" .
            htmlspecialchars($message) . "\n\n" .
            $contextLines .
            "🕒 <i>" . date('Y-m-d H:i:s') . "</i>";

        if (mb_strlen($fullMessage) > self::MAX_TELEGRAM_MESSAGE_LENGTH) {
            $fullMessage = mb_substr($fullMessage, 0, self::MAX_TELEGRAM_MESSAGE_LENGTH - 100) . "\n...\n✂️ پیام برای نمایش کوتاه شد.";
        }

        return $fullMessage;
    }

    private static function sanitizeContext(array $context): array
    {
        foreach ($context as $key => &$value) {
            if (is_string($key) && (stripos($key, 'token') !== false || stripos($key, 'password') !== false)) {
                $value = '[HIDDEN]';
            }
        }
        return $context;
    }
}