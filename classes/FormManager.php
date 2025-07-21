<?php

namespace Bot;

use Bot\IppanelSmsHandler;
use Payment\ZarinpalPaymentHandler;

class FormManager
{
    private $bot;
    private $stateManager;
    private $forms;


    private static $fileCache = [];

    public function __construct(BotHandler $bot, StateManager $stateManager, array $formsConfig)
    {
        $this->bot = $bot;
        $this->stateManager = $stateManager;
        $this->forms = $formsConfig;
    }

    public function start(string $chatId, string $formKey, ?string $callbackQueryId = null, ?int $messageId = null): void
    {
        if (!isset($this->forms[$formKey])) {
            Logger::log('error', 'Form Start Failed', "Form key '{$formKey}' not found.", ['chat_id' => $chatId]);
            return;
        }

        $form = $this->forms[$formKey];
        $steps = array_keys($form['fields']);
        $initialStep = $steps[0];

        $state = [
            'form_key' => $formKey,
            'current_step' => $initialStep,
            'chat_id' => $chatId,
            'steps' => $steps,
            'data' => [],
            'form_message_id' => $messageId,
            'pages' => [], // Reset pages on form start
        ];

        Logger::log('info', 'Form Started', "Starting form '{$formKey}' for user.", ['chat_id' => $chatId, 'step' => $initialStep]);
        $this->stateManager->saveState($chatId, $state);
        $this->askQuestion($chatId, $state, $callbackQueryId);
    }

    public function handle(string $chatId, $input): void
    {
        if (!$this->stateManager->lockState($chatId)) {
            Logger::log('warning', 'Form Race Condition', "Duplicate request ignored.", ['chat_id' => $chatId]);
            return;
        }

        try {
            $state = $this->stateManager->getState($chatId);
            if (!$state || !isset($state['form_key'])) {
                $this->stateManager->unlockState($chatId);
                return;
            }

            $currentStepKey = $state['current_step'];
            $fieldConfig = $this->forms[$state['form_key']]['fields'][$currentStepKey];

            // **تغییر اصلی شماره ۱: مدیریت اختصاصی مرحله multi_input**
            if (($fieldConfig['type'] ?? 'text') === 'multi_input') {
                if ($input === 'form_action_finish_attachments') {
                    $this->completeForm($chatId, $state); // اتمام فرم با دکمه
                    return;
                }
                // اگر ورودی از نوع فایل/عکس/ویدیو باشد (که در BotHandler به آرایه تبدیل شده)
                if (is_array($input)) {
                    $this->handleAttachment($chatId, $input);
                    return; // منتظر فایل بعدی یا دکمه پایان بمان
                }
                // اگر کاربر متن عادی فرستاد، آن را هم به عنوان پیوست متنی ذخیره کن
                if (is_string($input)) {
                    $this->handleAttachment($chatId, ['text' => $input]);
                    return;
                }
            }

            if (is_string($input) && str_starts_with($input, 'form_page_')) {
                $this->handlePageChange($chatId, $input, $state);
                return;
            }

            switch ($input) {
                case 'form_action_cancel':
                    $this->cancelForm($chatId, $state);
                    return;
                case 'form_action_back':
                    $this->goBack($chatId, $state);
                    return;
            }

            $originalInput = $input;
            $validationResult = $this->validateInput($chatId, $input, $currentStepKey, $fieldConfig, $state['data']);
            if ($validationResult !== true) {
                $this->sendUserFriendlyError($chatId, $validationResult);
                return;
            }
            $state['data'][$currentStepKey] = $input;
            if (($fieldConfig['type'] ?? 'text') === 'phone_with_sms') {
                $this->handlePhoneVerification($chatId, $originalInput);
            }
            $currentStepIndex = array_search($currentStepKey, $state['steps']);
            $nextStepIndex = $currentStepIndex + 1;
            if ($nextStepIndex < count($state['steps'])) {
                $nextStepKey = $state['steps'][$nextStepIndex];
                $nextFieldConfig = $this->forms[$state['form_key']]['fields'][$nextStepKey];
                $state['current_step'] = $nextStepKey;

                // **تغییر اصلی شماره ۲: شروع مرحله multi_input**
                if (($nextFieldConfig['type'] ?? 'text') === 'multi_input') {
                    $this->handleMultiInputStart($chatId, $nextFieldConfig, $state);
                } else {
                    $this->stateManager->saveState($chatId, $state);
                    $this->askQuestion($chatId, $state);
                }
            } else {
                $this->completeForm($chatId, $state);
            }
        } finally {
            $this->stateManager->unlockState($chatId);
        }
    }
    public function handleAttachment(string $chatId, array $message): void
    {
        $item = null;
        if (!empty($message['text'])) {
            $item = ['type' => 'text', 'file_id' => null, 'caption' => $message['text']];
        } elseif (!empty($message['photo'])) {
            $item = ['type' => 'photo', 'file_id' => end($message['photo'])['file_id'], 'caption' => $message['caption'] ?? null];
        } elseif (!empty($message['video'])) {
            $item = ['type' => 'video', 'file_id' => $message['video']['file_id'], 'caption' => $message['caption'] ?? null];
        } elseif (!empty($message['document'])) {
            $item = ['type' => 'document', 'file_id' => $message['document']['file_id'], 'caption' => $message['caption'] ?? null];
        }
        if ($item) {
            $state = $this->stateManager->getState($chatId);
            if ($state && ($state['current_step'] ?? '') === 'attachments') {
                if (!isset($state['temp_attachments'])) $state['temp_attachments'] = [];
                $state['temp_attachments'][] = $item;
                $this->stateManager->saveState($chatId, $state);
                $this->bot->sendRequest("sendMessage", ['chat_id' => $chatId, 'text' => '✅ پیوست شما افزوده شد.']);
            }
        }
    }


    private function handleMultiInputStart(string $chatId, array $fieldConfig, array &$state): void
    {
        $state['temp_attachments'] = [];
        $this->stateManager->saveState($chatId, $state);
        $prompt = $fieldConfig['prompt'];
        $finishButtonText = $fieldConfig['finish_button_text'] ?? 'اتمام';
        $messageId = $state['form_message_id'] ?? null;

        $params = [
            'chat_id' => $chatId,
            'text' => $prompt,
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => $finishButtonText, 'callback_data' => 'form_action_finish_attachments']]]
            ])
        ];
        if ($messageId) {
            $this->bot->sendRequest('editMessageText', array_merge($params, ['message_id' => $messageId]));
        } else {
            $this->bot->sendRequest('sendMessage', $params);
        }
    }

    private function askQuestion(string $chatId, array $state, ?string $callbackQueryId = null): void
    {
        $stepKey = $state['current_step'];
        $formKey = $state['form_key'];
        $fieldConfig = $this->forms[$formKey]['fields'][$stepKey];
        $data = $state['data'] ?? [];

        $questionText = $this->buildQuestionText($formKey, $stepKey, $data);
        $keyboard = $this->buildKeyboard($formKey, $stepKey, $data, $chatId);

        $params = [
            'chat_id' => $chatId,
            'text' => $questionText,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];

        $messageId = $state['form_message_id'] ?? null;

        $response = null;
        if ($messageId) {
            $response = $this->bot->sendRequest('editMessageText', array_merge($params, ['message_id' => $messageId]));
        } else {
            $response = $this->bot->sendRequest('sendMessage', $params);
        }

        if ($response && isset($response['result']['message_id']) && !$messageId) {
            $state['form_message_id'] = $response['result']['message_id'];
            $this->stateManager->saveState($chatId, $state);
        } elseif (!$response) {
            Logger::log('error', 'Form Message Error', "Failed to send or edit form question.", ['chat_id' => $chatId, 'step' => $stepKey]);
        }

        if ($callbackQueryId) {
            $this->bot->sendRequest('answerCallbackQuery', ['callback_query_id' => $callbackQueryId]);
        }
    }

    private function buildQuestionText(string $formKey, string $currentStepKey, array $data): string
    {
        $summary = "<b>خلاصه اطلاعات ثبت شده:</b>\n";
        $hasData = false;

        foreach ($this->forms[$formKey]['fields'] as $key => $fieldConfig) {
            if ($key === $currentStepKey) {
                break;
            }

            if (array_key_exists($key, $data)) {
                $label = $fieldConfig['label'];
                $value = $data[$key];

                $displayValue = '';

                if (is_null($value)) {
                    $displayValue = '---';
                } elseif (is_array($value)) {
                    $displayValue = !empty($value) ? implode(', ', $value) : '---';
                } else {
                    $displayValue = (string)$value;
                }
                $summary .= "<b>- {$label}:</b> " . htmlspecialchars($displayValue) . "\n";
                $hasData = true;
            }
        }

        if (!$hasData) {
            $summary = '';
        }

        $prompt = $this->forms[$formKey]['fields'][$currentStepKey]['prompt'];
        return $summary . "\n" . $prompt;
    }
    private function buildKeyboard(string $formKey, string $stepKey, array $data, string $chatId): array
    {
        $fieldConfig = $this->forms[$formKey]['fields'][$stepKey];
        $keyboard = [];
        $fieldType = $fieldConfig['type'] ?? 'text';

        if ($fieldType === 'buttons' && !empty($fieldConfig['options'])) {
            $keyboard = array_merge($keyboard, $fieldConfig['options']);
        } elseif ($fieldType === 'dynamic_buttons' && !empty($fieldConfig['source'])) {
            $sourceName = $fieldConfig['source'];

            if (!isset(self::$fileCache[$sourceName])) {
                $sourceFile = __DIR__ . '/data/' . $sourceName;
                if (file_exists($sourceFile)) {
                    self::$fileCache[$sourceName] = json_decode(file_get_contents($sourceFile), true);
                    Logger::log('info', 'Cache Miss', "Loaded '{$sourceName}' from file into cache.", ['chat_id' => $chatId]);
                } else {
                    self::$fileCache[$sourceName] = [];
                }
            }
            $optionsData = self::$fileCache[$sourceName];

            $filterKey = $fieldConfig['source_key'] ?? $fieldConfig['source_filter_by'] ?? null;
            if ($filterKey && isset($data[$filterKey])) {
                $filterValue = $data[$filterKey];
                // Handle different structures for cities.json
                $optionsData = $optionsData[$filterValue]['cities'] ?? ($optionsData[$filterValue] ?? []);
            }

            $itemsPerPage = $fieldConfig['items_per_page'] ?? 10;
            $currentPage = $this->stateManager->getStepPage($chatId, $stepKey);
            $totalItems = count($optionsData);
            $totalPages = ceil($totalItems / $itemsPerPage);
            $offset = ($currentPage - 1) * $itemsPerPage;
            $paginatedOptions = array_slice($optionsData, $offset, $itemsPerPage, true);

            $tempRow = [];
            foreach ($paginatedOptions as $key => $option) {
                $text = is_array($option) ? ($option['name'] ?? $key) : $option;
                $callback = is_array($option) ? ($option['name'] ?? $key) : $option;
                $tempRow[] = ['text' => $text, 'callback_data' => $callback];

                if (count($tempRow) >= 2) {
                    $keyboard[] = $tempRow;
                    $tempRow = [];
                }
            }
            if (!empty($tempRow)) {
                $keyboard[] = $tempRow;
            }

            $paginationRow = [];
            if ($currentPage > 1) {
                $paginationRow[] = ['text' => '⬅️ قبلی', 'callback_data' => 'form_page_prev'];
            }
            if ($currentPage < $totalPages) {
                $paginationRow[] = ['text' => 'بعدی ➡️', 'callback_data' => 'form_page_next'];
            }
            if (!empty($paginationRow)) {
                $keyboard[] = $paginationRow;
            }
        }

        $navigationRow = [];
        $currentStepIndex = array_search($stepKey, array_keys($this->forms[$formKey]['fields']));
        if ($currentStepIndex > 0) {
            $navigationRow[] = ['text' => '➡️ بازگشت', 'callback_data' => 'form_action_back'];
        }
        $navigationRow[] = ['text' => '❌ لغو', 'callback_data' => 'form_action_cancel'];
        $keyboard[] = $navigationRow;

        return $keyboard;
    }

    private function goBack(string $chatId, array &$state): void
    {
        $currentStepIndex = array_search($state['current_step'], $state['steps']);

        if ($currentStepIndex > 0) {
            $previousStepKey = $state['steps'][$currentStepIndex - 1];
            Logger::log('info', 'Form Go Back', "Navigating back.", ['chat_id' => $chatId, 'to_step' => $previousStepKey], true);

            $state['current_step'] = $previousStepKey;
            $this->stateManager->saveStepPage($chatId, $previousStepKey, 1);
            $this->stateManager->saveState($chatId, $state);
            $this->askQuestion($chatId, $state);
        }
    }

    private function handlePageChange(string $chatId, string $input, array &$state): void
    {
        $parts = explode('_', $input);
        $direction = end($parts);
        $stepKey = $state['current_step'];

        $currentPage = $this->stateManager->getStepPage($chatId, $stepKey);
        $newPage = ($direction === 'next') ? $currentPage + 1 : max(1, $currentPage - 1);

        $this->stateManager->saveStepPage($chatId, $stepKey, $newPage);

        $updatedState = $this->stateManager->getState($chatId);
        $this->askQuestion($chatId, $updatedState);
    }

    private function validateInput(string $chatId, &$input, string $fieldKey, array $fieldConfig, array $stateData): mixed
    {
        $rules = $fieldConfig['validation'] ?? '';

        if (($fieldKey === 'min_salary' || $fieldKey === 'max_salary') && $input === "توافقی") {
            $input = null;
        }

        if ($fieldKey === 'verify_code') {
            $storedCode = $this->stateManager->getVerificationCode($chatId);
            if ($storedCode !== $input) {
                return 'کد وارد شده صحیح نمی‌باشد.';
            }
            $this->stateManager->clearVerificationCode($chatId);
            $rules = str_replace('matches_code', '', $rules);
        }

        return $this->validate($input, $rules, $stateData);
    }

    private function validate($input, string $rules, array $allData): mixed
    {
        $rules = explode('|', $rules);

        if (in_array('nullable', $rules) && (is_null($input) || $input === '')) {
            return true;
        }

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if ($rule === 'nullable' || (is_null($input) || $input === '')) {
                continue;
            }

            if ($rule === 'required' && (is_null($input) || $input === '')) {
                return 'این فیلد اجباری است.';
            }

            if ($rule === 'numeric' && !is_numeric($input)) {
                return 'ورودی باید یک عدد باشد.';
            }
            if ($rule === 'phone_number' && !preg_match('/^(09\d{9})$/', $input)) {
                return 'فرمت شماره تلفن صحیح نیست. (مثال: 09123456789)';
            }

            if (str_starts_with($rule, 'min:')) {
                $min = (int)str_replace('min:', '', $rule);
                if (mb_strlen((string)$input) < $min) return "ورودی باید حداقل {$min} کاراکتر باشد.";
            }
            if (str_starts_with($rule, 'max:')) {
                $max = (int)str_replace('max:', '', $rule);
                if (mb_strlen((string)$input) > $max) return "ورودی باید حداکثر {$max} کاراکتر باشد.";
            }
            if (str_starts_with($rule, 'gte:')) {
                $compareField = str_replace('gte:', '', $rule);
                if (isset($allData[$compareField]) && is_numeric($input) && is_numeric($allData[$compareField]) && (int)$input < (int)$allData[$compareField]) {
                    return 'مقدار حداکثر حقوق نمی‌تواند کمتر از حداقل حقوق باشد.';
                }
            }
        }
        return true;
    }

    private function sendUserFriendlyError(string $chatId, string $errorMessage): void
    {
        $this->bot->sendRequest("sendMessage", [
            'chat_id' => $chatId,
            'text' => '❌ ' . $errorMessage,
        ]);
    }

    private function handlePhoneVerification(string $chatId, string $phoneNumber): void
    {
        $verificationCode = (string)rand(10000, 99999);
        $this->stateManager->saveVerificationCode($chatId, $verificationCode);
        $smsHandler = new IppanelSmsHandler();
        $smsHandler->sendVerificationCode($phoneNumber, $verificationCode);
        $this->bot->sendRequest("sendMessage", [
            'chat_id' => $chatId,
            'text' => "کد تایید شما : " . $verificationCode
        ]);
    }



    private function completeForm(string $chatId, array $state): void
    {
        Logger::log('success', 'Form Completed', "All steps completed. Processing final data.", [
            'chat_id' => $chatId,
            'form_key' => $state['form_key'],
            'data' => $state['data']
        ]);
        $formKey = $state['form_key'];
        $formConfig = $this->forms[$formKey];

        if (isset($state['temp_attachments'])) {
            $state['data']['attachments'] = $state['temp_attachments'];
            unset($state['temp_attachments']);
        }

        $data = $state['data'];
        $result = null;

        if (isset($formConfig['on_complete']) && is_callable($formConfig['on_complete'])) {
            $result = call_user_func($formConfig['on_complete'], $data, $chatId);
        }

        $messageId = $state['form_message_id'] ?? null;
        if (isset($formConfig['on_complete_prompt'])) {
            $promptConfig = $formConfig['on_complete_prompt'];
            $text = $promptConfig['text'];

            if (isset($result['ad_id'])) $text = str_replace('{ad_id}', $result['ad_id'], $text);
            if (isset($result['fee'])) $text = str_replace('{fee}', number_format($result['fee']), $text);

            $keyboard = $promptConfig['keyboard'] ?? [];
            if ($result && !empty($keyboard)) {
                $keyboardString = json_encode($keyboard);
                if (isset($result['payment_url'])) $keyboardString = str_replace('{payment_url}', $result['payment_url'], $keyboardString);
                $keyboard = json_decode($keyboardString, true);
            }

            $method = $messageId ? 'editMessageText' : 'sendMessage';
            $params = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => !empty($keyboard) ? json_encode(['inline_keyboard' => $keyboard]) : json_encode([]),
            ];
            if ($messageId) $params['message_id'] = $messageId;

            $this->bot->sendRequest($method, $params);
        }

        $this->stateManager->clearState($chatId);
    }

    private function cancelForm(string $chatId, array $state): void
    {
        Logger::log('warning', 'Form Canceled', "User canceled the form.", [
            'chat_id' => $chatId,
            'form_key' => $state['form_key'],
            'at_step' => $state['current_step']
        ]);
        $formKey = $state['form_key'];
        $message = $this->forms[$formKey]['on_cancel'] ?? 'عملیات لغو شد.';
        $messageId = $state['form_message_id'] ?? null;

        if ($messageId) {
            $this->bot->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $message,
                'reply_markup' => json_encode(['inline_keyboard' => []])
            ]);
        } else {
            $this->bot->sendRequest('sendMessage', ['chat_id' => $chatId, 'text' => $message]);
        }
        $this->stateManager->clearState($chatId);
    }
}

class FormProcessor
{
    public static function saveAdAndSetPendingPayment(array $formData, string $chatId): ?array
    {
        try {
            $db = new Database();

            $adId = $db->createAd(
                (int)$chatId,
                $formData['title'] ?? 'بدون عنوان',
                $formData['description'] ?? '',
                $formData['province'] ?? '',
                $formData['city'] ?? '',
                $formData['collaboration_type'] ?? 'نامشخص',
                $formData['min_salary'] ?? 0,
                $formData['max_salary'] ?? 0,
                $formData['experience_level'] ?? 'نامشخص',
                $formData['remote_work'] ?? 'no',
                $formData['military_service_status'] ?? 'not_important',
                $formData['phone_number'] ?? '',
                $formData['attachments'] ?? []
            );

            if ($adId === false) {
                error_log("Failed to create ad in database for chat ID: {$chatId}");
                return null;
            }

            $adCreationFee = (int)($db->getSettingValue('ad_creation_fee') ?? 10000);

            if ($adCreationFee <= 0) {
                $bot = new BotHandler($chatId, '', null, null, null);
                $bot->sendAdForApprovalToAdmin($adId);
                $db->updateAdStatus($adId, 'pending_approval');
                return ['ad_id' => $adId, 'payment_url' => null, 'fee' => 0];
            }

            $paymentHandler = new ZarinpalPaymentHandler();
            $paymentUrl = $paymentHandler->createAdPayment($adId, $adCreationFee);

            if (!$paymentUrl) {
                error_log("Failed to create Zarinpal payment link for ad ID: {$adId}");
                return ['ad_id' => $adId, 'payment_url' => null, 'fee' => $adCreationFee];
            }

            return ['ad_id' => $adId, 'payment_url' => $paymentUrl, 'fee' => $adCreationFee];
        } catch (\Exception $e) {
            error_log("Error in FormProcessor::saveAdAndSetPendingPayment: " . $e->getMessage());
            return null;
        }
    }
}
