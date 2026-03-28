<?php

declare(strict_types=1);

namespace App;

class FeedAdapter
{
    public function __construct(
        private string $apiKey
    ) {}

    /**
     * API-Tennis-ээс live match-уудыг авч, internal format руу хөрвүүлнэ.
     */
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

        if (!is_array($data) || ($data['success'] ?? 0) != 1) {
            error_log('FeedAdapter: invalid response or API error');
            return [];
        }

        $results = $data['result'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        return array_map([$this, 'normalize'], $results);
    }

    /**
     * API-Tennis response-г internal format руу хөрвүүлнэ.
     */
    private function normalize(array $event): array
    {
        $pointbypoint = $event['pointbypoint'] ?? [];
        $lastGame = !empty($pointbypoint) ? end($pointbypoint) : [];
        $lastGamePoints = $lastGame['points'] ?? [];
        $lastPoint = !empty($lastGamePoints) ? end($lastGamePoints) : [];

        // Одоогийн game-ийн хамгийн сүүлийн point score-г parse хийх
        // Format: "0 - 30", "15 - 15" гэх мэт
        $serverPts = -1;
        $returnPts = -1;
        if (!empty($lastPoint['score'])) {
            $parts = array_map('trim', explode('-', $lastPoint['score']));
            if (count($parts) === 2) {
                $serverPts = (int) $parts[0];
                $returnPts = (int) $parts[1];
            }
        }

        // Server тодорхойлох: event_serve = "First Player" эсвэл "Second Player"
        $serve = $event['event_serve'] ?? '';
        $server = '';
        if ($serve === 'First Player') {
            $server = $event['event_first_player'] ?? '';
        } elseif ($serve === 'Second Player') {
            $server = $event['event_second_player'] ?? '';
        }

        // Game index: pointbypoint массивын нийт тоо
        $gameIndex = count($pointbypoint);

        // Score text: sets-ийн оноо нэгтгэх
        $scoreText = $this->buildScoreText($event);

        return [
            'match_id'    => (string) ($event['event_key'] ?? ''),
            'player1'     => $event['event_first_player'] ?? '',
            'player2'     => $event['event_second_player'] ?? '',
            'server'      => $server,
            'score_text'  => $scoreText,
            'game_index'  => $gameIndex,
            'point_score' => [
                'server'   => $serverPts,
                'returner' => $returnPts,
            ],
            'level'       => $event['event_type_type'] ?? '',
            'surface'     => $event['tournament_name'] ?? '',
            'status'      => $event['event_status'] ?? '',
            'tournament'  => $event['tournament_name'] ?? '',
            'round'       => $event['tournament_round'] ?? '',
            // Шинэ game эхэлсэн эсэхийг шалгах
            'game_just_started' => $this->isGameJustStarted($lastGamePoints),
            // Raw pointbypoint data (RuleEngine-д хэрэгтэй)
            'pointbypoint' => $pointbypoint,
        ];
    }

    /**
     * Set-үүдийн оноог нэг мөрөнд нэгтгэх.
     */
    private function buildScoreText(array $event): string
    {
        $scores = $event['scores'] ?? [];
        if (empty($scores) || !is_array($scores)) {
            return $event['event_final_result'] ?? '0 - 0';
        }

        $parts = [];
        foreach ($scores as $set) {
            $parts[] = ($set['score_first'] ?? '0') . '-' . ($set['score_second'] ?? '0');
        }

        $text = implode(' ', $parts);

        // Одоогийн game score нэмэх
        $gameResult = $event['event_game_result'] ?? '';
        if (!empty($gameResult)) {
            $text .= ' (' . $gameResult . ')';
        }

        return $text;
    }

    /**
     * Game дөнгөж эхэлсэн эсэх (1-2 point-той).
     */
    private function isGameJustStarted(array $points): bool
    {
        return count($points) <= 2;
    }
}
