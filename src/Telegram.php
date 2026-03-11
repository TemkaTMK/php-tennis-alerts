<?php

declare(strict_types=1);

class Telegram
{
    public static function send($text)
    {
        $token = getenv('TELEGRAM_BOT_TOKEN');
        $chatId = getenv('TELEGRAM_CHAT_ID');

        if (!$token || !$chatId) {
            return 'ENV_MISSING';
        }

        $url = "https://api.telegram.org/bot" . $token . "/sendMessage";

        $postFields = [
            'chat_id' => $chatId,
            'text' => $text
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $result = curl_exec($ch);

        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return 'CURL_ERROR: ' . $err;
        }

        curl_close($ch);
        return $result;
    }
}
