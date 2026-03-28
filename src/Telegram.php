<?php

declare(strict_types=1);

namespace App;

class Telegram
{
    public function __construct(
        private string $token,
        private string $chatId
    ) {}

    public function send(string $text): bool
    {
        if (empty($this->token) || empty($this->chatId)) {
            error_log('Telegram: token or chat_id is missing');
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";

        $postFields = [
            'chat_id' => $this->chatId,
            'text' => $text,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result = curl_exec($ch);

        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            error_log('Telegram CURL error: ' . $err);
            return false;
        }

        curl_close($ch);

        $decoded = json_decode($result, true);
        if (!isset($decoded['ok']) || $decoded['ok'] !== true) {
            error_log('Telegram API error: ' . $result);
            return false;
        }

        return true;
    }
}
