<?php

namespace Bot;

use Config\AppConfig;
use Payment\ZarinpalPaymentHandler;
use Bot\DB;     // <-- استفاده از مدیر دیتابیس جدید
use Bot\Logger; // <-- اطمینان از وجود کلاس لاگر
use Bot\jdf;


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
            if (str_starts_with($this->text, "/start")) {
                $this->deleteMessage($this->messageId);
                if (!empty($currentUser['message_ids'])) $this->deleteMessages($currentUser['message_ids']);
                DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);

                $parts = explode(' ', $this->text);
                if (isset($parts[1]) && str_starts_with($parts[1], 'product_')) {
                    $productId = (int)str_replace('product_', '', $parts[1]);
                    $this->showSingleProduct($productId);
                } else {
                    $this->MainMenu();
                }
                return;
            } elseif ($this->text === "/cart") {
                if (!empty($currentUser['message_ids'])) $this->deleteMessages($currentUser['message_ids']);
                $this->showCart();
                return;
            } elseif ($this->text === "/search") {
                $this->activateInlineSearch();
                return;
            } elseif ($this->text === "/favorites") {
                if (!empty($currentUser['message_ids'])) $this->deleteMessages($currentUser['message_ids']);
                $this->showFavoritesList();
                return;
            }  elseif (str_starts_with($state, 'awaiting_receipt_')) {
                $this->deleteMessage($this->messageId);
                if (!isset($this->message['photo'])) {
                    $this->Alert("خطا: لطفاً فقط تصویر رسید را ارسال کنید.");
                    return;
                }

                $invoiceId = str_replace('awaiting_receipt_', '', $state);
                $receiptFileId = end($this->message['photo'])['file_id'];

                DB::table('invoices')->update($invoiceId, [
                    'receipt_file_id' => $receiptFileId,
                    'status' => 'payment_review' 
                ]);

                DB::table('users')->update($this->chatId, ['state' => '']);

                $this->Alert("✅ رسید شما با موفقیت دریافت شد. پس از بررسی، نتیجه به شما اطلاع داده خواهد شد. سپاس از خرید شما!");
                $this->MainMenu(); 

                $this->notifyAdminOfNewReceipt($invoiceId, $receiptFileId);

                return;
            }elseif (strpos($state, 'editing_product_') === 0) {
                $this->handleProductUpdate($state);
                return;
            } elseif (in_array($state, ['adding_product_name', 'adding_product_description', 'adding_product_count', 'adding_product_price', 'adding_product_photo'])) {
                $this->handleProductCreationSteps();
                return;
            } elseif (in_array($state, ['entering_shipping_name', 'entering_shipping_phone', 'entering_shipping_address'])) {
                $this->handleShippingInfoSteps();
                return;
            } elseif (str_starts_with($state, 'editing_category_name_')) {
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
            } elseif (str_starts_with($state, 'editing_setting_')) {
                $key = str_replace('editing_setting_', '', $state);
                $value = trim($this->text);
                $this->deleteMessage($this->messageId);

                $numericFields = ['delivery_price', 'tax_percent', 'discount_fixed'];
                if (in_array($key, $numericFields) && !is_numeric($value)) {
                    $this->Alert("مقدار وارد شده باید یک عدد معتبر باشد.");
                    return;
                }

                $userData = DB::table('users')->findById($this->chatId);
                $stateData = json_decode($userData['state_data'] ?? '{}', true);
                $messageId = $stateData['message_id'] ?? null;

                DB::table('settings')->set($key, $value);
                DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);

                $this->showBotSettingsMenu($messageId);
                return;
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
                $user = DB::table('users')->findById($this->chatId);
                if (!empty($user['message_ids'])) {
                    $this->deleteMessages($user['message_ids']);
                }
                $this->MainMenu($messageId);
                return;
            } elseif ($callbackData === 'contact_support') {
                $this->showSupportInfo($messageId);
                return;
            } elseif ($callbackData === 'main_menu2') {
                $user = DB::table('users')->findById($this->chatId);
                if (!empty($user['message_ids'])) {
                    $this->deleteMessages($user['message_ids']);
                }
                $this->deleteMessage($this->messageId);
                $this->MainMenu();
                return;
            } elseif ($callbackData === 'nope') {
                return;
            } elseif ($callbackData === 'admin_bot_settings') {
                $this->showBotSettingsMenu($messageId);
                return;
            } elseif ($callbackData === 'show_store_rules') {
                $this->showStoreRules($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'edit_setting_')) {
                $key = str_replace('edit_setting_', '', $callbackData);

                $fieldMap = [
                    'store_name' => 'نام فروشگاه',
                    'main_menu_text' => 'متن منوی اصلی',
                    'delivery_price' => 'هزینه ارسال (به تومان)',
                    'tax_percent' => 'درصد مالیات (فقط عدد)',
                    'discount_fixed' => 'مبلغ تخفیف ثابت (به تومان)',
                    'card_number' => 'شماره کارت (بدون فاصله)',
                    'card_holder_name' => 'نام و نام خانوادگی صاحب حساب',
                    'support_id' => 'آیدی پشتیبانی تلگرام (با @)',
                    'store_rules' => 'قوانین فروشگاه (متن کامل)',
                    'channel_id' => 'آیدی کانال فروشگاه (با @)',
                ];

                if (!isset($fieldMap[$key])) {
                    $this->Alert("خطا: تنظیمات نامشخص است.");
                    return;
                }

                if (!isset($fieldMap[$key])) {
                    $this->Alert("خطا: تنظیمات نامشخص است.");
                    return;
                }

                // ذخیره وضعیت کاربر و messageId برای بازگشت
                $stateData = json_encode(['message_id' => $messageId]);
                DB::table('users')->update($this->chatId, [
                    'state' => "editing_setting_{$key}",
                    'state_data' => $stateData
                ]);

                $promptText = "لطفاً مقدار جدید برای \"{$fieldMap[$key]}\" را ارسال کنید.";
                $this->Alert($promptText, true);

                return;
            } elseif ($callbackData === 'activate_inline_search') {
                $this->activateInlineSearch($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_approve_')) {
                $invoiceId = str_replace('admin_approve_', '', $callbackData);
                
                
                $invoice = DB::table('invoices')->findById($invoiceId);
                
                if (!$invoice || $invoice['status'] === 'approved') {
                    $this->Alert("این فاکتور قبلاً تایید شده یا یافت نشد.");
                    return;
                }

                $productsTable = DB::table('products');
                foreach ($invoice['products'] as $purchasedProduct) {
                    $productId = $purchasedProduct['id'];
                    $quantityPurchased = $purchasedProduct['quantity'];

                    $productData = $productsTable->findById($productId);
                    if ($productData) {
                        $newCount = $productData['count'] - $quantityPurchased;
                        $newCount = max(0, $newCount); 
                        $productsTable->update($productId, ['count' => $newCount]);
                    }
                }
                DB::table('invoices')->update($invoiceId, ['status' => 'approved']);
                
                $invoice = DB::table('invoices')->findById($invoiceId);
                $userId = $invoice['user_id'] ?? null;
                if ($userId) {
                    $this->sendRequest("sendMessage", [
                        'chat_id' => $userId,
                        'text' => "✅ سفارش شما با شماره فاکتور `{$invoiceId}` تایید شد و به زودی برای شما ارسال خواهد شد. سپاس از خرید شما!",
                        'parse_mode' => 'Markdown'
                    ]);
                }

                $originalText = $callbackQuery['message']['text'];
                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $originalText . "\n\n--- ✅ این فاکتور توسط شما تایید شد. ---",
                    'parse_mode' => 'Markdown'
                ]);

                return;

            } elseif (str_starts_with($callbackData, 'admin_reject_')) {
                $invoiceId = str_replace('admin_reject_', '', $callbackData);
                DB::table('invoices')->update($invoiceId, ['status' => 'rejected']);
                
                $invoice = DB::table('invoices')->findById($invoiceId);
                $userId = $invoice['user_id'] ?? null;
                $settings = DB::table('settings')->all();
                $supportId = $settings['support_id'] ?? 'پشتیبانی';

                if ($userId) {
                    $this->sendRequest("sendMessage", [
                        'chat_id' => $userId,
                        'text' => "❌ متاسفانه پرداخت شما برای فاکتور `{$invoiceId}` رد شد. لطفاً برای پیگیری با پشتیبانی ({$supportId}) تماس بگیرید.",
                        'parse_mode' => 'Markdown'
                    ]);
                }
                
                $originalText = $callbackQuery['message']['text'];
                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $originalText . "\n\n--- ❌ این فاکتور توسط شما رد شد. ---",
                    'parse_mode' => 'Markdown'
                ]);

                return;
            }elseif ($callbackData === 'show_favorites') {
                $this->showFavoritesList(1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'fav_list_page_')) {
                $page = (int)str_replace('fav_list_page_', '', $callbackData);
                $this->showFavoritesList($page, $messageId);
                return;
            } elseif ($callbackData === 'edit_cart') {
                $this->showCartInEditMode($messageId);
                return;
            } elseif ($callbackData === 'show_cart') {
                $this->showCart($messageId);
                return;
            } elseif ($callbackData === 'clear_cart') {
                DB::table('users')->update($this->chatId, ['cart' => '[]']);
                $this->Alert("🗑 سبد خرید شما با موفقیت خالی شد.");
                $this->showCart($messageId);
                return;
            } elseif ($callbackData === 'complete_shipping_info' || $callbackData === 'edit_shipping_info') {
                DB::table('users')->update($this->chatId, ['state' => 'entering_shipping_name', 'state_data' => '[]']);
                $res = $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "لطفاً نام و نام خانوادگی کامل گیرنده را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                return;
            } elseif ($callbackData === 'checkout') {
                $this->initiateCardPayment($messageId); // جایگزینی با تابع جدید
                return;
            } elseif (str_starts_with($callbackData, 'upload_receipt_')) {
                $invoiceId = str_replace('upload_receipt_', '', $callbackData);
                DB::table('users')->update($this->chatId, ['state' => 'awaiting_receipt_' . $invoiceId]);
                $this->Alert("لطفاً تصویر رسید خود را ارسال کنید...", true);
                return;
            }elseif (strpos($callbackData, 'admin_edit_product_') === 0) {
                sscanf($callbackData, "admin_edit_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                if ($productId && $categoryId && $page) {
                    $this->showProductEditMenu($productId, $messageId, $categoryId, $page);
                }
                return;
            } elseif (str_starts_with($callbackData, 'confirm_product_edit_')) {
                sscanf($callbackData, "confirm_product_edit_%d_cat_%d_page_%d", $productId, $categoryId, $page);

                if (empty($productId) || empty($categoryId) || empty($page)) {
                    $this->Alert("خطا: اطلاعات ویرایش محصول ناقص است.");
                    return;
                }

                $product = DB::table('products')->findById($productId);
                if (empty($product)) {
                    $this->Alert("خطا: محصول یافت نشد.");
                    return;
                }

                DB::table('users')->update($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $productText = $this->generateProductCardText($product);
                $originalKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                }
                $this->Alert("✅ محصول با موفقیت ویرایش شد.", false);
                return;
            } elseif (strpos($callbackData, 'edit_field_') === 0) {
                sscanf($callbackData, "edit_field_%[^_]_%d_%d_%d", $field, $productId, $categoryId, $page);
                if ($field === 'imagefileid') {
                    $field = 'image_file_id';
                }

                $fieldMap = [
                    'name' => 'نام',
                    'description' => 'توضیحات',
                    'count' => 'تعداد',
                    'price' => 'قیمت',
                    'image_file_id' => 'عکس'
                ];

                if (!isset($fieldMap[$field])) {
                    $this->Alert("خطا: فیلد نامشخص است.");
                    return;
                }

                $stateData = json_encode([
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'page' => $page,
                    'message_id' => $messageId
                ]);
                DB::table('users')->update($this->chatId, [
                    'state' => "editing_product_{$field}",
                    'state_data' => $stateData
                ]);

                $promptText = "لطفاً مقدار جدید برای \"{$fieldMap[$field]}\" را ارسال کنید.";
                if ($field === 'image_file_id') {
                    $promptText .= " (یا /remove برای حذف عکس)";
                }

                $this->Alert($promptText, true);

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
                DB::table('users')->update($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $this->Alert("✅ محصول با موفقیت ذخیره شد!");
                $this->deleteMessage($messageId); // پیام پیش‌نمایش را حذف کن
                $this->showProductManagementMenu(null); // منو را به عنوان پیام جدید بفرست

                return;
            } elseif ($callbackData === 'product_confirm_cancel') {
                DB::table('users')->update($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);
                $this->Alert("❌ عملیات افزودن محصول لغو شد.");
                $this->deleteMessage($messageId);
                $this->showProductManagementMenu(null);
                return;
            } elseif ($callbackData === 'admin_panel_entry') {
                $this->showAdminMainMenu($messageId);
                return;
            } elseif ($callbackData === 'show_about_us') {
                $this->showAboutUs();
                return;
            } elseif ($callbackData === 'admin_manage_categories') {
                $this->showCategoryManagementMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_category_list') {
                $this->showCategoryList($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'cart_remove_')) {
                $productId = (int)str_replace('cart_remove_', '', $callbackData);

                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    unset($cart[$productId]);
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->deleteMessage($messageId);
                    $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                }

                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_increase_')) {
                $productId = (int)str_replace('edit_cart_increase_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);
                if (isset($cart[$productId])) {
                    $cart[$productId]++;
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshCartItemCard($productId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_decrease_')) {
                $productId = (int)str_replace('edit_cart_decrease_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);
                if (isset($cart[$productId])) {
                    $cart[$productId]--;
                    if ($cart[$productId] <= 0) unset($cart[$productId]);
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshCartItemCard($productId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_remove_')) {
                $productId = (int)str_replace('edit_cart_remove_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);
                unset($cart[$productId]);
                DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                $this->deleteMessage($messageId);

                return;
            } elseif (str_starts_with($callbackData, 'cart_increase_')) {
                $productId = (int)str_replace('cart_increase_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    $cart[$productId]++;
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshProductCard($productId, $messageId);
                    $this->Alert("✅ به سبد خرید اضافه شد", false);
                }
                return;
            } elseif (str_starts_with($callbackData, 'cart_decrease_')) {
                $productId = (int)str_replace('cart_decrease_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    $cart[$productId]--;
                    if ($cart[$productId] <= 0) {
                        unset($cart[$productId]);
                    }

                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshProductCard($productId, $messageId);
                    $this->Alert("از سبد خرید کم شد", false);
                }
                return;
            } elseif (str_starts_with($callbackData, 'category_')) {
                $categoryId = (int)str_replace('category_', '', $callbackData);
                $this->showUserProductList($categoryId, 1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'user_list_products_cat_')) {
                sscanf($callbackData, "user_list_products_cat_%d_page_%d", $categoryId, $page);
                if ($categoryId && $page) {
                    $this->showUserProductList($categoryId, $page, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'toggle_favorite_')) {
                $productId = (int)str_replace('toggle_favorite_', '', $callbackData);
                $product = DB::table('products')->findById($productId);

                if (!$product) {
                    $this->Alert("❌ محصول یافت نشد.");
                    return;
                }

                $user = DB::table('users')->findById($this->chatId);
                $favorites = json_decode($user['favorites'] ?? '[]', true);

                $message = "";

                if (in_array($productId, $favorites)) {
                    $favorites = array_diff($favorites, [$productId]);
                    $message = "از علاقه‌مندی‌ها حذف شد.";
                } else {
                    $favorites[] = $productId;
                    $message = "به علاقه‌مندی‌ها اضافه شد.";
                }
                DB::table('users')->update($this->chatId, ['favorites' => json_encode(array_values($favorites))]);

                $this->refreshProductCard($productId, $messageId);
                $this->Alert("❤️ " . $message, false);

                return;
            } elseif (str_starts_with($callbackData, 'add_to_cart_')) {
                $productId = (int)str_replace('add_to_cart_', '', $callbackData);
                $product = DB::table('products')->findById($productId);

                if (!$product || ($product['count'] ?? 0) <= 0) {
                    $this->Alert("❌ متاسفانه موجودی این محصول به اتمام رسیده است.");
                    return;
                }

                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    $cart[$productId]++;
                } else {
                    $cart[$productId] = 1;
                }

                DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                $this->Alert("✅ به سبد خرید اضافه شد", false);
                $this->refreshProductCard($productId, $messageId);

                return;
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
                    'parse_mode' => 'HTML',
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
            } elseif (strpos($callbackData, 'admin_delete_product_') === 0) {

                sscanf($callbackData, "admin_delete_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                $product = DB::table('products')->findById($productId);

                if (!$product) {
                    $this->Alert("خطا: محصول یافت نشد!");
                    return;
                }

                $confirmationText = "❓ آیا از حذف محصول \"{$product['name']}\" مطمئن هستید؟";
                $confirmationKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ بله، حذف کن', 'callback_data' => 'confirm_delete_product_' . $productId],
                            ['text' => '❌ خیر، انصراف', 'callback_data' => 'cancel_delete_product_' . $productId . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $confirmationText,
                        'reply_markup' => $confirmationKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $confirmationText,
                        'reply_markup' => $confirmationKeyboard
                    ]);
                }
                return;
            } elseif (strpos($callbackData, 'confirm_delete_product_') === 0) {
                $productId = str_replace('confirm_delete_product_', '', $callbackData);

                DB::table('products')->delete($productId);
                $this->deleteMessage($messageId);
                $this->Alert("✅ محصول با موفقیت حذف شد.");
                return;
            } elseif (strpos($callbackData, 'cancel_delete_product_') === 0) {

                sscanf($callbackData, "cancel_delete_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                $product = DB::table('products')->findById($productId);

                if (!$product || !$categoryId || !$page) {
                    $this->Alert("خطا در بازگردانی محصول.");
                    return;
                }

                $productText = $this->generateProductCardText($product);

                $originalKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];
                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                }

                return;
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
    public function handleInlineQuery(array $inlineQuery): void
    {
        $inlineQueryId = $inlineQuery['id'];
        $query = trim($inlineQuery['query']);
        if (empty($query)) {
            $this->sendRequest("answerInlineQuery", ['inline_query_id' => $inlineQueryId, 'results' => []]);
            return;
        }

        $allProducts = DB::table('products')->all();
        $foundProducts = [];
        foreach ($allProducts as $product) {
            if (str_contains(strtolower($product['name']), strtolower($query)) || str_contains(strtolower($product['description']), strtolower($query))) {
                $foundProducts[] = $product;
            }
        }

        $foundProducts = array_slice($foundProducts, 0, 20);

        $results = [];
        foreach ($foundProducts as $product) {
            $productUrl = $this->botLink . 'product_' . $product['id'];

            $results[] = [
                'type' => 'article',
                'id' => (string)$product['id'],
                'title' => $product['name'],
                'input_message_content' => [
                    'message_text' => $this->generateProductCardText($product),
                    'parse_mode' => 'HTML'
                ],

                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => 'مشاهده در ربات', 'url' => $productUrl]]
                    ]
                ],
                'description' => 'قیمت: ' . number_format($product['price']) . ' تومان'

            ];
        }

        $this->sendRequest("answerInlineQuery", [
            'inline_query_id' => $inlineQueryId,
            'results' => $results,
            'cache_time' => 10
        ]);
    }

    public function MainMenu($messageId = null): void
    {
        $settings = DB::table('settings')->all();
        $channelId = $settings['channel_id'] ?? null;
        $hour = (int) jdf::jdate('H');
        $defaultWelcome = match (true) {
            $hour < 12 => "☀️ صبح بخیر! آماده‌ای برای دیدن پیشنهادهای خاص امروز؟",
            $hour < 18 => "🌼 عصر بخیر! یه چیزی خاص برای امروز داریم 😉",
            default => "🌙 شب بخیر! شاید وقتشه یه هدیه‌ خاص برای خودت یا عزیزات پیدا کنی...",
        };
        $menuText = $settings['main_menu_text'] ?? $defaultWelcome;

        $allCategories = DB::table('categories')->all();
        $categoryButtons = [];
        if (!empty($settings['daily_offer'])) {
            $categoryButtons[] = [['text' => '🔥 پیشنهاد ویژه امروز', 'callback_data' => 'daily_offer']];
        }

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
                $row[] = ['text' =>  $category['name'], 'callback_data' => 'category_' . $category['id']];
                if (count($row) == 2) {
                    $categoryButtons[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $categoryButtons[] = $row;
            }
        }

        $userActionButtons = [
            ['text' => '❤️ علاقه‌مندی‌ها', 'callback_data' => 'show_favorites'],
            ['text' => '🛒 سبد خرید', 'callback_data' => 'show_cart']
        ];
        $categoryButtons[] = $userActionButtons;
        $categoryButtons[] = [
            ['text' => '🔍 جستجوی محصول', 'callback_data' => 'activate_inline_search'],
            ['text' => '📞 پشتیبانی', 'callback_data' => 'contact_support']
        ];
        $categoryButtons[] = [
            ['text' => '📜 قوانین فروشگاه', 'callback_data' => 'show_store_rules'],
            ['text' => 'ℹ️ درباره ما', 'callback_data' => 'show_about_us']
        ];

        if (!empty($channelId)) {
            $channelUsername = str_replace('@', '', $channelId);
            $categoryButtons[] = [['text' => '📢 عضویت در کانال فروشگاه', 'url' => "https://t.me/{$channelUsername}"]];
        }

        $user = DB::table('users')->findById($this->chatId);
        if ($user && !empty($user['is_admin'])) {
            $categoryButtons[] = [['text' => '⚙️ مدیریت فروشگاه', 'callback_data' => 'admin_panel_entry']];
        }

        $keyboard = ['inline_keyboard' => $categoryButtons];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $menuText,
            'reply_markup' => json_encode($keyboard),
            'parse_mode' => 'HTML',
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }


    public function showFavoritesList($page = 1, $messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }
        $cart = json_decode($user['cart'] ?? '{}', true);
        $favoritesIds = json_decode($user['favorites'] ?? '[]', true);
        if (empty($favoritesIds)) {
            $this->Alert("❤️ لیست علاقه‌مندی‌های شما خالی است.");
            return;
        }


        $allProducts = DB::table('products')->all();
        $favoriteProducts = array_filter($allProducts, fn($product) => in_array($product['id'], $favoritesIds));

        $perPage = 5;
        $totalPages = ceil(count($favoriteProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($favoriteProducts, $offset, $perPage);

        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productId = $product['id'];
            $keyboardRows = [];

            $keyboardRows[] = [['text' => '❤️ حذف از علاقه‌مندی', 'callback_data' => 'toggle_favorite_' . $productId]];

            if (isset($cart[$productId])) {
                $quantity = $cart[$productId];
                $keyboardRows[] = [
                    ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
                ];
            } else {
                $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
            }

            $productKeyboard = ['inline_keyboard' => $keyboardRows];
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            }
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navText = "--- علاقه‌مندی‌ها (صفحه {$page} از {$totalPages}) ---";
        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "fav_list_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "fav_list_page_" . ($page + 1)];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

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


    public function showCart($messageId = null): void
    {


        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);
        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }


        if (empty($cart)) {
            $this->Alert("🛒 سبد خرید شما خالی است.");
            $this->MainMenu();
            return;
        }

        $settings = DB::table('settings')->all();
        $shippingInfoComplete = !empty($user['shipping_name']) && !empty($user['shipping_phone']) && !empty($user['shipping_address']);

        $storeName     = $settings['store_name'] ?? 'فروشگاه من';
        $deliveryCost  = (int)($settings['delivery_price'] ?? 0);
        $taxPercent    = (int)($settings['tax_percent'] ?? 0);
        $discountFixed = (int)($settings['discount_fixed'] ?? 0);

        $date = jdf::jdate('Y/m/d');
        $invoiceId = $this->chatId;

        $text = "🧾 <b>فاکتور خرید از {$storeName}</b>\n";
        $text .= "📆 تاریخ: {$date}\n";
        $text .= "🆔 شماره فاکتور: {$invoiceId}\n\n";

        if ($shippingInfoComplete) {
            $text .= "🚚 <b>مشخصات گیرنده:</b>\n";
            $text .= "👤 نام: {$user['shipping_name']}\n";
            $text .= "📞 تلفن: {$user['shipping_phone']}\n";
            $text .= "📍 آدرس: {$user['shipping_address']}\n\n";
        }

        $text .= "<b>📋 لیست اقلام:</b>\n";
        $allProducts = DB::table('products')->all();
        $totalPrice = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($allProducts[$productId])) {
                $product = $allProducts[$productId];
                $unitPrice = $product['price'];
                $itemPrice = $unitPrice * $quantity;
                $totalPrice += $itemPrice;

                $text .= "🔸 {$product['name']}\n";
                $text .= "  ➤ تعداد: {$quantity} | قیمت واحد: " . number_format($unitPrice) . " تومان\n";
                $text .= "  💵 مجموع: " . number_format($itemPrice) . " تومان\n\n";
            }
        }

        $taxAmount = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost - $discountFixed;

        $text .= "📦 هزینه ارسال: " . number_format($deliveryCost) . " تومان\n";
        $text .= "💸 تخفیف: " . number_format($discountFixed) . " تومان\n";
        $text .= "📊 مالیات ({$taxPercent}%): " . number_format($taxAmount) . " تومان\n";
        $text .= "💰 <b>مبلغ نهایی قابل پرداخت:</b> <b>" . number_format($grandTotal) . "</b> تومان";


        $keyboardRows = [];
        if ($shippingInfoComplete) {

            $keyboardRows[] = [['text' => '💳 پرداخت نهایی', 'callback_data' => 'checkout']];
            $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
            $keyboardRows[] = [['text' => '📝 ویرایش اطلاعات ارسال', 'callback_data' => 'edit_shipping_info']];
        } else {
            $keyboardRows[] = [['text' => '📝 تکمیل اطلاعات ارسال', 'callback_data' => 'complete_shipping_info']];
            $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
        }

        $keyboardRows[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];
        $keyboard = ['inline_keyboard' => $keyboardRows];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                "message_id" => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
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
                "parse_mode" => "HTML",
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
            $productText = $this->generateProductCardText($product);
            $productKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                    ]
                ]
            ];
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
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
                    'parse_mode' => 'HTML',
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
                    'parse_mode' => 'HTML',
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
                    'parse_mode' => 'HTML',
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
                    'parse_mode' => 'HTML',
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
    private function generateProductCardText(array $product): string
    {

        $rtl_on  = "\u{202B}";
        $rtl_off = "\u{202C}";

        $name        = $product['name'];
        $desc        = $product['description'] ?? 'توضیحی ثبت نشده';
        $price       = number_format($product['price']);

        $text = $rtl_on;
        $text .= "🛍️ <b>{$name}</b>\n\n";
        $text .=  "{$desc}\n\n";

        if (isset($product['quantity'])) {

            $quantity = (int)$product['quantity'];
            $text .= "🔢 <b>تعداد در سبد:</b> {$quantity} عدد\n";
        } else {
            $count = (int)($product['count'] ?? 0);
            $text .= "📦 <b>موجودی:</b> {$count} عدد\n";
        }
        $text .= "💵 <b>قیمت:</b> {$price} تومان";
        $text .= $rtl_off;

        return $text;
    }


    public function promptUserForCategorySelection($messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }
        $allCategories = DB::table('categories')->all();
        if (empty($allCategories)) {
            $this->Alert("هیچ دسته‌بندی‌ای برای نمایش محصولات وجود ندارد!");
            $this->showProductManagementMenu(messageId: $messageId);
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
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $res = $this->sendRequest('sendMessage', [
                'chat_id' => $this->chatId,
                'text' => $previewText,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }

        if (isset($res['result']['message_id'])) {
            $this->saveMessageId($this->chatId, $res['result']['message_id']);
        }
    }
    private function handleProductUpdate(string $state): void
    {
        $field = str_replace('editing_product_', '', $state);

        $user = DB::table('users')->findById($this->chatId);
        $stateData = json_decode($user['state_data'] ?? '{}', true);

        $productId = $stateData['product_id'] ?? null;
        $categoryId = $stateData['category_id'] ?? null;
        $page = $stateData['page'] ?? null;
        $messageId = $stateData['message_id'] ?? null;

        if (!$productId || !$categoryId || !$page || !$messageId) {
            $this->Alert("خطا در پردازش ویرایش. لطفاً دوباره تلاش کنید.");
            DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);
            return;
        }

        $this->deleteMessage($this->messageId);

        $updateData = [];
        $value = null;

        switch ($field) {
            case 'name':
                $value = trim($this->text);
                if (empty($value)) {
                    $this->Alert("نام نمی‌تواند خالی باشد.");
                    return;
                }
                $updateData['name'] = $value;
                break;
            case 'description':
                $updateData['description'] = trim($this->text);
                break;
            case 'count':
            case 'price':
                $value = trim($this->text);
                if (!is_numeric($value) || $value < 0) {
                    $this->Alert("مقدار وارد شده باید یک عدد معتبر باشد.");
                    return;
                }
                $updateData[$field] = (int)$value;
                break;
            case 'image_file_id':
                if (isset($this->message['photo'])) {
                    $updateData['image_file_id'] = end($this->message['photo'])['file_id'];
                } elseif ($this->text === '/remove') {
                    $updateData['image_file_id'] = null;
                } else {
                    $this->Alert("لطفاً یک عکس ارسال کنید یا از دستور /remove استفاده کنید.");
                    return;
                }
                break;
        }

        DB::table('products')->update($productId, $updateData);

        DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);
        $this->Alert("✅ بروزرسانی با موفقیت انجام شد.");
        $this->showProductEditMenu($productId, $messageId, $categoryId, $page);
    }

    public function showProductEditMenu(int $productId, int $messageId, int $categoryId, int $page): void
    {
        $product = DB::table('products')->findById($productId);
        if (!$product) {
            $this->Alert("خطا: محصول یافت نشد!");
            $this->deleteMessage($messageId);
            return;
        }

        $text = "شما در حال ویرایش محصول \"{$product['name']}\"هستید.\n\n";
        $text .= "کدام بخش را می‌خواهید ویرایش کنید؟";

        $keyboard = [
            'inline_keyboard' => [

                [
                    ['text' => '✏️ ویرایش نام', 'callback_data' => "edit_field_name_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ ویرایش توضیحات', 'callback_data' => "edit_field_description_{$productId}_{$categoryId}_{$page}"]
                ],
                [
                    ['text' => '✏️ ویرایش تعداد', 'callback_data' => "edit_field_count_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ ویرایش قیمت', 'callback_data' => "edit_field_price_{$productId}_{$categoryId}_{$page}"]
                ],
                [['text' => '🖼️ ویرایش عکس', 'callback_data' => "edit_field_imagefileid_{$productId}_{$categoryId}_{$page}"]],
                [['text' => '✅ تایید و ذخیره', 'callback_data' => "confirm_product_edit_{$productId}_cat_{$categoryId}_page_{$page}"]],

            ]
        ];

        $method = !empty($product['image_file_id']) ? "editMessageCaption" : "editMessageText";
        $textOrCaptionKey = !empty($product['image_file_id']) ? "caption" : "text";

        $this->sendRequest($method, [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            $textOrCaptionKey => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard
        ]);
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

    public function showUserProductList($categoryId, $page = 1, $messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);
        $favorites = json_decode($user['favorites'] ?? '[]', true);

        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }

        $perPage = 5;
        $allProducts = DB::table('products')->find(['category_id' => $categoryId, 'is_active' => true]);

        if (empty($allProducts)) {
            $this->Alert("متاسفانه محصولی در این دسته‌بندی یافت نشد.");
            return;
        }

        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);

        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productId = $product['id'];
            $keyboardRows = [];

            $isFavorite = in_array($productId, $favorites);
            $favoriteButtonText = $isFavorite ? '❤️ حذف از علاقه‌مندی' : '🤍 افزودن به علاقه‌مندی';
            $keyboardRows[] = [['text' => $favoriteButtonText, 'callback_data' => 'toggle_favorite_' . $productId]];

            if (isset($cart[$productId])) {
                $quantity = $cart[$productId];
                $keyboardRows[] = [
                    ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
                ];
            } else {
                $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
            }

            $productKeyboard = ['inline_keyboard' => $keyboardRows];

            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
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
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "user_list_products_cat_{$categoryId}_page_{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "user_list_products_cat_{$categoryId}_page_{$nextPage}"];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

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

    private function refreshProductCard(int $productId, ?int $messageId): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);
        $favorites = json_decode($user['favorites'] ?? '[]', true);

        $keyboardRows = [];
        $isFavorite = in_array($productId, $favorites);
        $favoriteButtonText = $isFavorite ? '❤️ حذف از علاقه‌مندی' : '🤍 افزودن به علاقه‌مندی';
        $keyboardRows[] = [['text' => $favoriteButtonText, 'callback_data' => 'toggle_favorite_' . $productId]];

        if (isset($cart[$productId])) {
            $quantity = $cart[$productId];
            $keyboardRows[] = [
                ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
            ];
        } else {
            $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
        }
        if ($messageId == null) {
            $keyboardRows[] = [['text' => 'منوی اصلی', 'callback_data' => 'main_menu2']];
        }

        $newKeyboard = ['inline_keyboard' => $keyboardRows];

        if ($messageId) {

            $this->sendRequest('editMessageReplyMarkup', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'reply_markup' => $newKeyboard
            ]);
        } else {
            $product = DB::table('products')->findById($productId);
            $productText = $this->generateProductCardText($product);
            if (!empty($product['image_file_id'])) {
                $this->sendRequest("sendPhoto", ["chat_id" => $this->chatId, "photo" => $product['image_file_id'], "caption" => $productText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            } else {
                $this->sendRequest("sendMessage", ["chat_id" => $this->chatId, "text" => $productText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            }
        }
    }
    public function activateInlineSearch($messageId = null): void
    {
        $text = "🔍 برای جستجوی محصولات در این چت، روی دکمه زیر کلیک کرده و سپس عبارت مورد نظر خود را تایپ کنید:";
        $buttonText = "شروع جستجو در این چت 🔍";

        if ($messageId == null) {
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $text,
                "reply_markup" => [
                    "inline_keyboard" => [
                        [
                            [
                                "text" => $buttonText,
                                "switch_inline_query_current_chat" => ""
                            ]
                        ]
                    ]
                ]
            ]);
        } else {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                'message_id' => $messageId,
                "text" => $text,
                "reply_markup" => [
                    "inline_keyboard" => [
                        [
                            [
                                "text" => $buttonText,
                                "switch_inline_query_current_chat" => ""
                            ]
                        ],
                        [
                            [
                                "text" => "🔙 بازگشت",
                                "callback_data" => "main_menu"
                            ]
                        ]
                    ]
                ]
            ]);
        }
    }

    public function showSingleProduct(int $productId): void
    {
        $product = DB::table('products')->findById($productId);
        if (!$product) {
            $this->Alert("متاسفانه محصول مورد نظر یافت نشد یا حذف شده است.");
            $this->MainMenu();
            return;
        }


        $this->refreshProductCard($productId, null);
    }

    public function showAboutUs(): void
    {

        $text = "🤖 *درباره توسعه‌دهنده ربات*\n\n";
        $text .= "این ربات یک *نمونه‌کار حرفه‌ای* در زمینه طراحی و توسعه ربات‌های فروشگاهی در تلگرام است که توسط *امیر سلیمانی* طراحی و برنامه‌نویسی شده است.\n\n";
        $text .= "✨ *ویژگی‌های برجسته ربات:*\n";
        $text .= "🔹 پنل مدیریت کامل از داخل تلگرام (افزودن، ویرایش، حذف محصول)\n";
        $text .= "🗂️ مدیریت هوشمند دسته‌بندی محصولات\n";
        $text .= "🛒 سیستم سبد خرید و لیست علاقه‌مندی‌ها\n";
        $text .= "🔍 جستجوی پیشرفته با سرعت بالا (Inline Mode)\n";
        $text .= "💳 اتصال امن به درگاه پرداخت\n\n";
        $text .= "💼 *آیا برای کسب‌وکار خود به یک ربات تلگرامی نیاز دارید؟*\n";
        $text .= "ما آماده‌ایم تا ایده‌های شما را به یک ربات کاربردی و حرفه‌ای تبدیل کنیم.\n\n";
        $text .= "📞 *راه ارتباط با توسعه‌دهنده:* [@Amir_soleimani_79](https://t.me/Amir_soleimani_79)";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ بازگشت به فروشگاه', 'callback_data' => 'main_menu']]
            ]
        ];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => false,
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    public function showCartInEditMode($messageId): void
    {
        $this->deleteMessage($messageId);

        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);

        if (empty($cart)) {
            $this->Alert("سبد خرید شما خالی  است.");
            $this->MainMenu();
            return;
        }

        $allProducts = DB::table('products')->all();
        $newMessageIds = [];

        foreach ($cart as $productId => $quantity) {
            if (isset($allProducts[$productId])) {
                $product = $allProducts[$productId];
                $product['quantity'] = $quantity;
                $itemText = $this->generateProductCardText($product);

                $keyboard = [
                    'inline_keyboard' => [
                        [

                            ['text' => '➕', 'callback_data' => "edit_cart_increase_{$productId}"],
                            ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                            ['text' => '➖', 'callback_data' => "edit_cart_decrease_{$productId}"]
                        ],
                        [
                            ['text' => '🗑 حذف کامل از سبد', 'callback_data' => "edit_cart_remove_{$productId}"]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $res = $this->sendRequest("sendPhoto", [
                        "chat_id" => $this->chatId,
                        "photo" => $product['image_file_id'],
                        "caption" => $itemText,
                        "parse_mode" => "HTML",
                        "reply_markup" => $keyboard
                    ]);
                } else {
                    $res = $this->sendRequest("sendMessage", [
                        "chat_id" => $this->chatId,
                        "text" => $itemText,
                        "parse_mode" => "HTML",
                        "reply_markup" => $keyboard
                    ]);
                }

                if (isset($res['result']['message_id'])) {
                    $newMessageIds[] = $res['result']['message_id'];
                }
            }
        }

        $endEditText = "تغییرات مورد نظر را اعمال کرده و در پایان، دکمه زیر را بزنید:";
        $endEditKeyboard = [['text' => '✅ مشاهده فاکتور نهایی', 'callback_data' => 'show_cart']];

        $navMessageRes = $this->sendRequest("sendMessage", ['chat_id' => $this->chatId, 'text' => $endEditText, 'reply_markup' => ['inline_keyboard' => [$endEditKeyboard]]]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        DB::table('users')->update($this->chatId, ['message_ids' => $newMessageIds]);
    }

    private function refreshCartItemCard(int $productId, int $messageId): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);
        $product = DB::table('products')->findById($productId);

        if (!$product) {
            $this->deleteMessage($messageId);
            $this->Alert("خطا: محصول یافت نشد.", false);
            return;
        }

        if (!isset($cart[$productId])) {
            $this->deleteMessage($messageId);
            $this->Alert("محصول از سبد شما حذف شد.", false);
            return;
        }

        $quantity = $cart[$productId];
        $product['quantity'] = $quantity;

        $newText = $this->generateProductCardText($product);
        $newKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '➕', 'callback_data' => "edit_cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => "edit_cart_decrease_{$productId}"]
                ],
                [
                    ['text' => '🗑 حذف کامل از سبد', 'callback_data' => "edit_cart_remove_{$productId}"]
                ]
            ]
        ];


        if (!empty($product['image_file_id'])) {

            $this->sendRequest('editMessageCaption', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'caption' => $newText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        } else {
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $newText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        }
    }

    public function showBotSettingsMenu($messageId = null): void
    {
        $settings = DB::table('settings')->all();

        $storeName = $settings['store_name'] ?? 'تعیین نشده ❌';
        $mainMenuText = $settings['main_menu_text'] ?? 'تعیین نشده ❌';

        $deliveryPrice = number_format($settings['delivery_price'] ?? 0) . ' تومان';
        $taxPercent = ($settings['tax_percent'] ?? 0) . '٪';
        $discountFixed = number_format($settings['discount_fixed'] ?? 0) . ' تومان';

        $cardNumber = $settings['card_number'] ?? 'وارد نشده ❌';
        $cardHolderName = $settings['card_holder_name'] ?? 'وارد نشده ❌';
        $supportId = $settings['support_id'] ?? 'وارد نشده ❌';

        $storeRules = !empty($settings['store_rules']) ? $settings['store_rules'] : '❌ تنظیم نشده';
        $channelId = $settings['channel_id'] ?? 'وارد نشده';


        $text = "⚙️ <b>مدیریت تنظیمات ربات فروشگاه</b>\n\n";
        $text .= "🛒 <b>نام فروشگاه: </b> {$storeName}\n";
        $text .= "🧾 <b>متن منوی اصلی:</b>\n {$mainMenuText}\n\n";

        $text .= "🚚 <b>هزینه ارسال: </b> {$deliveryPrice}\n";
        $text .= "📊 <b>مالیات: </b> {$taxPercent}\n";
        $text .= "🎁 <b>تخفیف ثابت: </b>{$discountFixed}\n\n";

        $text .= "💳 <b>شماره کارت: </b> {$cardNumber}\n";
        $text .= "👤 <b>صاحب حساب: </b> {$cardHolderName}\n";
        $text .= "📢 آیدی کانال: <b>{$channelId}</b>\n";
        $text .= "📞 <b>آیدی پشتیبانی: </b> {$supportId}\n";
        $text .= "📜 <b>قوانین فروشگاه: \n</b> {$storeRules}\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ نام فروشگاه', 'callback_data' => 'edit_setting_store_name'],
                    ['text' => '✏️ متن منو', 'callback_data' => 'edit_setting_main_menu_text']
                ],
                [
                    ['text' => '✏️ هزینه ارسال', 'callback_data' => 'edit_setting_delivery_price'],
                    ['text' => '✏️ درصد مالیات', 'callback_data' => 'edit_setting_tax_percent']
                ],
                [
                    ['text' => '✏️ تخفیف ثابت', 'callback_data' => 'edit_setting_discount_fixed']
                ],
                [
                    ['text' => '✏️ شماره کارت', 'callback_data' => 'edit_setting_card_number'],
                    ['text' => '✏️ نام صاحب حساب', 'callback_data' => 'edit_setting_card_holder_name']
                ],
                [
                    ['text' => '✏️ آیدی پشتیبانی', 'callback_data' => 'edit_setting_support_id'],
                    ['text' => '✏️ آیدی کانال', 'callback_data' => 'edit_setting_channel_id']
                ],
                [
                    ['text' => '✏️ قوانین فروشگاه', 'callback_data' => 'edit_setting_store_rules']
                ],
                [
                    ['text' => '🔙 بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        $res = $messageId
            ? $this->sendRequest("editMessageText", array_merge($data, ['message_id' => $messageId]))
            : $this->sendRequest("sendMessage", $data);

        if (isset($res['result']['message_id'])) {
            $this->saveMessageId($this->chatId, $res['result']['message_id']);
        }
    }
    public function showSupportInfo($messageId = null): void
    {
        $settings = DB::table('settings')->all();
        $supportId = $settings['support_id'] ?? null;

        if (empty($supportId)) {
            $this->Alert("اطلاعات پشتیبانی در حال حاضر تنظیم نشده است.");
            return;
        }

        $username = str_replace('@', '', $supportId);
        $supportUrl = "https://t.me/{$username}";

        $text = "📞 برای ارتباط با واحد پشتیبانی می‌توانید مستقیماً از طریق آیدی زیر اقدام کنید .\n\n";
        $text .= "👤 آیدی پشتیبانی: {$supportId}";

        $keyboard = [
            'inline_keyboard' => [
                // [['text' => '🚀 شروع گفتگو با پشتیبانی', 'url' => $supportUrl]],
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }

    public function showStoreRules($messageId = null): void
    {
        $settings = DB::table('settings')->all();
        $rulesText = $settings['store_rules'] ?? 'متاسفانه هنوز قانونی برای فروشگاه تنظیم نشده است.';

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => "<b>📜 قوانین و مقررات فروشگاه</b>\n\n" . $rulesText,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }
    private function handleShippingInfoSteps(): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $state = $user['state'] ?? null;
        $stateData = json_decode($user['state_data'] ?? '{}', true);
        $messageId = $this->getMessageId($this->chatId);

        switch ($state) {
            case 'entering_shipping_name':
                $name = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($name)) {
                    $this->Alert("⚠️ نام و نام خانوادگی نمی‌تواند خالی باشد.");
                    return;
                }
                $stateData['name'] = $name;
                DB::table('users')->update($this->chatId, [
                    'state' => 'entering_shipping_phone',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ نام ثبت شد: {$name}\n\nحالا لطفاً شماره تلفن همراه خود را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                break;

            case 'entering_shipping_phone':
                $phone = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($phone) || strlen($phone) < 10) {
                    $this->Alert("⚠️ لطفاً یک شماره تلفن معتبر وارد کنید.");
                    return;
                }
                $stateData['phone'] = $phone;
                DB::table('users')->update($this->chatId, [
                    'state' => 'entering_shipping_address',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ شماره تلفن ثبت شد: {$phone}\n\nدر نهایت، لطفاً آدرس دقیق پستی خود را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                break;

            case 'entering_shipping_address':
                $address = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($address)) {
                    $this->Alert("⚠️ آدرس نمی‌تواند خالی باشد.");
                    return;
                }

                // ذخیره نهایی اطلاعات در دیتابیس کاربر
                DB::table('users')->update($this->chatId, [
                    'shipping_name' => $stateData['name'],
                    'shipping_phone' => $stateData['phone'],
                    'shipping_address' => $address,
                    'state' => null, // پاک کردن وضعیت
                    'state_data' => null
                ]);

                $this->deleteMessage($messageId); // حذف پیام راهنما
                $this->Alert("✅ اطلاعات شما با موفقیت ذخیره شد.");
                $this->showCart(); // نمایش مجدد سبد خرید با اطلاعات کامل
                break;
        }
    }
    public function initiateCardPayment($messageId): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);

        if (empty($cart)) {
            $this->Alert("سبد خرید شما خالی است!");
            return;
        }

        // ۱. خواندن تنظیمات و اطلاعات کاربر
        $settings = DB::table('settings')->all();
        $cardNumber = $settings['card_number'] ?? null;
        $cardHolderName = $settings['card_holder_name'] ?? null;

        if (empty($cardNumber) || empty($cardHolderName)) {
            $this->Alert("متاسفانه اطلاعات کارت فروشگاه تنظیم نشده است. لطفاً به مدیریت اطلاع دهید.");
            return;
        }

        // ۲. محاسبه مجدد مبلغ نهایی برای ثبت در فاکتور
        $deliveryCost  = (int)($settings['delivery_price'] ?? 0);
        $taxPercent    = (int)($settings['tax_percent'] ?? 0);
        $allProducts = DB::table('products')->all();
        $totalPrice = 0;
        $productsDetails = [];

        foreach ($cart as $productId => $quantity) {
            if (isset($allProducts[$productId])) {
                $product = $allProducts[$productId];
                $itemPrice = $product['price'] * $quantity;
                $totalPrice += $itemPrice;
                $productsDetails[] = [
                    'id' => $productId,
                    'name' => $product['name'],
                    'quantity' => $quantity,
                    'price' => $product['price']
                ];
            }
        }
        $taxAmount = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost;


        // ۳. ایجاد فاکتور جدید در جدول invoices
        $invoices = DB::table('invoices');
        $newInvoiceId = uniqid('inv_');
        $invoiceData = [
            'id' => $newInvoiceId,
            'user_id' => $this->chatId,
            'user_info' => [
                'name' => $user['shipping_name'],
                'phone' => $user['shipping_phone'],
                'address' => $user['shipping_address']
            ],
            'products' => $productsDetails,
            'total_amount' => $grandTotal,
            'status' => 'pending_payment', // وضعیت: در انتظار پرداخت
            'created_at' => date('Y-m-d H:i:s'),
            'receipt_file_id' => null
        ];
        $invoices->insert($invoiceData);

        // ۴. پاک کردن سبد خرید کاربر
        DB::table('users')->update($this->chatId, ['cart' => '[]']);

        // ۵. نمایش اطلاعات کارت و دکمه ارسال رسید
        $text = "✅ سفارش شما ثبت شد!\n\n";
        $text .= "لطفاً مبلغ <b>" . number_format($grandTotal) . " تومان</b> را به کارت زیر واریز کرده و سپس با استفاده از دکمه زیر، تصویر رسید پرداخت را برای ما ارسال کنید.\n\n";
        $text .= "💳 شماره کارت:\n<code>{$cardNumber}</code>\n\n";
        $text .= "👤 نام صاحب حساب:\n<b>{$cardHolderName}</b>\n\n";
        $text .= "❗️ سفارش شما پس از تایید پرداخت، پردازش و ارسال خواهد شد.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $newInvoiceId]]
            ]
        ];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    public function notifyAdminOfNewReceipt(string $invoiceId, string $receiptFileId): void
    {
        $settings = DB::table('settings')->all();
        $adminId = $settings['admin_id'] ?? null;
        if (empty($adminId)) {
            Logger::log('error', 'notifyAdminOfNewReceipt failed', 'Admin ID is not set in settings.');
            return;
        }

        $invoice = DB::table('invoices')->findById($invoiceId);
        if (!$invoice) {
            Logger::log('error', 'notifyAdminOfNewReceipt failed', 'Invoice not found.', ['invoice_id' => $invoiceId]);
            return;
        }

        // نام متغیر از caption به text برای خوانایی بهتر تغییر کرد
        $userInfo = $invoice['user_info'];
        $products = $invoice['products'];
        $totalAmount = number_format($invoice['total_amount']);
        $createdAt = jdf::jdate('Y/m/d - H:i', strtotime($invoice['created_at']));

        $text = "🔔 رسید پرداخت جدید دریافت شد 🔔\n\n";
        $text .= "📄 شماره فاکتور: `{$invoiceId}`\n";
        $text .= "📅 تاریخ ثبت: {$createdAt}\n\n";
        $text .= "👤 مشخصات خریدار:\n";
        $text .= "- نام: {$userInfo['name']}\n";
        $text .= "- تلفن: `{$userInfo['phone']}`\n";
        $text .= "- آدرس: {$userInfo['address']}\n\n";
        $text .= "🛍 محصولات خریداری شده:\n";
        foreach ($products as $product) {
            $productPrice = number_format($product['price']);
            $text .= "- {$product['name']} (تعداد: {$product['quantity']}, قیمت واحد: {$productPrice} تومان)\n";
        }
        $text .= "\n";
        $text .= "💰 مبلغ کل پرداخت شده: {$totalAmount} تومان\n\n";
       

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید فاکتور', 'callback_data' => 'admin_approve_' . $invoiceId],
                    ['text' => '❌ رد فاکتور', 'callback_data' => 'admin_reject_' . $invoiceId]
                ]
            ]
        ];

        $this->sendRequest("sendPhoto", [
            'chat_id' => $adminId,
            'photo' => $receiptFileId
        ]);

        $this->sendRequest("sendMessage", [
            'chat_id' => $adminId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
        
    }
}
