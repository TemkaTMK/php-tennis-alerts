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

        // Pattern 2: Serve дээрээ эхний 2 оноогоо алдсан
        // event_game_result-аас ШУУД шалгана — хоцрохгүй
        $this->checkServe030Live($gameResult, $serveKey, $server, $matchId, $player1, $player2, $scoreText, $gameIndex);

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
     *
     * Server эхний 2 оноогоо алдсан эсэхийг шалгана.
     * 12 секундийн завсарт score өөрчлөгдөж болох тул
     * 0-30, 0-40, 15-40 зэрэг server-ийн эхний 2 оноо алдагдсан
     * бүх тохиолдлыг шалгана.
     *
     * game_result format: "First Player pts - Second Player pts" ҮРГЭЛЖ
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

        // Тоон утга руу хөрвүүлэх (A, AD гэх мэтийг тооцохгүй)
        $firstPts  = $this->parsePoints($parts[0]);
        $secondPts = $this->parsePoints($parts[1]);

        if ($firstPts === -1 || $secondPts === -1) {
            return;
        }

        // Server-ийн оноо, returner-ийн оноог тодорхойлох
        $serverPts   = ($serveKey === 'First Player') ? $firstPts : $secondPts;
        $returnerPts = ($serveKey === 'First Player') ? $secondPts : $firstPts;

        // Server эхний 2 оноогоо алдсан эсэх:
        // Server-ийн оноо 0 эсвэл 15, Returner-ийн оноо 30+
        // Жишээ: 0-30, 0-40, 15-40
        $serverLostFirst2 = ($serverPts <= 15 && $returnerPts >= 30 && $returnerPts > $serverPts);

        // Debug log
        echo "  [SERVE030] {$server}: gameResult={$gameResult} serveKey={$serveKey} serverPts={$serverPts} returnerPts={$returnerPts} lost2=" . ($serverLostFirst2 ? 'YES' : 'no') . PHP_EOL;

        if (!$serverLostFirst2) {
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
            . "Serve дээрээ эхний 2 оноогоо алдлаа ({$gameResult})\n"
            . "Score: {$scoreText}";

        $this->saveAndSend($matchId, $server, $ruleKey, $message, $alertKey);
    }

    /**
     * Point string-г тоо руу хөрвүүлэх.
     * "0" → 0, "15" → 15, "30" → 30, "40" → 40, "A" → 45, бусад → -1
     */
    private function parsePoints(string $pts): int
    {
        $pts = trim($pts);
        if ($pts === 'A' || $pts === 'AD') {
            return 45;
        }
        if (is_numeric($pts)) {
            return (int) $pts;
        }
        return -1;
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
