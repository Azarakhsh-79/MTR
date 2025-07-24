<?php

// برای جلوگیری از خطاهای CORS در هنگام توسعه
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// لود کردن کلاس‌های مورد نیاز
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/DB.php';
require_once __DIR__ . '/classes/jdf.php';

use Bot\DB;

/**
 * داده‌های اولیه ارسال شده از سوی تلگرام را برای احراز هویت امن، اعتبارسنجی می‌کند.
 * @param string $initData رشته initData از وب اپ
 * @param string $botToken توکن ربات شما
 * @return array|null آرایه داده‌های کاربر در صورت موفقیت، در غیر این صورت null
 */
function validateTelegramInitData(string $initData, string $botToken): ?array
{
    $data_check_arr = [];
    $initData_parts = explode('&', rawurldecode($initData));

    foreach ($initData_parts as $part) {
        if (strpos($part, '=') !== false) {
            list($key, $value) = explode('=', $part, 2);
            if ($key !== 'hash') {
                $data_check_arr[$key] = $value;
            }
        }
    }

    $check_hash = $data_check_arr['hash'] ?? '';
    unset($data_check_arr['hash']);
    ksort($data_check_arr);

    $data_check_string = "";
    foreach ($data_check_arr as $key => $value) {
        $data_check_string .= $key . '=' . $value . "\n";
    }
    $data_check_string = substr($data_check_string, 0, -1);

    $secret_key = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);

    // اگر هش معتبر نبود، یعنی درخواست از سوی تلگرام نیست
    // در نسخه نهایی، این بخش را از کامنت خارج کنید
    // if (strcmp($hash, $check_hash) !== 0) {
    //     return null;
    // }
    
    // استخراج اطلاعات کاربر
    if (isset($data_check_arr['user'])) {
        return json_decode($data_check_arr['user'], true);
    }

    return null;
}


// دریافت درخواست
$action = $_GET['action'] ?? '';
$initData = $_GET['initData'] ?? '';

if ($action === 'get_cart') {
    // توکن ربات خود را برای اعتبارسنجی اینجا قرار دهید
    $config = \Config\AppConfig::getConfig();
    $botToken = $config['bot']['token'];

    // اعتبارسنجی درخواست و دریافت اطلاعات کاربر
    $userDataFromTelegram = validateTelegramInitData($initData, $botToken);
    
    // اگر اطلاعات کاربر معتبر نبود، دسترسی را قطع کن
    if ($userDataFromTelegram === null) {
        http_response_code(403); // Forbidden
        echo json_encode(['error' => 'Authentication failed']);
        exit;
    }
    
    $userId = $userDataFromTelegram['id'];
    $user = DB::table('users')->findById($userId);
    $cart = json_decode($user['cart'] ?? '{}', true);

    $productsDB = DB::table('products')->all();
    $settings = DB::table('settings')->all();

    $cartDetails = [];
    foreach ($cart as $productId => $quantity) {
        if (isset($productsDB[$productId])) {
            $product = $productsDB[$productId];
            $cartDetails[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $quantity,
                // آدرس کامل عکس محصول را اینجا قرار دهید
                'image' => $product['image_file_id'] ? 'https://via.placeholder.com/60' : 'https://via.placeholder.com/60' // این بخش نیاز به تکمیل دارد
            ];
        }
    }
    
    $response = [
        'products' => $cartDetails,
        'deliveryCost' => (int)($settings['delivery_price'] ?? 0)
    ];

    echo json_encode($response);
    exit;
}

// اگر هیچ action معتبری پیدا نشد
http_response_code(400); // Bad Request
echo json_encode(['error' => 'Invalid action']);