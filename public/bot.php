<?php

require __DIR__ . '/vendor/autoload.php';

use Config\AppConfig;
use Bot\BotHandler;
use Bot\InlineQueryHandler;
use Payment\ZarinpalPaymentHandler;

$config = AppConfig::getConfig();


$update = json_decode(file_get_contents('php://input'), true);


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($update['inline_query'])) {
    $inlineQuery = $update['inline_query'];
    $query = $inlineQuery['query'];

    $inlineQueryHandler = new InlineQueryHandler();
    $inlineQueryHandler->handleInlineQuery($inlineQuery);
} elseif (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $messageId = $message['message_id'] ?? null;

    $bot = new BotHandler($chatId, $text, $messageId, $message);

    if (isset($message['successful_payment'])) {
        $bot->handleSuccessfulPayment($update);
    } else {
        $bot->handleRequest();
    }
} elseif (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'] ?? null;
    $bot = new BotHandler($chatId, '', $messageId, $callbackQuery['message']);
    $bot->handleCallbackQuery($callbackQuery);
}  elseif (isset($update['pre_checkout_query'])) {
    $bot = new BotHandler(null, null, null, null);
    $bot->handlePreCheckoutQuery($update);
}
