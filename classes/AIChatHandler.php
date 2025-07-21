<?php

namespace Bot;

use Config\AppConfig;

class AIChatHandler
{
    private $chatId;
    private $bot;
    private $db;
    private $apiKey;
    private $model;
    private $temperature;

    public function __construct($chatId, $text, BotHandler $botHandler = null)
    {
        $this->chatId = $chatId;
        $this->bot = $botHandler;
        $this->db = new Database();
        $config = AppConfig::getConfig();
        $this->apiKey = $config['ai']['gpt']['api_key'] ?? '';
        $this->model = $config['ai']['gpt']['model'] ?? 'gpt-4';
        $this->temperature = $config['ai']['gpt']['temperature'] ?? 0.7;
    }
    private function deleteMessage($chatId, $messageId)
{
    $requestData = [
        "chat_id" => $chatId,
        "message_id" => $messageId
    ];

    return $this->getBotHandler()->sendRequest("deleteMessage", $requestData);
}

    private function logToFile($filePath, $message)
{
    $directory = dirname($filePath);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $logMessage = "[" . date("Y-m-d H:i:s") . "] " . $message . "\n";
    file_put_contents($filePath, $logMessage, FILE_APPEND);
}


    private function getBotHandler()
    {
        if ($this->bot === null) {
            $this->bot = new BotHandler($this->chatId, "", null, null);
        }
        return $this->bot;
    }

public function chatWithGPT($chatId, $userMessage)
{
    $waitingMessage = $this->sendMessage($chatId, "⏳ Please wait while we process your request...\n\n⚠️ Note: The response time may vary depending on the complexity of your request and the AI model's processing time. Thank you for your patience!");

    if (!isset($waitingMessage["result"]["message_id"])) {
        $waitingMessageId = null;
    } else {
        $waitingMessageId = $waitingMessage["result"]["message_id"];
    }

    $chatHistory = $this->getChatHistory($chatId, 10); 
    $this->logToFile(__DIR__ . "/../../logs/debug.log", "🔹 Chat History for chat_id $chatId: " . json_encode($chatHistory, JSON_PRETTY_PRINT));

    $this->db->saveChatHistory($chatId, "user", "text", $userMessage);

    $messages = [
        ["role" => "system", "content" => "You are an AI assistant specialized in architecture and career guidance."]
    ];

    foreach ($chatHistory as $msg) {
        if (isset($msg["role"], $msg["content"])) {
            $messages[] = [
                "role" => ($msg["role"] === "assistant" ? "assistant" : "user"),
                "content" => $msg["content"]
            ];
        }
    }

    $messages[] = ["role" => "user", "content" => $userMessage];

    $this->logToFile(__DIR__ . "/../../logs/debug.log", "🔹 Final Messages Sent to OpenAI for chat_id $chatId: " . json_encode($messages, JSON_PRETTY_PRINT));

    $aiResponse = $this->sendToOpenAI($messages);
    $this->db->saveChatHistory($chatId, "assistant", "text", $aiResponse);

    if ($waitingMessageId) {
        $this->deleteMessage($chatId, $waitingMessageId);
    }

    $this->sendMessage($chatId, $aiResponse);
}







  public function handleMediaMessage($chatId, $fileId, $type, $caption = null)
{
    $this->sendMessage($chatId, "📷 Media received! Processing your " . ($type === 'photo' ? 'photo' : 'video') . "...");

    $filePath = $this->getFilePath($fileId);
    if (!$filePath) {
        $this->logToFile(__DIR__ . "/../../logs/error.log", "❌ Failed to retrieve media file for chat_id: $chatId, file_id: $fileId");
        $this->sendMessage($chatId, "❌ Failed to retrieve media file from Telegram.");
        return;
    }

    $fileUrl = "https://api.telegram.org/file/bot" . $this->getBotHandler()->botToken . "/" . $filePath;
    $caption = $caption ?: "Analyze this " . ($type === 'photo' ? "image" : "video") . " and describe its contents.";

    $this->db->saveChatHistory($chatId, "user", $type, $caption, $fileUrl);

    $messages = [
        ["role" => "system", "content" => "You are an AI assistant capable of analyzing images and videos."],
        [
            "role" => "user",
            "content" => [
                ["type" => "text", "text" => $caption],
                ["type" => "image_url", "image_url" => $fileUrl]
            ]
        ]
    ];

    $this->logToFile(__DIR__ . "/../../logs/debug.log", "🔹 Sending Image/Video Analysis Request for chat_id $chatId: " . json_encode($messages, JSON_PRETTY_PRINT));

    $aiResponse = $this->sendToOpenAI($messages, "gpt-4-vision-preview");

    $this->db->saveChatHistory($chatId, "assistant", "text", $aiResponse);

    $this->sendMessage($chatId, "🤖 AI Analysis Result: \n" . $aiResponse);
}



   public function aiMenu($chatId): void
{
    $keyboard = [
        [
            ["text" => "🤖 Chat with AI", "callback_data" => "ai_chat_architecture"]
        ],
        [
            ["text" => "exit menu", "callback_data" => "exit_menu"]
        ]
    ];

    $this->sendMessage($chatId, "🤖 Welcome to AI Chat! Click a button below to start.", $keyboard, false);
}


  private function sendMessage($chatId, $text, $keyboard = null, $showExitButton = true)
{
    if ($showExitButton) {
        $exitButton = [["text" => "exit", "callback_data" => "exit_chat"]];
        if ($keyboard) {
            $keyboard[] = $exitButton;
        } else {
            $keyboard = [$exitButton];
        }
    }

    $requestData = [
        "chat_id" => $chatId,
        "text" => $text,
        "reply_markup" => json_encode(["inline_keyboard" => $keyboard ?? []])
    ];

    return $this->getBotHandler()->sendRequest("sendMessage", $requestData);
}



    private function getChatHistory($chatId)
{
    $history = $this->db->getChatHistory($chatId) ?? [];

    $this->logToFile(__DIR__ . "/../../logs/debug.log", "🔹 Raw DB Chat History for chat_id $chatId: " . json_encode($history, JSON_PRETTY_PRINT));

    return $history;
}

    private function sendToOpenAI($messages, $model = "gpt-4")
{
    $apiUrl = "https://api.openai.com/v1/chat/completions";
    $data = [
        "model" => $model,
        "messages" => $messages,
        "temperature" => (float) $this->temperature
    ];

    $headers = [
        "Authorization: Bearer " . $this->apiKey,
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $decodedResponse = json_decode($response, true);

    $this->logToFile(__DIR__ . "/../../logs/debug.log", "🔹 OpenAI API Response for chat_id: " . json_encode($decodedResponse, JSON_PRETTY_PRINT));
    $this->logToFile(__DIR__ . "/../../logs/debug.log", "🔹 HTTP Code: " . $httpCode . " | CURL Error: " . $error);

    return $decodedResponse['choices'][0]['message']['content'] ?? "❌ AI couldn't process the message.";
}


    private function getFilePath($fileId)
    {
        $fileInfo = $this->getBotHandler()->sendRequest("getFile", ["file_id" => $fileId]);
        return $fileInfo['result']['file_path'] ?? null;
    }
}
