<?php

declare(strict_types=1);

namespace App;

use PDO;

class RuleEngine
{
    public function __construct(
        private Telegram $telegram,
        private PDO $pdo
    ) {}

    public function process(array $match): void
    {
        $pointbypoint = $match['pointbypoint'] ?? [];
        if (empty($pointbypoint)) {
            return;
        }

        $matchId   = $match['match_id'] ?? '';
        $player1   = $match['player1'] ?? '';
        $player2   = $match['player2'] ?? '';
        $scoreText = $match['score_text'] ?? '';

        // Game бүрийн анализ хийх — serve хэн хийсэн, эхний оноог хэн авсан, 0-30 болсон эсэх
        $gameAnalysis = $this->analyzeGames($pointbypoint);

        // Pattern 1: Дараалсан 2+ game эхний оноогоо алдсан
        $this->checkConsecFirstPointLost($gameAnalysis, $matchId, $player1, $player2, $scoreText);

        // Pattern 2: Serve дээрээ 0-30 болсон (pointbypoint-аас шалгана — алдагдахгүй)
        $this->checkServe030($gameAnalysis, $matchId, $player1, $player2, $scoreText);

        // Pattern 3: Дараалсан 2 serve game 0-30 болсон (⚠️ онцлог alert)
        $this->checkConsecServe030($gameAnalysis, $matchId, $player1, $player2, $scoreText);
    }

    /**
     * Pointbypoint data-г анализ хийж, game бүрийн мэдээллийг гаргах.
     *
     * ЧУХАЛ: API-Tennis score format нь ҮРГЭЛЖ "First Player - Second Player".
     * Serve хийж буй тоглогчийг player_served field-ээс мэдэх бөгөөд
     * score-г зөв тайлбарлахын тулд хэн serve хийж буйг харгалзана.
     */
    private function analyzeGames(array $pointbypoint): array
    {
        $games = [];

        foreach ($pointbypoint as $i => $game) {
            $served = $game['player_served'] ?? '';
            $points = $game['points'] ?? [];

            if (empty($served) || empty($points)) {
                $games[] = [
                    'index'             => $i,
                    'served'            => $served,
                    'server_lost_first' => false,
                    'reached_0_30'      => false,
                ];
                continue;
            }

            // Эхний point шалгах — server эхний оноогоо алдсан эсэх
            $firstPointScore = $points[0]['score'] ?? '';
            $serverLostFirst = $this->didServerLoseFirstPoint($firstPointScore, $served);

            // 0-30 шалгах — 2-р point-ийн дараа server 0, returner 30 болсон эсэх
            $reached030 = false;
            if (count($points) >= 2) {
                $secondPointScore = $points[1]['score'] ?? '';
                $reached030 = $this->isServer030($secondPointScore, $served);
            }

            $games[] = [
                'index'             => $i,
                'served'            => $served,
                'server_lost_first' => $serverLostFirst,
                'reached_0_30'      => $reached030,
            ];
        }

        return $games;
    }

    /**
     * Pattern 1: CONSEC_FIRST_POINT_LOST
     * Нэг тоглогч дараалсан 2+ serve game-дээ эхний оноогоо алдсан
     */
    private function checkConsecFirstPointLost(
        array $gameAnalysis,
        string $matchId,
        string $player1,
        string $player2,
        string $scoreText
    ): void {
        $streaks = ['First Player' => 0, 'Second Player' => 0];

        foreach ($gameAnalysis as $game) {
            $served = $game['served'];
            if (empty($served)) {
                continue;
            }

            if ($game['server_lost_first']) {
                $streaks[$served]++;
            } else {
                $streaks[$served] = 0;
            }
        }

        foreach ($streaks as $playerKey => $streak) {
            if ($streak >= 2) {
                $playerName = $playerKey === 'First Player' ? $player1 : $player2;
                $ruleKey = 'CONSEC_FIRST_POINT_LOST';
                $alertKey = "{$matchId}_{$playerName}_{$ruleKey}_{$streak}";

                if ($this->isDuplicate($matchId, $playerName, $ruleKey, $alertKey)) {
                    continue;
                }

                $message = "🔴 PATTERN: Эхний оноо алдсан\n"
                    . "Match: {$player1} vs {$player2}\n"
                    . "Тоглогч: {$playerName}\n"
                    . "Дараалсан {$streak} serve game эхний оноогоо алдлаа\n"
                    . "Score: {$scoreText}";

                $this->saveAndSend($matchId, $playerName, $ruleKey, $message, $alertKey);
            }
        }
    }

    /**
     * Pattern 2: SERVE_0_30
     * Тоглогч өөрийн serve дээрээ 0-30 болсон (pointbypoint-аас шалгана)
     */
    private function checkServe030(
        array $gameAnalysis,
        string $matchId,
        string $player1,
        string $player2,
        string $scoreText
    ): void {
        foreach ($gameAnalysis as $game) {
            if (!$game['reached_0_30']) {
                continue;
            }

            $served = $game['served'];
            $playerName = $served === 'First Player' ? $player1 : $player2;
            $ruleKey = 'SERVE_0_30';
            $gameIdx = $game['index'];
            $alertKey = "{$matchId}_{$playerName}_{$ruleKey}_g{$gameIdx}";

            if ($this->isDuplicate($matchId, $playerName, $ruleKey, $alertKey)) {
                continue;
            }

            $message = "🟡 SERVE 0-30\n"
                . "Match: {$player1} vs {$player2}\n"
                . "Тоглогч: {$playerName}\n"
                . "Өөрийн serve дээрээ 0-30 болсон\n"
                . "Score: {$scoreText}";

            $this->saveAndSend($matchId, $playerName, $ruleKey, $message, $alertKey);
        }
    }

    /**
     * Pattern 3: CONSEC_SERVE_0_30
     * Тоглогч дараалсан 2 serve game-аа 0-30-аар эхэлсэн (⚠️ ОНЦЛОГ)
     */
    private function checkConsecServe030(
        array $gameAnalysis,
        string $matchId,
        string $player1,
        string $player2,
        string $scoreText
    ): void {
        $streaks = ['First Player' => 0, 'Second Player' => 0];

        foreach ($gameAnalysis as $game) {
            $served = $game['served'];
            if (empty($served)) {
                continue;
            }

            if ($game['reached_0_30']) {
                $streaks[$served]++;
            } else {
                $streaks[$served] = 0;
            }
        }

        foreach ($streaks as $playerKey => $streak) {
            if ($streak >= 2) {
                $playerName = $playerKey === 'First Player' ? $player1 : $player2;
                $ruleKey = 'CONSEC_SERVE_0_30';
                $alertKey = "{$matchId}_{$playerName}_{$ruleKey}_{$streak}";

                if ($this->isDuplicate($matchId, $playerName, $ruleKey, $alertKey)) {
                    continue;
                }

                $message = "🔥🔥🔥 ДАВХАР PATTERN 🔥🔥🔥\n"
                    . "━━━━━━━━━━━━━━━━━━━━\n"
                    . "⚠️ Дараалсан {$streak} serve game 0-30!\n"
                    . "━━━━━━━━━━━━━━━━━━━━\n"
                    . "Match: {$player1} vs {$player2}\n"
                    . "Тоглогч: {$playerName}\n"
                    . "Score: {$scoreText}\n"
                    . "━━━━━━━━━━━━━━━━━━━━";

                $this->saveAndSend($matchId, $playerName, $ruleKey, $message, $alertKey);
            }
        }
    }

    // ===== Helper methods =====

    /**
     * Server эхний оноогоо алдсан эсэх.
     *
     * Score format: "First Player pts - Second Player pts" (ҮРГЭЛЖ)
     * Хэрэв First Player serve хийж байвал: "0 - 15" → server 0, returner 15 → АЛДСАН
     * Хэрэв Second Player serve хийж байвал: "15 - 0" → server(2nd) 0тэй тэнцэх нь 1st=15 → АЛДСАН
     */
    private function didServerLoseFirstPoint(string $score, string $served): bool
    {
        $parts = array_map('trim', explode('-', $score));
        if (count($parts) !== 2) {
            return false;
        }

        $firstPlayerPts  = (int) $parts[0];
        $secondPlayerPts = (int) $parts[1];

        if ($served === 'First Player') {
            // First Player serve хийж байна → First Player 0, Second Player 15 = алдсан
            return $firstPlayerPts === 0 && $secondPlayerPts === 15;
        } elseif ($served === 'Second Player') {
            // Second Player serve хийж байна → First Player 15, Second Player 0 = алдсан
            return $firstPlayerPts === 15 && $secondPlayerPts === 0;
        }

        return false;
    }

    /**
     * Server 0-30 болсон эсэх (2-р point-ийн дараа).
     *
     * Score format: "First Player pts - Second Player pts" (ҮРГЭЛЖ)
     */
    private function isServer030(string $score, string $served): bool
    {
        $parts = array_map('trim', explode('-', $score));
        if (count($parts) !== 2) {
            return false;
        }

        $firstPlayerPts  = (int) $parts[0];
        $secondPlayerPts = (int) $parts[1];

        if ($served === 'First Player') {
            // First Player serve → server 0-30 = First:0, Second:30
            return $firstPlayerPts === 0 && $secondPlayerPts === 30;
        } elseif ($served === 'Second Player') {
            // Second Player serve → server 0-30 = First:30, Second:0
            return $firstPlayerPts === 30 && $secondPlayerPts === 0;
        }

        return false;
    }

    /**
     * Давхардсан alert эсэх шалгах.
     */
    private function isDuplicate(string $matchId, string $player, string $ruleKey, string $alertKey): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM alerts
                WHERE match_id = :match
                AND player_name = :player
                AND rule_key = :rule
                AND score_text = :key
            ");
            $stmt->execute([
                ':match'  => $matchId,
                ':player' => $player,
                ':rule'   => $ruleKey,
                ':key'    => $alertKey,
            ]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\PDOException $e) {
            error_log('RuleEngine: duplicate check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Alert хадгалж, Telegram руу илгээх.
     */
    private function saveAndSend(
        string $matchId,
        string $player,
        string $ruleKey,
        string $message,
        string $alertKey
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT OR IGNORE INTO alerts
                (match_id, player_name, rule_key, message, score_text, created_at)
                VALUES (:match, :player, :rule, :msg, :key, :time)
            ");
            $stmt->execute([
                ':match'  => $matchId,
                ':player' => $player,
                ':rule'   => $ruleKey,
                ':msg'    => $message,
                ':key'    => $alertKey,
                ':time'   => date('c'),
            ]);
        } catch (\PDOException $e) {
            error_log('RuleEngine: failed to insert alert: ' . $e->getMessage());
        }

        $this->telegram->send($message);
    }
}
