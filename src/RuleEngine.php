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
        try {
            $rules = $this->pdo->query("SELECT * FROM rules WHERE enabled=1")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('RuleEngine: failed to load rules: ' . $e->getMessage());
            return;
        }

        foreach ($rules as $rule) {
            if ($rule['key_name'] === 'CONSEC_SERVICE_START_0_30') {
                $this->checkConsecutiveServiceStart030($match, $rule);
            }
        }
    }

    private function checkConsecutiveServiceStart030(array $match, array $rule): void
    {
        $matchId = $match['match_id'] ?? '';
        $server  = $match['server'] ?? '';
        $game    = $match['game_index'] ?? 0;

        $serverPts  = $match['point_score']['server'] ?? -1;
        $returnPts  = $match['point_score']['returner'] ?? -1;

        if ($serverPts != 0 || $returnPts != 2) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT OR IGNORE INTO service_flags
                (match_id, player_name, game_index, start_0_30, created_at)
                VALUES (:match, :player, :game, 1, :time)
            ");
            $stmt->execute([
                ':match'  => $matchId,
                ':player' => $server,
                ':game'   => $game,
                ':time'   => date('c'),
            ]);
        } catch (\PDOException $e) {
            error_log('RuleEngine: failed to insert service_flag: ' . $e->getMessage());
            return;
        }

        try {
            $check = $this->pdo->prepare("
                SELECT * FROM service_flags
                WHERE match_id = :match
                AND player_name = :player
                ORDER BY game_index DESC
                LIMIT 2
            ");
            $check->execute([
                ':match'  => $matchId,
                ':player' => $server,
            ]);
            $rows = $check->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('RuleEngine: failed to check service_flags: ' . $e->getMessage());
            return;
        }

        if (count($rows) < 2) {
            return;
        }

        // Duplicate alert шалгалт
        $scoreText = $match['score_text'] ?? '';
        try {
            $dupCheck = $this->pdo->prepare("
                SELECT COUNT(*) FROM alerts
                WHERE match_id = :match
                AND player_name = :player
                AND rule_key = 'CONSEC_SERVICE_START_0_30'
                AND score_text = :score
            ");
            $dupCheck->execute([
                ':match'  => $matchId,
                ':player' => $server,
                ':score'  => $scoreText,
            ]);
            if ((int) $dupCheck->fetchColumn() > 0) {
                return; // Аль хэдийн илгээсэн
            }
        } catch (\PDOException $e) {
            error_log('RuleEngine: duplicate check failed: ' . $e->getMessage());
        }

        $message = "🎾 PATTERN ALERT\n"
            . "Match: " . ($match['player1'] ?? '') . " vs " . ($match['player2'] ?? '') . "\n"
            . "Rule: 2 service games start 0-30\n"
            . "Player: {$server}\n"
            . "Score: {$scoreText}";

        try {
            $log = $this->pdo->prepare("
                INSERT OR IGNORE INTO alerts
                (match_id, player_name, rule_key, message, score_text, created_at)
                VALUES (:match, :player, 'CONSEC_SERVICE_START_0_30', :msg, :score, :time)
            ");
            $log->execute([
                ':match'  => $matchId,
                ':player' => $server,
                ':msg'    => $message,
                ':score'  => $scoreText,
                ':time'   => date('c'),
            ]);
        } catch (\PDOException $e) {
            error_log('RuleEngine: failed to insert alert: ' . $e->getMessage());
        }

        $this->telegram->send($message);
    }
}
