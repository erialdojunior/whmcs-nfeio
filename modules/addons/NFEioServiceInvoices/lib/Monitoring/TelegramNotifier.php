<?php

namespace NFEioServiceInvoices\Monitoring;

class TelegramNotifier
{
    /**
     * @var string
     */
    private $token;

    /**
     * @var string
     */
    private $chatId;

    public function __construct($token, $chatId)
    {
        $this->token = trim((string) $token);
        $this->chatId = trim((string) $chatId);
    }

    public function isConfigured()
    {
        return preg_match('/^[0-9]{5,15}:[A-Za-z0-9_-]{20,}$/', $this->token) === 1
            && preg_match('/^-?[0-9]+$/', $this->chatId) === 1;
    }

    public function send($message)
    {
        if (!$this->isConfigured()) {
            return $this->failure(0, 'invalid_configuration');
        }

        $message = $this->limitMessage((string) $message, 4096);
        $url = 'https://api.telegram.org/bot' . $this->token . '/sendMessage';
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ], '', '&'),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $curlError !== '') {
            return $this->failure($httpCode, $this->sanitizeError($curlError ?: 'transport_error'));
        }

        $decoded = json_decode($response, true);
        if ($httpCode !== 200 || !is_array($decoded) || empty($decoded['ok'])) {
            $description = is_array($decoded) && isset($decoded['description'])
                ? (string) $decoded['description']
                : 'telegram_api_error';

            return $this->failure($httpCode, $this->sanitizeError($description));
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'error' => null,
        ];
    }

    private function failure($httpCode, $error)
    {
        return [
            'success' => false,
            'http_code' => (int) $httpCode,
            'error' => $this->limitMessage((string) $error, 250),
        ];
    }

    private function sanitizeError($error)
    {
        $error = str_replace($this->token, '[redacted]', (string) $error);
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error));
    }

    private function limitMessage($message, $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, $length, 'UTF-8');
        }

        return substr($message, 0, $length);
    }
}
