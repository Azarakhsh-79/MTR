<?php

namespace Bot;

use Config\AppConfig;
use Payment\ZarinpalPaymentHandler;
use Bot\DB;     // <-- استفاده از مدیر دیتابیس جدید
use Bot\Logger; // <-- اطمینان از وجود کلاس لاگر


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
            }


            if (in_array($state, ['adding_product_name', 'adding_product_description', 'adding_product_count', 'adding_product_price', 'adding_product_photo'])) {
                $this->handleProductCreationSteps();
                return;
            }

            if (str_starts_with($state, 'editing_category_name_')) {
                $categoryName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته‌بندی نمی‌تواند خالی باشد.");
                    return;
                }
                $categoryId = str_replace('editing_category_name_', '', $state);
                if (!$categoryId) {
                    $this->Alert("خطا: شناسه دسته‌بندی مشخص نشده است.");
                    return;
                }
                $res = DB::table('categories')->update($categoryId, ['name' => $categoryName]);
                if ($res) {
                    DB::table('users')->update($this->chatId, ['state' => '']);
                    $messageId = $this->getMessageId($this->chatId);
                    $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $messageId,
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
                    $this->Alert("نام دسته‌بندی نمی‌تواند خالی باشد.");
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
            } else if (strpos($callbackData, 'list_products_cat_') === 0) {

                sscanf($callbackData, "list_products_cat_%d_page_%d", $categoryId, $page);

                if ($categoryId && $page) {
                    $this->showProductListByCategory($categoryId, $page, $messageId);
                }
                return;
            } elseif (strpos($callbackData, 'product_creation_back_to_') === 0) {
                $targetState = str_replace('product_creation_back_to_', '', $callbackData);
                DB::table('users')->update($this->chatId, ['state' => 'adding_product_' . $targetState]);

                $text = "";
                $reply_markup = [];

                switch ('adding_product_' . $targetState) {
                    case 'adding_product_name':
                        $text = "لطفاً نام محصول را مجدداً وارد کنید:";
                        $reply_markup = [
                            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]
                        ];
                        break;
                    case 'adding_product_description':
                        $text = "لطفاً توضیحات محصول را مجدداً وارد کنید:";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_name'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                    case 'adding_product_count':
                        $text = "لطفاً تعداد موجودی محصول را مجدداً وارد کنید (فقط عدد انگلیسی):";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_description'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                    case 'adding_product_price':
                        $text = "لطفاً قیمت محصول را مجدداً وارد کنید (فقط عدد انگلیسی و به تومان):";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_count'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                }

                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                    'reply_markup' => $reply_markup
                ]);
                return;
            } else if ($callbackData === 'product_confirm_save') {
                $user = DB::table('users')->findById($this->chatId);
                $stateData = json_decode($user['state_data'] ?? '{}', true);

                $this->createNewProduct($stateData);

                DB::table('users')->unsetKey($this->chatId, 'state');
                DB::table('users')->unsetKey($this->chatId, 'state_data');

                $this->Alert("✅ محصول با موفقیت ذخیره شد!");
                $this->deleteMessage($messageId); // پیام پیش‌نمایش را حذف کن
                $this->showProductManagementMenu(null); // منو را به عنوان پیام جدید بفرست

                return;
            } elseif ($callbackData === 'product_confirm_cancel') {
                DB::table('users')->unsetKey($this->chatId, 'state');
                DB::table('users')->unsetKey($this->chatId, 'state_data');

                $this->Alert("❌ عملیات افزودن محصول لغو شد.");
                $this->deleteMessage($messageId);
                $this->showProductManagementMenu(null);
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
            } elseif (strpos($callbackData, 'admin_edit_category_') === 0) {
                $categoryId = str_replace('admin_edit_category_', '', $callbackData);
                $category = DB::table('categories')->findById($categoryId);
                if ($category) {
                    DB::table('users')->update($this->chatId, ['state' => "editing_category_name_{$categoryId}"]);
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
                $categoryId = str_replace('admin_delete_category_', '', $callbackData);
                $category = DB::table('categories')->findById($categoryId);
                if (!$category) {
                    $this->alert("دسته‌بندی یافت نشد.");
                    return;
                }
                $res = DB::table('categories')->delete($categoryId);
                if ($res) {
                    $this->Alert("دسته‌بندی با موفقیت حذف شد.");
                    $this->deleteMessage($messageId);
                } else {
                    $this->Alert("خطا در حذف دسته‌بندی. لطفاً دوباره تلاش کنید.");
                }
            }
            if (strpos($callbackData, 'product_cat_select_') === 0) {
                $categoryId = (int)str_replace('product_cat_select_', '', $callbackData);

                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_name',
                    'state_data' => json_encode(['category_id' => $categoryId])
                ]);

                $res = $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ دسته‌بندی انتخاب شد.\n\nحالا لطفاً نام محصول را وارد کنید:",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => [
                        'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]
                    ]
                ]);
                $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                return;
            } elseif ($callbackData === 'admin_manage_products') {
                $user = DB::table('users')->findById($this->chatId);
                if ($user['state'] != null) {
                    DB::table('users')->update($this->chatId, ['state' => null, 'state_data' => null]);
                }
                $this->showProductManagementMenu($messageId);
            } elseif ($callbackData === 'admin_add_product') {
                $this->promptForProductCategory($messageId);
            } elseif ($callbackData === 'admin_product_list') {
                $this->promptUserForCategorySelection($messageId);
            } elseif (strpos($callbackData, 'admin_edit_product_') === 0) {
                $productId = str_replace('admin_edit_product_', '', $callbackData);
            } elseif ($callbackData === 'admin_bot_settings') {
                $this->Alert("این بخش هنوز آماده نیست.");
            } elseif ($callbackData === 'admin_reports') {
                $this->Alert("این بخش هنوز آماده نیست.");
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
        // Logger::log('info', 'sendRequest success', "Method: $method, HTTP: $httpCode", $response, true);
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

    public function showProductManagementMenu($messageId = null): void
    {
        $text = "بخش مدیریت محصولات. لطفاً یک گزینه را انتخاب کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن محصول جدید', 'callback_data' => 'admin_add_product']],
                [['text' => '📜 لیست محصولات', 'callback_data' => 'admin_product_list']],
                [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
            ]
        ];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        }
    }



    public function showProductList($messageId = null): void
    {
        if ($messageId) {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "⏳ در حال ارسال لیست محصولات...",
                "reply_markup" => ['inline_keyboard' => []]
            ]);
        }

        $allProducts = DB::table('products')->all();

        if (empty($allProducts)) {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "هیچ محصولی برای نمایش وجود ندارد.",
                "reply_markup" => [
                    'inline_keyboard' => [[['text' => '⬅️ بازگشت', 'callback_data' => 'admin_manage_products']]]
                ]
            ]);
            return;
        }

        $messageIdsToDelete = [$messageId];

        foreach ($allProducts as $product) {

            $productText = "📦 نام محصول: " . $product['name'] . "\n";
            $productText .= "📝 توضیحات: " . $product['description'] . "\n";
            $productText .= "💰 قیمت: " . number_format($product['price']) . " تومان";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id']],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id']]
                    ]
                ]
            ];

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $productText,
                "parse_mode" => "Markdown",
                "reply_markup" => $keyboard
            ]);
            if (isset($res['result']['message_id'])) {
                $messageIdsToDelete[] = $res['result']['message_id'];
            }
        }

        $finalMessage = $this->sendRequest("sendMessage", [
            "chat_id" => $this->chatId,
            "text" => "--- پایان لیست محصولات ---",
            "reply_markup" => [
                'inline_keyboard' => [[['text' => '⬅️ بازگشت ', 'callback_data' => 'admin_manage_products']]]
            ]
        ]);
        if (isset($finalMessage['result']['message_id'])) {
            $messageIdsToDelete[] = $finalMessage['result']['message_id'];
        }

        DB::table('users')->update($this->chatId, ['message_ids' => $messageIdsToDelete]);
    }
    public function showProductListByCategory($categoryId, $page = 1, $messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }

        $perPage = 5;
        $allProducts = DB::table('products')->find(['category_id' => $categoryId]);

        if (empty($allProducts)) {
            $this->Alert("هیچ محصولی در این دسته‌بندی یافت نشد.");
            $this->promptUserForCategorySelection($messageId);
            return;
        }

        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);

        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = "📦 نام: " . $product['name'] . "\n";
            $productText .= "📝 توضیحات: " . ($product['description'] ?? '-') . "\n";
            $productText .= "🔢 موجودی: " . ($product['count'] ?? 0) . " عدد\n";
            $productText .= "💰 قیمت: " . number_format($product['price']) . " تومان";

            $productKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id']],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id']]
                    ]
                ]
            ];
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "Markdown",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "Markdown",
                    "reply_markup" => $productKeyboard
                ]);
            }

            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navText = "--- صفحه {$page} از {$totalPages} ---";
        $navButtons = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "list_products_cat_{$categoryId}_page_{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "list_products_cat_{$categoryId}_page_{$nextPage}"];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به دسته‌بندی‌ها', 'callback_data' => 'admin_product_list']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $navText,
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        DB::table('users')->update($this->chatId, ['message_ids' => $newMessageIds]);
    }
    public function promptForProductCategory($messageId = null): void
    {
        $allCategories = DB::table('categories')->all();
        if (empty($allCategories)) {
            $this->Alert(message: "ابتدا باید حداقل یک دسته‌بندی ایجاد کنید!");
            $this->showProductManagementMenu($messageId);
            return;
        }
        $categoryButtons = [];
        foreach ($allCategories as $category) {
            $categoryButtons[] = [['text' => $category['name'], 'callback_data' => 'product_cat_select_' . $category['id']]];
        }
        $categoryButtons[] = [['text' => '❌ انصراف و بازگشت', 'callback_data' => 'admin_manage_products']];

        $keyboard = ['inline_keyboard' => $categoryButtons];
        $text = "لطفاً دسته‌بندی محصول جدید را انتخاب کنید:";

        DB::table('users')->update($this->chatId, [
            'state' => 'adding_product_category',
            'state_data' => json_encode([])
        ]);

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        }
    }

    private function handleProductCreationSteps(): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $state = $user['state'] ?? null;
        $stateData = json_decode($user['state_data'] ?? '{}', true);
        $messageId = $this->getMessageId($this->chatId);

        switch ($state) {
            case 'adding_product_name':
                $productName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($productName)) {
                    $this->Alert("⚠️ نام محصول نمی‌تواند خالی باشد.");
                    return;
                }
                $stateData['name'] = $productName;
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_description',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ نام محصول ثبت شد: {$productName}\n\nحالا لطفاً توضیحات محصول را وارد کنید:",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_name'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_description':
                $stateData['description'] = trim($this->text);
                $this->deleteMessage($this->messageId);
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_count',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ توضیحات ثبت شد.\n\nحالا لطفاً تعداد موجودی محصول را وارد کنید (فقط عدد انگلیسی):",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_description'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_count':
                $count = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($count) || $count < 0) {
                    $this->Alert("⚠️ لطفاً یک تعداد معتبر وارد کنید.");
                    return;
                }
                $stateData['count'] = (int)$count;
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_price',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ تعداد ثبت شد: {$count} عدد\n\nحالا لطفاً قیمت محصول را وارد کنید (به تومان):",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_count'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_price':
                $price = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($price) || $price < 0) {
                    $this->Alert("⚠️ لطفاً یک قیمت معتبر وارد کنید.");
                    return;
                }
                $stateData['price'] = (int)$price;
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_photo',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ قیمت ثبت شد: " . number_format($price) . " تومان\n\nحالا لطفاً عکس محصول را ارسال کنید (برای رد کردن از دستور /skip استفاده کنید):",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_price'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_photo':
                $this->deleteMessage($this->messageId);

                if (isset($this->message['photo'])) {
                    $stateData['image_file_id'] = end($this->message['photo'])['file_id'];
                } elseif ($this->text !== '/skip') {
                    $this->Alert("⚠️ لطفاً یک عکس ارسال کنید یا از دستور /skip استفاده کنید.");
                    return;
                } else {
                    $stateData['image_file_id'] = null;
                }

                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_confirmation',
                    'state_data' => json_encode($stateData)
                ]);
                $this->deleteMessage($messageId);
                $this->showConfirmationPreview();
                break;
        }
    }
    public function promptUserForCategorySelection($messageId = null): void
    {
        $allCategories = DB::table('categories')->all();
        if (empty($allCategories)) {
            $this->Alert("هیچ دسته‌بندی‌ای برای نمایش محصولات وجود ندارد!");
            $this->showProductManagementMenu($messageId);
            return;
        }

        $categoryButtons = [];
        $row = [];
        foreach ($allCategories as $category) {
            $row[] = ['text' => $category['name'], 'callback_data' => 'list_products_cat_' . $category['id'] . '_page_1'];
            if (count($row) >= 2) {
                $categoryButtons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $categoryButtons[] = $row;
        }

        $categoryButtons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'admin_manage_products']];

        $keyboard = ['inline_keyboard' => $categoryButtons];
        $text = "لطفاً برای مشاهده محصولات، یک دسته‌بندی را انتخاب کنید:";

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => $keyboard
        ]);
    }
    private function showConfirmationPreview(): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $stateData = json_decode($user['state_data'] ?? '{}', true);

        // ساخت متن پیش نمایش
        $previewText = " لطفاً اطلاعات زیر را بررسی و تایید کنید:\n\n";
        $previewText .= "📦 نام محصول: " . ($stateData['name'] ?? 'ثبت نشده') . "\n";
        $previewText .= "📝 توضیحات: " . ($stateData['description'] ?? 'ثبت نشده') . "\n";
        $previewText .= "🔢 موجودی: " . ($stateData['count'] ?? '۰') . " عدد\n";
        $previewText .= "💰 قیمت: " . number_format($stateData['price'] ?? 0) . " تومان\n\n";
        $previewText .= "در صورت صحت اطلاعات، دکمه \"تایید و ذخیره\" را بزنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و ذخیره', 'callback_data' => 'product_confirm_save'],
                    ['text' => '❌ لغو عملیات', 'callback_data' => 'product_confirm_cancel']
                ]
            ]
        ];

        if (!empty($stateData['image_file_id'])) {
            $res = $this->sendRequest('sendPhoto', [
                'chat_id' => $this->chatId,
                'photo' => $stateData['image_file_id'],
                'caption' => $previewText,
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard
            ]);
        } else {
            $res = $this->sendRequest('sendMessage', [
                'chat_id' => $this->chatId,
                'text' => $previewText,
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard
            ]);
        }

        if (isset($res['result']['message_id'])) {
            $this->saveMessageId($this->chatId, $res['result']['message_id']);
        }
    }

    private function createNewProduct(array $productData): void
    {
        $products = DB::table('products')->all();
        $newId = empty($products) ? 1 : max(array_keys($products)) + 1;

        $finalProduct = [
            'id' => $newId,
            'name' => $productData['name'],
            'description' => $productData['description'] ?? '',
            'price' => $productData['price'],
            'category_id' => $productData['category_id'],
            'count' => $productData['count'] ?? 0,
            'image_file_id' => $productData['image_file_id'] ?? null,
            'is_active' => true,
        ];

        DB::table('products')->insert($finalProduct);
    }
}
