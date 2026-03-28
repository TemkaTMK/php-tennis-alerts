<?php

declare(strict_types=1);

namespace App;

class FeedAdapter
{
    public function __construct(
        private string $apiKey
    ) {}

    public function getLiveMatches(): array
    {
        if (empty($this->apiKey)) {
            error_log('FeedAdapter: TENNIS_API_KEY is missing');
            return [];
        }

        $url = 'https://api.api-tennis.com/tennis/?method=get_livescore&APIkey=' . urlencode($this->apiKey);

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
            error_log('FeedAdapter: invalid JSON response');
            return [];
        }

        return $data['result'] ?? [];
    }
}
