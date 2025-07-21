<?php

namespace Bot;

use Config\AppConfig;

class Logger
{
    public static function log(
        string $level,
        string $title,
        string $message ,
        array $context = [],
        bool $sendToTelegram = false
    ): void {
        $config = AppConfig::getConfig();
        $botToken = $config['bot']['token'];
        $emojis = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'error' => '❌'];
        $emoji = $emojis[strtolower($level)] ?? '📝';
        $timestamp = date('[Y-m-d H:i:s]');
        $logText = "$timestamp [$level] $title - $message";
        foreach ($context as $key => $value) {
            if (stripos($key, 'token') !== false || stripos($key, 'password') !== false) {
                $context[$key] = '[HIDDEN]';
            }
        }
        if (!empty($context)) {
            $logText .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $logDir = __DIR__ . '/../log';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/log_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logText . PHP_EOL, FILE_APPEND);
        if ($sendToTelegram  || !$sendToTelegram) {
            $contextLines = '';
            foreach ($context as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $prettyValue = "<pre>" . htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "</pre>";
                } else {
                    $prettyValue = "<code>" . htmlspecialchars((string)$value) . "</code>";
                }
                $contextLines .= "🔹 <b>" . htmlspecialchars($key) . ":</b> {$prettyValue}\n";
            }
            $telegramMessage = "$emoji <b>" . htmlspecialchars($title) . "</b>\n\n" . 
                htmlspecialchars($message) . "\n\n" . 
                $contextLines . 
                "🕒 <i>" . date('Y-m-d H:i:s') . "</i>";
            if (mb_strlen($telegramMessage) > 4000) {
                $telegramMessage = mb_substr($telegramMessage, 0, 3990) . "\n...\n📌 پیام طولانی‌تر بود!";
            }
            try {
                $ch = curl_init("https://api.telegram.org/bot$botToken/sendMessage");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_POSTFIELDS => http_build_query([
                        "chat_id" => "@mybugsram",
                        "text" => $telegramMessage,
                        "parse_mode" => "HTML",
                        "disable_web_page_preview" => true
                    ])
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Throwable $e) {
                file_put_contents($logFile, "$timestamp [error] Telegram Error - " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
        }
    }
}
