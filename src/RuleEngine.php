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

        // Тэмцээн + odds мэдээлэл
        $tournament  = $match['tournament'] ?? '';

        // === ITF M100 / W100-аас доош тэмцээнүүдийг skip хийнэ ===
        if ($this->isLowTierItfTournament($tournament)) {
            return;
        }

        $p1Odds      = $match['player1_odds'] ?? null;
        $p2Odds      = $match['player2_odds'] ?? null;

        // Тоглогч бүрийн odds тэмдэг тооцоолох
        $p1Tag = $this->buildOddsTag($p1Odds, $p2Odds);
        $p2Tag = $this->buildOddsTag($p2Odds, $p1Odds);

        // Match context — бүх pattern-д дамжуулна
        $ctx = [
            'tournament' => $tournament,
            'player1'    => $player1,
            'player2'    => $player2,
            'p1Tag'      => $p1Tag,
            'p2Tag'      => $p2Tag,
            'p1Odds'     => $p1Odds,
            'p2Odds'     => $p2Odds,
            'scoreText'  => $scoreText,
        ];

        // === Pattern 1: ИДЭВХГҮЙ болгосон ===
        // $this->checkFirstPointLostLive($gameResult, $serveKey, $server, $matchId, $ctx, $gameIndex);

        // === Pattern 2: Serve дээрээ эхний 2 оноогоо алдсан ===
        // Арга 1: event_game_result-аас ШУУД шалгана (real-time)
        $this->checkServe030Live($gameResult, $serveKey, $server, $matchId, $ctx, $gameIndex);

        // Арга 2: Өмнөх game-ийн score-г санаж шалгах (pointbypoint байхгүй үед)
        $gameScore = $match['score_text'] ?? '';
        $this->checkServe030ByStateChange($matchId, $serveKey, $server, $gameResult, $gameScore, $ctx, $gameIndex);

        if (!empty($pointbypoint)) {
            $gameAnalysis = $this->analyzeGames($pointbypoint);

            // Pattern 2 Арга 3: pointbypoint-аас шалгана (game дууссаны дараа ч барьдаг)
            $this->checkServe030FromHistory($gameAnalysis, $matchId, $ctx);

            // Pattern 1 backup: ИДЭВХГҮЙ болгосон
            // $this->checkFirstPointLostFromHistory($gameAnalysis, $matchId, $ctx);
        }

        // === Pattern 1: ИДЭВХГҮЙ болгосон ===
        // $this->checkConsecFirstPointLostFromDB($matchId, $player1, $ctx);
        // $this->checkConsecFirstPointLostFromDB($matchId, $player2, $ctx);

        // === Pattern 3: ИДЭВХГҮЙ болгосон ===
        // $this->checkConsecServe030Live($matchId, $player1, $ctx);
        // $this->checkConsecServe030Live($matchId, $player2, $ctx);
    }

    private function isLowTierItfTournament(string $name): bool
    {
        $n = strtoupper(trim($name));

        if (!str_contains($n, 'ITF')) {
            return false;
        }

        if (preg_match('/\b([MW])\s?(\d{2,3})\b/', $n, $m)) {
            $level = (int) $m[2];
            return $level < 100;
        }

        return false;
    }

    /**
     * Тоглогчийн odds-р overrated/underrated тэмдэг тодорхойлох.
     * Бага odds = фаворит (📈), их odds = андердог (📉).
     */
    private function buildOddsTag(?float $myOdds, ?float $opponentOdds): string
    {
        if ($myOdds === null || $opponentOdds === null) {
            return '';
        }
        if ($myOdds < $opponentOdds) {
            return '📈';
        }
        if ($myOdds > $opponentOdds) {
            return '📉';
        }
        return '';
    }

    /**
     * Server-ийн odds тэмдэг + odds утга авах
     */
    private function getServerTag(string $server, array $ctx): string
    {
        $tag = ($server === $ctx['player1']) ? $ctx['p1Tag'] : $ctx['p2Tag'];
        $odds = ($server === $ctx['player1']) ? $ctx['p1Odds'] : $ctx['p2Odds'];
        if (!empty($tag) && $odds !== null) {
            return " {$tag} ({$odds})";
        }
        return '';
    }

    /**
     * Мессежний header: тэмцээний нэр
     */
    private function buildMatchHeader(array $ctx): string
    {
        $header = '';
        if (!empty($ctx['tournament'])) {
            $header .= "🏆 {$ctx['tournament']}\n";
        }
        return $header;
    }

    /**
     * Pattern 1: FIRST_POINT_LOST — LIVE detect
     * ⚠️  ИДЭВХГҮЙ: process()-оос дуудагдахгүй.
     */
    private function checkFirstPointLostLive(
        string $gameResult,
        string $serveKey,
        string $server,
        string $matchId,
        array $ctx,
        int $gameIndex
    ): void {
        if (empty($gameResult) || empty($serveKey)) {
            return;
        }

        $parts = array_map('trim', explode('-', $gameResult));
        if (count($parts) !== 2) {
            return;
        }

        $firstPts  = $this->parsePoints($parts[0]);
        $secondPts = $this->parsePoints($parts[1]);

        if ($firstPts === -1 || $secondPts === -1) {
            return;
        }

        $serverPts   = ($serveKey === 'First Player') ? $firstPts : $secondPts;
        $returnerPts = ($serveKey === 'First Player') ? $secondPts : $firstPts;

        $serverLostFirst = ($serverPts === 0 && $returnerPts >= 15);

        if (!$serverLostFirst) {
            return;
        }

        $ruleKey = 'FIRST_POINT_LOST';
        $alertKey = "{$matchId}_{$server}_{$ruleKey}_g{$gameIndex}";

        if ($this->isDuplicate($matchId, $server, $ruleKey, $alertKey)) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT OR IGNORE INTO alerts
                (match_id, player_name, rule_key, message, score_text, created_at)
                VALUES (:match, :player, :rule, :msg, :key, :time)
            ");
            $stmt->execute([
                ':match'  => $matchId,
                ':player' => $server,
                ':rule'   => $ruleKey,
                ':msg'    => 'first_point_lost_detected',
                ':key'    => $alertKey,
                ':time'   => date('c'),
            ]);
            echo "  [FPL] {$server}: first point lost detected (game {$gameIndex})" . PHP_EOL;
        } catch (\PDOException $e) {
            error_log('RuleEngine: first point lost save failed: ' . $e->getMessage());
        }
    }

    /**
     * Pattern 1 backup: pointbypoint-аас эхний оноо алдсан detect
     * ⚠️  ИДЭВХГҮЙ: process()-оос дуудагдахгүй.
     */
    private function checkFirstPointLostFromHistory(
        array $gameAnalysis,
        string $matchId,
        array $ctx
    ): void {
        foreach ($gameAnalysis as $game) {
            $served = $game['served'];
            if (empty($served) || !$game['server_lost_first']) {
                continue;
            }

            $playerName = ($served === 'First Player') ? $ctx['player1'] : $ctx['player2'];
            $ruleKey = 'FIRST_POINT_LOST';
            $gameIdx = $game['index'];
            $alertKey = "{$matchId}_{$playerName}_{$ruleKey}_g{$gameIdx}";

            if ($this->isDuplicate($matchId, $playerName, $ruleKey, $alertKey)) {
                continue;
            }

            try {
                $stmt = $this->pdo->prepare("
                    INSERT OR IGNORE INTO alerts
                    (match_id, player_name, rule_key, message, score_text, created_at)
                    VALUES (:match, :player, :rule, :msg, :key, :time)
                ");
                $stmt->execute([
                    ':match'  => $matchId,
                    ':player' => $playerName,
                    ':rule'   => $ruleKey,
                    ':msg'    => 'first_point_lost_detected',
                    ':key'    => $alertKey,
                    ':time'   => date('c'),
                ]);
            } catch (\PDOException $e) {
                error_log('RuleEngine: first point lost history save failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Pattern 1: CONSEC_FIRST_POINT_LOST — DB-ээс дараалсан эсэхийг шалгах
     * ⚠️  ИДЭВХГҮЙ: process()-оос дуудагдахгүй.
     */
    private function checkConsecFirstPointLostFromDB(
        string $matchId,
        string $server,
        array $ctx
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                SELECT score_text FROM alerts
                WHERE match_id = :match
                AND player_name = :player
                AND rule_key = 'FIRST_POINT_LOST'
                ORDER BY created_at ASC
            ");
            $stmt->execute([':match' => $matchId, ':player' => $server]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($rows) < 2) {
                return;
            }

            $gameIndices = [];
            foreach ($rows as $alertKey) {
                if (preg_match('/_g(\d+)$/', $alertKey, $m)) {
                    $gameIndices[] = (int) $m[1];
                }
            }

            if (count($gameIndices) < 2) {
                return;
            }

            sort($gameIndices);

            $curStreak = 1;
            for ($i = 1; $i < count($gameIndices); $i++) {
                $gap = $gameIndices[$i] - $gameIndices[$i - 1];
                if ($gap <= 3) {
                    $curStreak++;
                } else {
                    $curStreak = 1;
                }
            }

            $lastGap = $gameIndices[count($gameIndices) - 1] - $gameIndices[count($gameIndices) - 2];
            $isLastConsecutive = ($lastGap <= 3);

            echo "  [CONSEC_FPL] {$server}: indices=" . implode(',', $gameIndices)
                . " streak={$curStreak} lastGap={$lastGap}" . PHP_EOL;

            if (!$isLastConsecutive || $curStreak < 2) {
                return;
            }

            $ruleKey = 'CONSEC_FIRST_POINT_LOST';
            $lastIdx = $gameIndices[count($gameIndices) - 1]
