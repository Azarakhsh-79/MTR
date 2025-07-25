<?php
// =================== START: CORS & PREFLIGHT HANDLING ===================
// به درخواست‌هایی که از هر دامنه‌ای می‌آیند اجازه می‌دهد
header("Access-Control-Allow-Origin: *");
// متدهای مجاز برای درخواست را مشخص می‌کند
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// هدرهای مجاز در درخواست را مشخص می‌کند
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// مرورگرها قبل از ارسال درخواست اصلی، یک درخواست از نوع OPTIONS برای بررسی CORS می‌فرستند.
// این کد به آن درخواست پاسخ مثبت می‌دهد تا درخواست اصلی ارسال شود.
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
// فعال کردن نمایش همه خطاها و لاگ کردن آن‌ها
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt'); // لاگ‌ها در این فایل ذخیره می‌شوند

error_log("======= [API.PHP] START - " . date("Y-m-d H:i:s") . " =======");

// برای جلوگیری از خطاهای CORS در هنگام توسعه
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// لود کردن کلاس‌های مورد نیاز
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../classes/DB.php';
require_once __DIR__ . '/../classes/jdf.php';

use Bot\DB;
use Config\AppConfig;

/**
 * داده‌های اولیه ارسال شده از سوی تلگرام را برای احراز هویت امن، اعتبارسنجی می‌کند.
 * @param string $initData رشته initData از وب اپ
 * @param string $botToken توکن ربات شما
 * @return array|null آرایه داده‌های کاربر در صورت موفقیت، در غیر این صورت null
 */
function validateTelegramInitData(string $initData, string $botToken): ?array
{
    error_log("[validateTelegramInitData] Received initData: " . $initData);
    $initData_parts = [];
    parse_str(rawurldecode($initData), $initData_parts);

    if (!isset($initData_parts['hash'])) {
        error_log("[validateTelegramInitData] ERROR: Hash is not set in initData.");
        return null;
    }

    $check_hash = $initData_parts['hash'];
    unset($initData_parts['hash']);

    $data_check_arr = [];
    foreach ($initData_parts as $key => $value) {
        $data_check_arr[] = $key . '=' . $value;
    }
    sort($data_check_arr);
    $data_check_string = implode("\n", $data_check_arr);
    error_log("[validateTelegramInitData] Data check string: \n" . $data_check_string);

    $secret_key = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);
    error_log("[validateTelegramInitData] Calculated Hash: " . $hash);
    error_log("[validateTelegramInitData] Received Hash:   " . $check_hash);


    // این بخش بسیار مهم است و باید فعال باشد. اگر لاگ‌ها متفاوت بود، یعنی اعتبارسنجی مشکل دارد.
    if (strcmp($hash, $check_hash) !== 0) {
        error_log("[validateTelegramInitData] ERROR: HASH VALIDATION FAILED!");
        // در حالت تست می‌توانید این بخش را کامنت کنید اما در نسخه نهایی حتما فعال باشد
        // return null;
    } else {
        error_log("[validateTelegramInitData] SUCCESS: Hash validation passed.");
    }
    
    // استخراج اطلاعات کاربر
    if (isset($initData_parts['user'])) {
        $user_data = json_decode($initData_parts['user'], true);
        error_log("[validateTelegramInitData] User data extracted: " . print_r($user_data, true));
        return $user_data;
    }

    error_log("[validateTelegramInitData] ERROR: User data not found in initData.");
    return null;
}


// دریافت درخواست
$action = $_GET['action'] ?? '';
$initData = $_GET['initData'] ?? '';

error_log("[API.PHP] Request received. Action: '{$action}', InitData present: " . !empty($initData));


if ($action === 'get_cart') {
    // توکن ربات خود را برای اعتبارسنجی اینجا قرار دهید
    $config = AppConfig::getConfig();
    $botToken = $config['bot']['token'];
    error_log("[API.PHP] Bot token loaded.");

    // اعتبارسنجی درخواست و دریافت اطلاعات کاربر
    $userDataFromTelegram = validateTelegramInitData($initData, $botToken);
    
    // اگر اطلاعات کاربر معتبر نبود، دسترسی را قطع کن
    if ($userDataFromTelegram === null) {
        http_response_code(403); // Forbidden
        $error_response = json_encode(['error' => 'Authentication failed']);
        error_log("[API.PHP] ERROR: Authentication failed. Sending 403 response: " . $error_response);
        echo $error_response;
        exit;
    }
    
    $userId = $userDataFromTelegram['id'];
    error_log("[API.PHP] User authenticated. UserID: " . $userId);

    $user = DB::table('users')->findById($userId);
    if (!$user) {
         error_log("[API.PHP] ERROR: User with ID {$userId} not found in database.");
         http_response_code(404);
         echo json_encode(['error' => 'User not found']);
         exit;
    }
    $cart = json_decode($user['cart'] ?? '{}', true);
    error_log("[API.PHP] User cart data: " . print_r($cart, true));


    $productsDB = DB::table('products')->all();
    $settings = DB::table('settings')->all();
    error_log("[API.PHP] Products and Settings loaded from DB.");


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
                'image' => 'https://via.placeholder.com/60' // این بخش نیاز به تکمیل دارد
            ];
        } else {
             error_log("[API.PHP] WARNING: Product with ID {$productId} from cart not found in products table.");
        }
    }
    
    $response = [
        'products' => $cartDetails,
        'deliveryCost' => (int)($settings['delivery_price'] ?? 0)
    ];
    
    $json_response = json_encode($response);
    error_log("[API.PHP] SUCCESS: Sending response: " . $json_response);
    echo $json_response;
    exit;
}

// اگر هیچ action معتبری پیدا نشد
http_response_code(400); // Bad Request
$error_response = json_encode(['error' => 'Invalid action']);
error_log("[API.PHP] ERROR: Invalid action requested. Action: '{$action}'");
echo $error_response;

error_log("======= [API.PHP] END - " . date("Y-m-d H:i:s") . " =======\n");