<?php

declare(strict_types=1);

namespace App;

class Telegram
{
    private int $lastSendTime = 0;

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

        // Rate limit: Telegram group дээр ~20 msg/min
        // Мессеж хооронд хамгийн багадаа 100ms зайтай байна
        $now = (int)(microtime(true) * 1000);
        $elapsed = $now - $this->lastSendTime;
        if ($elapsed < 100) {
            usleep((100 - $elapsed) * 1000);
        }
        $this->lastSendTime = (int)(microtime(true) * 1000);

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

            // Rate limit: retry_after байвал хүлээгээд дахин оролдоно
            $retryAfter = $decoded['parameters']['retry_after'] ?? 0;
            if ($retryAfter > 0) {
                echo "[Telegram] Rate limited, sleeping {$retryAfter}s..." . PHP_EOL;
                sleep($retryAfter);
                // Retry once
                $ch2 = curl_init();
                curl_setopt_array($ch2, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($postFields),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $retry = curl_exec($ch2);
                curl_close($ch2);
                $retryDecoded = json_decode($retry, true);
                return isset($retryDecoded['ok']) && $retryDecoded['ok'] === true;
            }

            return false;
        }

        return true;
    }
}
