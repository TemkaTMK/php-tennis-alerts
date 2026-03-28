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

        $matchId = $match['match_id'] ?? '';
        $player1 = $match['player1'] ?? '';
        $player2 = $match['player2'] ?? '';
        $scoreText = $match['score_text'] ?? '';

        // Pattern 1: Дараалсан 2+ game эхний оноогоо алдсан
        $this->checkConsecFirstPointLost($pointbypoint, $matchId, $player1, $player2, $scoreText);

        // Pattern 2: Serve дээрээ 0-30 болсон (одоогийн game)
        $this->checkServe030($match, $matchId, $player1, $player2, $scoreText);

        // Pattern 3: Дараалсан 2 serve game 0-30 болсон (⚠️ онцлог alert)
        $this->checkConsecServe030($pointbypoint, $match, $matchId, $player1, $player2, $scoreText);
    }

    /**
     * Pattern 1: CONSEC_FIRST_POINT_LOST
     * Нэг тоглогч дараалсан 2+ game-дээ эхний оноогоо алдсан
     */
    private function checkConsecFirstPointLost(
        array $pointbypoint,
        string $matchId,
        string $player1,
        string $player2,
        string $scoreText
    ): void {
        if (count($pointbypoint) < 2) {
            return;
        }

        // Тоглогч бүрийн дараалсан эхний оноо алдсан тоог тоолох
        // "First Player" болон "Second Player" гэж ялгана
        $streaks = ['First Player' => 0, 'Second Player' => 0];

        foreach ($pointbypoint as $game) {
            $served = $game['player_served'] ?? '';
            $points = $game['points'] ?? [];
            if (empty($points) || empty($served)) {
                // Мэдээлэл дутуу бол streak тасална
                $streaks['First Player'] = 0;
                $streaks['Second Player'] = 0;
                continue;
            }

            $firstPointScore = $points[0]['score'] ?? '';
            // Serve хийж буй тоглогч эхний оноогоо алдсан эсэх
            // Score format: "0 - 15" → server 0, returner 15 → server алдсан
            // Score format: "15 - 0" → server 15, returner 0 → server авсан
            $lostFirstPoint = $this->didServerLoseFirstPoint($firstPointScore);

            if ($lostFirstPoint) {
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
                    . "Дараалсан {$streak} game эхний оноогоо алдлаа\n"
                    . "Score: {$scoreText}";

                $this->saveAndSend($matchId, $playerName, $ruleKey, $message, $alertKey);
            }
        }
    }

    /**
     * Pattern 2: SERVE_0_30
     * Тоглогч өөрийн serve дээрээ 0-30 болсон (одоогийн game)
     */
    private function checkServe030(
        array $match,
        string $matchId,
        string $player1,
        string $player2,
        string $scoreText
    ): void {
        $serverPts = $match['point_score']['server'] ?? -1;
        $returnPts = $match['point_score']['returner'] ?? -1;
        $server = $match['server'] ?? '';

        // 0-30 = server 0, returner 30 гэсэн утгатай
        // API score format: "0 - 30" → parsed int: server=0, returner=30
        if ($serverPts !== 0 || $returnPts !== 30) {
            return;
        }

        if (empty($server)) {
            return;
        }

        $ruleKey = 'SERVE_0_30';
        $gameIndex = $match['game_index'] ?? 0;
        $alertKey = "{$matchId}_{$server}_{$ruleKey}_g{$gameIndex}";

        if ($this->isDuplicate($matchId, $server, $ruleKey, $alertKey)) {
            return;
        }

        $message = "🟡 SERVE 0-30\n"
            . "Match: {$player1} vs {$player2}\n"
            . "Тоглогч: {$server}\n"
            . "Өөрийн serve дээрээ 0-30 болсон\n"
            . "Score: {$scoreText}";

        $this->saveAndSend($matchId, $server, $ruleKey, $message, $alertKey);
    }

    /**
     * Pattern 3: CONSEC_SERVE_0_30
     * Тоглогч дараалсан 2 serve game-аа 0-30-аар эхэлсэн (⚠️ ОНЦЛОГ)
     */
    private function checkConsecServe030(
        array $pointbypoint,
        array $match,
        string $matchId,
        string $player1,
        string $player2,
        string $scoreText
    ): void {
        if (count($pointbypoint) < 3) {
            return;
        }

        // Тоглогч бүрийн serve game дээрх 0-30 streak тоолох
        $streaks = ['First Player' => 0, 'Second Player' => 0];

        foreach ($pointbypoint as $game) {
            $served = $game['player_served'] ?? '';
            $points = $game['points'] ?? [];

            if (empty($served)) {
                continue;
            }

            // Зөвхөн serve хийсэн game-ийг шалгана
            if (count($points) >= 2) {
                $secondPointScore = $points[1]['score'] ?? '';
                // 2-р point-ийн дараа 0-30 болсон эсэх
                if ($this->isScore030($secondPointScore)) {
                    $streaks[$served]++;
                } else {
                    $streaks[$served] = 0;
                }
            }
            // Бусад тоглогчийн serve game-д streak хадгалагдана (тасрахгүй)
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
     * Score format: "0 - 15" → server алдсан, "15 - 0" → server авсан
     */
    private function didServerLoseFirstPoint(string $score): bool
    {
        $parts = array_map('trim', explode('-', $score));
        if (count($parts) !== 2) {
            return false;
        }
        return (int) $parts[0] === 0 && (int) $parts[1] === 15;
    }

    /**
     * Score 0-30 эсэх шалгах.
     * Score format: "0 - 30"
     */
    private function isScore030(string $score): bool
    {
        $parts = array_map('trim', explode('-', $score));
        if (count($parts) !== 2) {
            return false;
        }
        return (int) $parts[0] === 0 && (int) $parts[1] === 30;
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
