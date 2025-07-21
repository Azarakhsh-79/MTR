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
    public function deleteMessage($messageId, $delay = 0)
    {
        if (!$messageId) {
            return false;
        }

        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $messageId
        ];
        sleep($delay);
        $response = $this->sendRequest("deleteMessage", $data);
    }
    public function deleteMessages(array $messageIds): bool
    {
        if (empty($messageIds) || count($messageIds) > 100) {
            return false;
        }
        $data = [
            'chat_id' => $this->chatId,
            'message_ids' => $messageIds
        ];

        $response = $this->sendRequest("deleteMessages", $data);
        DB::table('users')->unsetKey($this->chatId, 'messages_ids');
        return $response['ok'] ?? false;
    }
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
            } elseif ($state === "editing_category_name") {
                $categoryName = trim($this->text);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته‌بندی نمی‌تواند خالی باشد.");
                    return;
                }
                $categoryId = $currentUser['editing_category_id'] ?? null;
                if (!$categoryId) {
                    $this->Alert("خطا: شناسه دسته‌بندی مشخص نشده است.");
                    return;
                }
                $res = DB::table('categories')->update($categoryId, ['name' => $categoryName]);
                if ($res) {
                    DB::table('users')->update($this->chatId, ['state' => '']);
                    // ساخت دوباره پیام 
                    $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $this->messageId,
                        "text" => "دسته‌بندی با موفقیت ویرایش شد: {$categoryName}",
                        "reply_markup" => [
                            "inline_keyboard" => [
                                [
                                    ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_category_' . $categoryId],
                                    ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_category_' . $categoryId]
                                ]
                            ]
                        ]
                    ]);
                } else {
                    $this->Alert("خطا در ویرایش دسته‌بندی. لطفاً دوباره تلاش کنید.");
                }
                return;
            } elseif ($state === "adding_category_name") {
                $categoryName = trim($this->text);
                if (empty($categoryName)) {
                    $this->sendRequest("sendMessage", [
                        "chat_id" => $this->chatId,
                        "text" => "نام دسته‌بندی نمی‌تواند خالی باشد."
                    ]);
                    return;
                }
                $res = $this->createNewCategory($categoryName);
                $messageId = $this->getMessageId($this->chatId);
                if ($res) {
                    $this->deleteMessage($this->messageId);
                    DB::table('users')->update($this->chatId, ['state' => '']);

                    $this->Alert("دسته‌بندی جدید با موفقیت ایجاد شد.");
                    $this->showCategoryManagementMenu($messageId ?? null);
                } else {
                    $this->Alert("خطا در ایجاد دسته‌بندی. لطفاً دوباره تلاش کنید.");
                    $this->MainMenu($messageId ?? null);
                }
                return;
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
                $this->showAdminMainMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_manage_categories') {
                $this->showCategoryManagementMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_category_list') {
                $this->showCategoryList($messageId);
                return;
            } elseif (strpos($callbackData, 'category_') === 0) {
                $categoryId = str_replace('category_', '', $callbackData);
                $this->sendRequest("answerCallbackQuery", [
                    "callback_query_id" => $this->callbackQueryId,
                    "text" => "دسته‌بندی با ID {$categoryId} انتخاب شد."
                ]);
                // در اینجا می‌توانید منطق نمایش محصولات یا اطلاعات دسته‌بندی را اضافه کنید
            } elseif (strpos($callbackData, 'admin_edit_category_') === 0) {
                $categoryId = str_replace('admin_edit_category_', '', $callbackData);
                $category = DB::table('categories')->findById($categoryId);
                if ($category) {
                    DB::table('users')->update($this->chatId, ['state' => 'editing_category_name']);
                    $res = $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $messageId,
                        "text" => "لطفاً نام جدید دسته‌بندی را وارد کنید: {$category['name']}",
                        "reply_markup" =>
                        [
                            "inline_keyboard" => [
                                [["text" => "🔙 بازگشت", "callback_data" => "admin_manage_categories"]]
                            ]
                        ]
                    ]);
                    $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                } else {
                    $this->alert("دسته‌بندی یافت نشد.");
                }
            } elseif (strpos($callbackData, 'admin_delete_category_') === 0) {
                // منطق حذف دسته‌بندی
            } elseif ($callbackData === 'admin_add_category') {
                DB::table('users')->update($this->chatId, ['state' => 'adding_category_name']);
                $res = $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    "message_id" => $messageId,
                    "text" => "لطفاً نام دسته‌بندی جدید را وارد کنید:",
                    "reply_markup" =>
                    [
                        "inline_keyboard" => [
                            [["text" => "🔙 بازگشت", "callback_data" => "admin_panel_entry"]]
                        ]
                    ]
                ]);
                $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
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
    public function showAdminMainMenu($messageId = null): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🛍 مدیریت دسته‌بندی‌ها', 'callback_data' => 'admin_manage_categories']],
                [['text' => '📝 مدیریت محصولات', 'callback_data' => 'admin_manage_products']],
                [['text' => '⚙️ تنظیمات ربات', 'callback_data' => 'admin_bot_settings']],
                [['text' => '📊 آمار و گزارشات', 'callback_data' => 'admin_reports']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "پنل مدیریت ربات:",
                "reply_markup" => json_encode($keyboard)
            ]);
            return;
        } else {
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => "پنل مدیریت ربات:",
                "reply_markup" => $keyboard
            ]);
        }
    }
    public function showCategoryList($messageId = null): void
    {
        $this->Alert("در حال ارسال لیست دسته‌بندی‌ها...", false);

        $allCategories = DB::table('categories')->all();

        if (empty($allCategories)) {
            $this->Alert("هیچ دسته‌بندی‌ای وجود ندارد.");
            return;
        }
        $messageId = $this->getMessageId($this->chatId);
        $res = $this->sendRequest("editMessageText", [
            "chat_id" => $this->chatId,
            "message_id" => $messageId,
            "text" => "⏳ در حال ارسال لیست دسته‌بندی‌ها...",
            "reply_markup" => ['inline_keyboard' => []]
        ]);
        $messageIds = [];
        if (isset($res['result']['message_id'])) {
            $messageIds[] = $res['result']['message_id'];
        }
        foreach ($allCategories as $category) {
            $categoryId = $category['id'];
            $categoryName = $category['name'];

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_category_' . $categoryId],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_category_' . $categoryId]
                    ]
                ]
            ];

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => "دسته: {$categoryName}",
                "parse_mode" => "Markdown",
                "reply_markup" => $keyboard
            ]);
            if (isset($res['result']['message_id'])) {
                $messageIds[] = $res['result']['message_id'];
            }
        }


        $this->sendRequest("sendMessage", [
            "chat_id" => $this->chatId,
            "text" => "--- پایان لیست ---",
            "reply_markup" => [
                'inline_keyboard' => [
                    [['text' => '⬅️ بازگشت ', 'callback_data' => 'admin_manage_categories']]
                ]
            ]
        ]);

        DB::table('users')->update($this->chatId, ['messages_ids' => $messageIds]);
    }
    public function showCategoryManagementMenu($messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (isset($user['messages_ids'])) {
            $this->deleteMessages($user['messages_ids']);
        }
        $text = "بخش مدیریت دسته‌بندی‌ها. لطفاً یک گزینه را انتخاب کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن دسته‌بندی جدید', 'callback_data' => 'admin_add_category']],
                [['text' => '📜 لیست دسته‌بندی‌ها', 'callback_data' => 'admin_category_list']],
                [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
            ]
        ];

        if ($messageId) {
            $res =  $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => $text,
                "reply_markup" => json_encode($keyboard)
            ]);
        } else {
            $res =   $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $text,
                "reply_markup" => json_encode($keyboard)
            ]);
        }
        if (isset($res['result']['message_id'])) {
            $this->saveMessageId($this->chatId, $res['result']['message_id']);
        }
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
            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $message,
            ]);
            $this->deleteMessage($res['result']['message_id'] ?? null, 3);
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
            $userData['is_admin'] = false;
            DB::table('users')->insert($userData);
        } else {
            DB::table('users')->update($chatId, $userData);
        }
    }
    public function createNewCategory(string $name)
    {
        $categories = DB::table('categories')->all();

        $newId = 1;
        $newSortOrder = 0;
        if (!empty($categories)) {
            $ids = array_keys($categories);
            $sortOrders = array_column($categories, 'sort_order');
            $newId = max($ids) + 1;
            $newSortOrder = max($sortOrders) + 1;
        }

        $newCategory = [
            'id' => $newId,
            'name' => $name,
            'parent_id' => 0,
            'is_active' => true,
            'sort_order' => $newSortOrder
        ];

        $res = DB::table('categories')->insert($newCategory);
        if ($res) {
            return $res;
        } else {
            return null;
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


        if ($httpCode >= 400) {
            Logger::log('error', 'sendRequest failed', "Method: $method, HTTP: $httpCode", ['request' => $data, 'response' => $response]);
        }
        $response = json_decode($response, true);
        //  Logger::log('info', 'sendRequest success', "Method: $method, HTTP: $httpCode", $response, true);
        return $response;
    }
    public function saveMessageId($chatId, $messageId)
    {
        if (!$chatId || !$messageId) {
            Logger::log('error', 'saveMessageId failed', 'Chat ID or Message ID is missing', ['chat_id' => $chatId, 'message_id' => $messageId]);
            return false;
        }

        $data = [
            'message_id' => $messageId,
        ];

        $result = DB::table('users')->update($chatId, $data);
        if ($result) {
            return true;
        } else {
            Logger::log('error', 'saveMessageId failed', 'Failed to save Message ID', ['chat_id' => $chatId, 'message_id' => $messageId]);
            return false;
        }
    }
    public function getMessageId($chatId)
    {
        if (!$chatId) {
            return null;
        }

        $message = DB::table('users')->findById($chatId);
        if ($message && isset($message['message_id'])) {
            return $message['message_id'];
        } else {
            Logger::log('error', 'getMessageId failed', 'Message ID not found for Chat ID', ['chat_id' => $chatId]);
            return null;
        }
    }
}
