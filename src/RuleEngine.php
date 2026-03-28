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
        $matchId   = $match['match_id'] ?? '';
        $player1   = $match['player1'] ?? '';
        $player2   = $match['player2'] ?? '';
        $scoreText = $match['score_text'] ?? '';
        $serveKey  = $match['serve_key'] ?? '';
        $server    = $match['server'] ?? '';

        if (empty($matchId) || empty($server)) {
            return;
        }

        $pointbypoint = $match['pointbypoint'] ?? [];
        $gameResult   = $match['game_result'] ?? '';
        $gameIndex    = $match['game_index'] ?? 0;

        // Pattern 2: Serve дээрээ эхний 2 оноогоо алдсан (0-30)
        // event_game_result-аас ШУУД шалгана — хоцрохгүй
        $this->checkServe030Live($gameResult, $serveKey, $server, $matchId, $player1, $player2, $scoreText, $gameIndex);

        // Pattern 1 & 3: pointbypoint түүхээс шалгана
        if (!empty($pointbypoint)) {
            $gameAnalysis = $this->analyzeGames($pointbypoint);

            // Pattern 1: Дараалсан 2+ game эхний оноогоо алдсан
            $this->checkConsecFirstPointLost($gameAnalysis, $matchId, $player1, $player2, $scoreText);

            // Pattern 3: Дараалсан 2 serve game 0-30 болсон (⚠️ онцлог alert)
            $this->checkConsecServe030($gameAnalysis, $matchId, $player1, $player2, $scoreText);
        }
    }

    /**
     * Pattern 2: SERVE_0_30 — LIVE шалгалт
     * event_game_result-аас шууд шалгана (real-time, хоцрохгүй)
     *
     * game_result format: "First Player pts - Second Player pts" ҮРГЭЛЖ
     * Server 0-30 гэдэг нь serve хийж буй тоглогчийн оноо 0, нөгөөгийнх 30
     */
    private function checkServe030Live(
        string $gameResult,
        string $serveKey,
        string $server,
        string $matchId,
        string $player1,
        string $player2,
        string $scoreText,
        int $gameIndex
    ): void {
        if (empty($gameResult) || empty($serveKey)) {
            return;
        }

        $parts = array_map('trim', explode('-', $gameResult));
        if (count($parts) !== 2) {
            return;
        }

        $firstPts  = (int) $parts[0];
        $secondPts = (int) $parts[1];

        // Server 0-30 эсэх шалгах
        $isServe030 = false;
        if ($serveKey === 'First Player') {
            // First Player serve → server 0, returner 30 = first:0, second:30
            $isServe030 = ($firstPts === 0 && $secondPts === 30);
        } elseif ($serveKey === 'Second Player') {
            // Second Player serve → server 0, returner 30 = first:30, second:0
            $isServe030 = ($firstPts === 30 && $secondPts === 0);
        }

        if (!$isServe030) {
            return;
        }

        $ruleKey = 'SERVE_0_30';
        $alertKey = "{$matchId}_{$server}_{$ruleKey}_g{$gameIndex}";

        if ($this->isDuplicate($matchId, $server, $ruleKey, $alertKey)) {
            return;
        }

        $message = "🟡 SERVE 0-30\n"
            . "Match: {$player1} vs {$player2}\n"
            . "Тоглогч: {$server}\n"
            . "Serve дээрээ эхний 2 оноогоо алдлаа\n"
            . "Score: {$scoreText}";

        $this->saveAndSend($matchId, $server, $ruleKey, $message, $alertKey);
    }

    /**
     * Pointbypoint data-г анализ хийх
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

            $firstPointScore = $points[0]['score'] ?? '';
            $serverLostFirst = $this->didServerLoseFirstPoint($firstPointScore, $served);

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
     * Pattern 3: CONSEC_SERVE_0_30
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

    private function didServerLoseFirstPoint(string $score, string $served): bool
    {
        $parts = array_map('trim', explode('-', $score));
        if (count($parts) !== 2) {
            return false;
        }
        $firstPts  = (int) $parts[0];
        $secondPts = (int) $parts[1];

        if ($served === 'First Player') {
            return $firstPts === 0 && $secondPts === 15;
        } elseif ($served === 'Second Player') {
            return $firstPts === 15 && $secondPts === 0;
        }
        return false;
    }

    private function isServer030(string $score, string $served): bool
    {
        $parts = array_map('trim', explode('-', $score));
        if (count($parts) !== 2) {
            return false;
        }
        $firstPts  = (int) $parts[0];
        $secondPts = (int) $parts[1];

        if ($served === 'First Player') {
            return $firstPts === 0 && $secondPts === 30;
        } elseif ($served === 'Second Player') {
            return $firstPts === 30 && $secondPts === 0;
        }
        return false;
    }

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
