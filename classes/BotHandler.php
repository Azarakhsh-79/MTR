<?php

namespace Bot;

use Config\AppConfig;
use Payment\ZarinpalPaymentHandler;
use Bot\DB;     // <-- استفاده از مدیر دیتابیس جدید
use Bot\Logger; // <-- اطمینان از وجود کلاس لاگر

/**
 * کلاس اصلی برای مدیریت منطق ربات تلگرام.
 * این کلاس تمام درخواست‌ها، پیام‌ها و کالبک‌ها را مدیریت می‌کند.
 */
class BotHandler
{
    private $chatId;
    private $text;
    private $messageId;
    private $message;
    private $zarinpalPaymentHandler;
    private $botToken;
    private $botLink;

    public function __construct($chatId, $text, $messageId, $message)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->messageId = $messageId;
        $this->message = $message;

        // سازنده بسیار تمیز شده و فقط تنظیمات اصلی را مقداردهی می‌کند
        $config = AppConfig::getConfig();
        $this->botToken = $config['bot']['token'];
        $this->botLink = $config['bot']['bot_link'];
        $this->zarinpalPaymentHandler = new ZarinpalPaymentHandler();
    }

    /**
     * متد اصلی برای پردازش پیام‌های ورودی.
     */
    public function handleRequest(): void
    {
        if (isset($this->message["from"])) {
            $this->saveOrUpdateUser($this->message["from"]);
        } else {
            error_log("BotHandler::handleRequest: 'from' field is missing.");
            return;
        }

        $currentUser = DB::table('users')->findById($this->chatId);
        $state = $currentUser['state'] ?? ''; 

        try {
            if ($this->text === "/start") { 
                DB::table('users')->update($this->chatId, ['state' => '']);
                $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => "به ربات خوش آمدید! برای دیدن دستورات از /help استفاده کنید.",
                ]);
            } elseif ($state === "awaiting_name") {
                // مثالی برای مدیریت یک وضعیت خاص
                // ...
            } else {
                $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => "دستور نامشخص است. لطفاً با /start شروع کنید."
                ]);
            }
        } catch (\Throwable $th) {
            Logger::log('error', 'BotHandler::handleRequest', 'message: ' . $th->getMessage(), ['chat_id' => $this->chatId, 'text' => $this->text]);
        }
    }

   
    public function handleCallbackQuery($callbackQuery): void
    {
        $chatId = $callbackQuery["message"]["chat"]["id"] ?? null;
        if (!$chatId) return;

        if (isset($callbackQuery["from"])) {
            $this->saveOrUpdateUser($callbackQuery["from"]);
        }

        $callbackData = $callbackQuery["data"] ?? null;
        $callbackQueryId = $callbackQuery["id"] ?? null;

        // ... در اینجا منطق مربوط به پردازش دکمه‌های مختلف را پیاده‌سازی کنید ...
        // مثال:
        // if ($callbackData === 'show_profile') { ... }

        $this->sendRequest('answerCallbackQuery', ['callback_query_id' => $callbackQueryId]);
    }

    
    public function handlePreCheckoutQuery($update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $query_id = $update['pre_checkout_query']['id'];

            // به تلگرام اطلاع می‌دهیم که پرداخت معتبر است
            $this->sendRequest("answerPreCheckoutQuery", [
                'pre_checkout_query_id' => $query_id,
                'ok' => true
            ]);
        }
    }

    
    public function handleSuccessfulPayment($update): void
    {
        if (isset($update['message']['successful_payment'])) {
            $chatId = $update['message']['chat']['id'];
            $payload = $update['message']['successful_payment']['invoice_payload'];

            // ... در اینجا منطق بعد از پرداخت موفق را پیاده‌سازی کنید ...
            // مثلا افزایش موجودی کاربر در دیتابیس
            // DB::table('users')->update($chatId, ['balance' => new_balance]);

            $this->sendRequest("sendMessage", ["chat_id" => $chatId, "text" => "پرداخت شما با موفقیت انجام شد. سپاسگزاریم!"]);
        }
    }

    private function saveOrUpdateUser(array $userFromTelegram): void
    {
        $chatId = $userFromTelegram['id'];

        $existingUser = DB::table('users')->findById($chatId);


        $userData = [
            'first_name' => $userFromTelegram['first_name'],
            'last_name' => $userFromTelegram['last_name'] ?? null,
            'username' => $userFromTelegram['username'] ?? null,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($existingUser === null) {
            $userData['id'] = $chatId;
            $userData['language_code'] = $userFromTelegram['language_code'] ?? 'fa';
            $userData['created_at'] = date('Y-m-d H:i:s');
            $userData['status'] = 'active'; 
            $userData['isadmin'] = false; 
            DB::table('users')->insert($userData);
        } else {
            DB::table('users')->update($chatId, $userData);
        }
    }


    public function sendRequest($method, $data)
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/$method";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // لاگ کردن درخواست و پاسخ (برای دیباگ)
        if ($httpCode >= 400) {
            Logger::log('error', 'sendRequest failed', "Method: $method, HTTP: $httpCode", ['request' => $data, 'response' => $response]);
        }

        return json_decode($response, true);
    }
}
