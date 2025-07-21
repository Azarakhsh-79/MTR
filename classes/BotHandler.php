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
    private $callbackQueryId;

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
                $this->MainMenu();
                return;
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
        $messageId = $callbackQuery["message"]["message_id"] ?? null;
        $callbackData = $callbackQuery["data"] ?? null;
        $this->callbackQueryId = $callbackQuery["id"] ?? null;

        if (!$chatId) return;
        if (isset($callbackQuery["from"])) {
            $this->saveOrUpdateUser($callbackQuery["from"]);
        }

        try {
            if ($callbackData === 'main_menu') {
                $this->MainMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_panel_entry') {
                $this->showAdminMainMenu();
                return;
            } else {
                $this->sendRequest("answerCallbackQuery", [
                    "callback_query_id" => $this->callbackQueryId,
                    "text" => "در حال پردازش درخواست شما..."
                ]);
            }
        } catch (\Throwable $th) {
            Logger::log('error', 'BotHandler::handleCallbackQuery', 'message: ' . $th->getMessage(), ['callbackQuery' => $callbackQuery]);
            return;
        }



        $this->sendRequest('answerCallbackQuery', ['callback_query_id' => $this->callbackQueryId]);
    }

    public function MainMenu($messageId = null): void
    {
        $settings = DB::table('settings')->all();
        $menuText = $settings['main_menu_text'] ?? 'به فروشگاه ما خوش آمدید!';

        $allCategories = DB::table('categories')->all();
        $categoryButtons = [];

        if (!empty($allCategories)) {
            $activeCategories = [];
            foreach ($allCategories as $category) {
                if (isset($category['parent_id']) && $category['parent_id'] == 0 && !empty($category['is_active'])) {
                    $activeCategories[] = $category;
                }
            }
            usort($activeCategories, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

            $row = [];
            foreach ($activeCategories as $category) {
                $row[] = ['text' => $category['name'], 'callback_data' => 'category_' . $category['id']];
                if (count($row) == 2) {
                    $categoryButtons[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $categoryButtons[] = $row;
            }
        }

        $user = DB::table('users')->findById($this->chatId);
        if ($user && !empty($user['is_admin'])) {
            $categoryButtons[] = [['text' => '🔐 ورود به پنل مدیریت', 'callback_data' => 'admin_panel_entry']];
        }

        $keyboard = ['inline_keyboard' => $categoryButtons];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $menuText,
            'reply_markup' =>  json_encode($keyboard),
            'parse_mode' => 'HTML',
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }
    public function showAdminMainMenu(): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🛍 مدیریت دسته‌بندی‌ها', 'callback_data' => 'admin_manage_categories']],
                [['text' => '📝 مدیریت محصولات', 'callback_data' => 'admin_manage_products']],
                [['text' => '⚙️ تنظیمات ربات', 'callback_data' => 'admin_bot_settings']],
                [['text' => '📊 آمار و گزارشات', 'callback_data' => 'admin_reports']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_meno']]
            ]
        ];

        $this->sendRequest("sendMessage", [
            "chat_id" => $this->chatId,
            "text" => "پنل مدیریت ربات:",
            "reply_markup" => $keyboard
        ]);
    }


    public function Alert($message, $alert = true): void
    {
        if ($this->callbackQueryId) {
            $data = [
                'callback_query_id' => $this->callbackQueryId,
                'text' => $message,
                'show_alert' => $alert
            ];
            $this->sendRequest("answerCallbackQuery", $data);
        } else {
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $message,
            ]);
        }
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
