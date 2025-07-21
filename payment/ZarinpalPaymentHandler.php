<?php

namespace Payment;

use Config\AppConfig;

class ZarinpalPaymentHandler
{
    private $merchantId;

    public function __construct()
    {
        $config = AppConfig::getConfig();
        $this->merchantId = $config['bot']['merchant_id'];
    }

    /**
     * Create a Zarinpal payment link for challenge participation
     *
     * @param int $amount Payment amount (in Toman)
     * @param int $chatId Telegram chat ID
     * @param int|null $challengeId Challenge ID
     * @return string|null Payment link if successful
     */
    public function createZarinpalPayment($amount, $chatId, $challengeId = null): ?array
    {
        $callbackUrl = "https://rammehraz.com/Rambot/wakeUpchamp/bot.php?chat_id={$chatId}";
        if ($challengeId) {
            $callbackUrl .= "&challenge_id={$challengeId}";
        }

        $data = [
            'merchant_id' => $this->merchantId,
            'amount' => $amount * 10,
            'callback_url' => $callbackUrl,
            'description' => $challengeId ? "Payment for joining challenge {$challengeId}" : "Payment for challenge"
        ];

        $result = $this->sendRequest('https://api.zarinpal.com/pg/v4/payment/request.json', $data);

        error_log("[Zarinpal Debug] Response: " . json_encode($result));

        if (isset($result['data']['code']) && $result['data']['code'] == 100 && isset($result['data']['authority'])) {
            return [
                'authority' => $result['data']['authority'],
                'payment_url' => "https://www.zarinpal.com/pg/StartPay/" . $result['data']['authority']
            ];
        }

        if (isset($result['errors'])) {
            error_log("[Zarinpal Error] " . json_encode($result['errors']));
        }

        return null;
    }




    public function verifyZarinpalPayment($amount, $authority): array
    {
        $data = [
            'merchant_id' => $this->merchantId,
            'amount' => $amount ,
            'authority' => $authority
        ];

        $result = $this->sendRequest('https://api.zarinpal.com/pg/v4/payment/verify.json', $data);

        if (isset($result['data']['code']) && in_array($result['data']['code'], [100, 101])) {
            return [
                'status' => true,
                'ref_id' => $result['data']['ref_id'] ?? null,
                'amount' => $result['data']['amount'] ?? null
            ];
        } else {
            error_log("Zarinpal Payment Error: " . print_r($result['errors'] ?? 'Unknown Error', true));
            return [
                'status' => false,
                'message' => $result['errors']['message'] ?? 'Unknown error'
            ];
        }
    }

    /**
     * Send request to Zarinpal API
     *
     * @param string $url API endpoint
     * @param array $data Request payload
     * @return array API response
     */
    private function sendRequest($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($data))
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        error_log("Zarinpal Response: " . print_r($response, true));

        return json_decode($response, true);
    }
}
