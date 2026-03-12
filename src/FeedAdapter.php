<?php

declare(strict_types=1);

class FeedAdapter
{
    public static function getLiveMatches(): array
    {
        $apiKey = getenv('TENNIS_API_KEY');

        if (!$apiKey) {
            return [];
        }

        $url = 'https://api.api-tennis.com/tennis/?method=get_livescore&APIkey=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log('FeedAdapter API error: ' . $error . ' | HTTP ' . $httpCode);
            return [];
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return [];
        }

        return $data['result'] ?? [];
    }
}
