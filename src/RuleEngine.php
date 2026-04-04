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

        $p1Odds = $match['player1_odds'] ?? null;
        $p2Odds = $match['player2_odds'] ?? null;

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
        $this->checkServe030Live($gameResult, $serveKey, $server, $matchId, $ctx, $gameIndex);

        $gameScore = $match['score_text'] ?? '';
        $this->checkServe030ByStateChange(
            $matchId,
            $serveKey,
            $server,
            $gameResult,
            $gameScore,
            $ctx,
            $gameIndex
        );

        if (!empty($pointbypoint)) {
            $gameAnalysis = $this->analyzeGames($pointbypoint);
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

        return str_contains($n, 'ITF');
    }

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

    private function getServerTag(string $server, array $ctx): string
    {
        $tag = ($server === $ctx['player1']) ? $ctx['p1Tag'] : $ctx['p2Tag'];
        $odds = ($server === $ctx['player1']) ? $ctx['p1Odds'] : $ctx['p2Odds'];

        if (!empty($tag) && $odds !== null) {
            return " {$tag} ({$odds})";
        }

        return '';
    }

    private function buildMatchHeader(array $ctx): string
    {
        $header = '';

        if (!empty($ctx['tournament'])) {
            $header .= "🏆 {$ctx['tournament']}
";
        }

        return $header;
    }

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
        } catch (PDOException $e) {
            error_log('RuleEngine: first point lost save failed: ' . $e->getMessage());
        }
    }

    private function checkFirstPointLostFromHistory(
        array $gameAnalysis,
        string $matchId,
        array $ctx
    ): void {
        foreach ($gameAnalysis as $game) {
            $served = $game['served'] ?? '';
            if (empty($served) || empty($game['server_lost_first'])) {
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
            } catch (PDOException $e) {
                error_log('RuleEngine: first point lost history save failed: ' . $e->getMessage());
            }
        }
    }

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
            $stmt->execute([
                ':match'  => $matchId,
                ':player' => $server,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($rows) < 2) {
                return;
            }

            $gameIndices = [];
            foreach ($rows as $alertKey) {
                if (preg_match('/_g(d+)$/', $alertKey, $m)) {
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

            if (!$isLastConsecutive || $curStreak < 2) {
                return;
            }

            $ruleKey = 'CONSEC_FIRST_POINT_LOST';
            $lastIdx = $gameIndices[count($gameIndices) - 1];
            $alertKey = "{$matchId}_{$server}_{$ruleKey}_g{$lastIdx}_s{$curStreak}";

            if ($this->isDuplicate($matchId, $server, $ruleKey, $alertKey)) {
                return;
            }

            $serverTag = $this->getServerTag($server, $ctx);
            $message = $this->buildMatchHeader($ctx)
                . "🔴 PATTERN: Эхний оноо алдсан
"
                . "Match: {$ctx['player1']} vs {$ctx['player2']}
"
                . "Тоглогч: {$server}{$serverTag}
"
                . "Дараалсан {$curStreak} serve game эхний оноогоо алдлаа
"
                . "Score: {$ctx['scoreText']}";

            $this->saveAndSend($matchId, $server, $ruleKey, $message, $alertKey);
        } catch (PDOException $e) {
            error_log('RuleEngine: consec first point lost check failed: ' . $e->getMessage());
        }
    }

    private function checkServe030Live(
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

        if ($firstPts >= 40 && $secondPts >= 40) {
            return;
        }

        $serverPts   = ($serveKey === 'First Player') ? $firstPts : $secondPts;
        $returnerPts = ($serveKey === 'First Player') ? $secondPts : $firstPts;

        $serverLostFirst2 = ($serverPts === 0 && $returnerPts >= 30);

        if (!$serverLostFirst2) {
            return;
        }

        $ruleKey = 'SERVE_0_30';
        $alertKey = "{$matchId}_{$server}_{$ruleKey}_g{$gameIndex}";

        if ($this->isDuplicate($matchId, $server, $ruleKey, $alertKey)) {
            return;
        }

        $serverTag = $this->getServerTag($server, $ctx);
        $message = $this->buildMatchHeader($ctx)
            . "🟡 SERVE 0-30
"
            . "Match: {$ctx['player1']} vs {$ctx['player2']}
"
            . "Тоглогч: {$server}{$serverTag}
"
            . "Serve дээрээ эхний 2 оноогоо алдлаа ({$gameResult})
"
            . "Score: {$ctx['scoreText']}";

        $this->saveAndSend($matchId, $server, $ruleKey, $message, $alertKey);
    }

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

    private function checkServe030ByStateChange(
        string $matchId,
        string $serveKey,
        string $server,
        string $gameResult,
        string $gameScore,
        array $ctx,
        int $gameIndex
    ): void {
        if (empty($matchId) || empty($gameResult) || empty($serveKey)) {
            return;
        }

        $parts = array_map('trim', explode('-', $gameResult));
        $curServerPts = 99;
        $curReturnerPts = 0;

        if (count($parts) === 2) {
            $fp = $this->parsePoints($parts[0]);
            $sp = $this->parsePoints($parts[1]);

            if ($fp !== -1 && $sp !== -1) {
                $curServerPts   = ($serveKey === 'First Player') ? $fp : $sp;
                $curReturnerPts = ($serveKey === 'First Player') ? $sp : $fp;
            }
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT serve_key, game_result, game_score, min_server_pts, max_returner_pts
                FROM match_game_state WHERE match_id = :id
            ");
            $stmt->execute([':id' => $matchId]);
            $prev = $stmt->fetch(PDO::FETCH_ASSOC);

            $prevGameScore = $prev['game_score'] ?? '';
            $prevServeKey  = $prev['serve_key'] ?? '';

            $isNewGame = $prev && (
                ($gameScore !== $prevGameScore) ||
                ($gameResult === '0 - 0' && ($prev['game_result'] ?? '') !== '0 - 0' && !empty($prev['game_result']))
            );

            if ($isNewGame) {
                $minSrvPts = (int) ($prev['min_server_pts'] ?? 99);
                $maxRetPts = (int) ($prev['max_returner_pts'] ?? 0);

                if ($minSrvPts === 0 && $maxRetPts >= 30 && !empty($prevServeKey)) {
                    $prevServerName = ($prevServeKey === 'First Player') ? $ctx['player1'] : $ctx['player2'];
                    $ruleKey = 'SERVE_0_30';
                    $prevGameIdx = max(0, $gameIndex - 1);
                    $alertKey = "{$matchId}_{$prevServerName}_{$ruleKey}_g{$prevGameIdx}";

                    if (!$this->isDuplicate($matchId, $prevServerName, $ruleKey, $alertKey)) {
                        $prevTag = $this->getServerTag($prevServerName, $ctx);
                        $message = $this->buildMatchHeader($ctx)
                            . "🟡 SERVE 0-30
"
                            . "Match: {$ctx['player1']} vs {$ctx['player2']}
"
                            . "Тоглогч: {$prevServerName}{$prevTag}
"
                            . "Serve дээрээ эхний 2 оноогоо алдлаа
"
                            . "Score: {$ctx['scoreText']}";

                        $this->saveAndSend($matchId, $prevServerName, $ruleKey, $message, $alertKey);
                    }
                }

                $upsert = $this->pdo->prepare("
                    INSERT INTO match_game_state (match_id, serve_key, game_result, game_score, min_server_pts, max_returner_pts, updated_at)
                    VALUES (:id, :sk, :gr, :gs, :minS, :maxR, :t)
                    ON CONFLICT(match_id) DO UPDATE SET
                        serve_key = :sk, game_result = :gr, game_score = :gs,
                        min_server_pts = :minS, max_returner_pts = :maxR, updated_at = :t
                ");
                $upsert->execute([
                    ':id' => $matchId,
                    ':sk' => $serveKey,
                    ':gr' => $gameResult,
                    ':gs' => $gameScore,
                    ':minS' => $curServerPts,
                    ':maxR' => $curReturnerPts,
                    ':t' => date('c'),
                ]);
            } else {
                $prevMin = $prev ? (int) ($prev['min_server_pts'] ?? 99) : 99;
                $prevMax = $prev ? (int) ($prev['max_returner_pts'] ?? 0) : 0;
                $newMin = min($prevMin, $curServerPts);
                $newMax = max($prevMax, $curReturnerPts);

                $upsert = $this->pdo->prepare("
                    INSERT INTO match_game_state (match_id, serve_key, game_result, game_score, min_server_pts, max_returner_pts, updated_at)
                    VALUES (:id, :sk, :gr, :gs, :minS, :maxR, :t)
                    ON CONFLICT(match_id) DO UPDATE SET
                        serve_key = :sk, game_result = :gr, game_score = :gs,
                        min_server_pts = :minS, max_returner_pts = :maxR, updated_at = :t
                ");
                $upsert->execute([
                    ':id' => $matchId,
                    ':sk' => $serveKey,
                    ':gr' => $gameResult,
                    ':gs' => $gameScore,
                    ':minS' => $newMin,
                    ':maxR' => $newMax,
                    ':t' => date('c'),
                ]);
            }
        } catch (PDOException $e) {
            error_log('RuleEngine state check error: ' . $e->getMessage());
        }
    }

    private function checkServe030FromHistory(
        array $gameAnalysis,
        string $matchId,
        array $ctx
    ): void {
        foreach ($gameAnalysis as $game) {
            $served = $game['served'] ?? '';
            if (empty($served) || empty($game['reached_0_30'])) {
                continue;
            }

            $playerName = ($served === 'First Player') ? $ctx['player1'] : $ctx['player2'];
            $ruleKey = 'SERVE_0_30';
            $gameIdx = $game['index'];
            $alertKey = "{$matchId}_{$playerName}_{$ruleKey}_g{$gameIdx}";

            if ($this->isDuplicate($matchId, $playerName, $ruleKey, $alertKey)) {
                continue;
            }

            $playerTag = $this->getServerTag($playerName, $ctx);
            $message = $this->buildMatchHeader($ctx)
                . "🟡 SERVE 0-30
"
                . "Match: {$ctx['player1']} vs {$ctx['player2']}
"
                . "Тоглогч: {$playerName}{$playerTag}
"
                . "Serve дээрээ эхний 2 оноогоо алдлаа (game #{$gameIdx})
"
                . "Score: {$ctx['scoreText']}";

            $this->saveAndSend($matchId, $playerName, $ruleKey, $message, $alertKey);
        }
    }

    private function checkConsecServe030Live(
        string $matchId,
        string $server,
        array $ctx
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                SELECT score_text FROM alerts
                WHERE match_id = :match
                AND player_name = :player
                AND rule_key = 'SERVE_0_30'
                ORDER BY created_at ASC
            ");
            $stmt->execute([
                ':match'  => $matchId,
                ':player' => $server,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($rows) < 2) {
                return;
            }

            $gameIndices = [];
            foreach ($rows as $alertKey) {
                if (preg_match('/_g(d+)$/', $alertKey, $m)) {
                    $gameIndices[] = (int) $m[1];
                }
            }

            if (count($gameIndices) < 2) {
                return;
            }

            sort($gameIndices);

            $maxStreak = 1;
            $curStreak = 1;
            for ($i = 1; $i < count($gameIndices); $i++) {
                $gap = $gameIndices[$i] - $gameIndices[$i - 1];
                if ($gap <= 3) {
                    $curStreak++;
                    $maxStreak = max($maxStreak, $curStreak);
                } else {
                    $curStreak = 1;
                }
            }

            $lastGap = $gameIndices[count($gameIndices) - 1] - $gameIndices[count($gameIndices) - 2];
            $isLastConsecutive = ($lastGap <= 3);

            if (!$isLastConsecutive || $maxStreak < 2) {
                return;
            }

            $ruleKey = 'CONSEC_SERVE_0_30';
            $lastIdx = $gameIndices[count($gameIndices) - 1];
            $alertKey = "{$matchId}_{$server}_{$ruleKey}_g{$lastIdx}_s{$curStreak}";

            if ($this->isDuplicate($matchId, $server, $ruleKey, $alertKey)) {
                return;
            }

            $serverTag = $this->getServerTag($server, $ctx);
            $message = $this->buildMatchHeader($ctx)
                . "🔥🔥🔥 ДАВХАР PATTERN 🔥🔥🔥
"
                . "━━━━━━━━━━━━━━━━━━━━
"
                . "⚠️ Дараалсан {$curStreak} serve game 0-30!
"
                . "━━━━━━━━━━━━━━━━━━━━
"
                . "Match: {$ctx['player1']} vs {$ctx['player2']}
"
                . "Тоглогч: {$server}{$serverTag}
"
                . "Score: {$ctx['scoreText']}
"
                . "━━━━━━━━━━━━━━━━━━━━";

            $this->saveAndSend($matchId, $server, $ruleKey, $message, $alertKey);
        } catch (PDOException $e) {
            error_log('RuleEngine: consec serve 030 check failed: ' . $e->getMessage());
        }
    }

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
        }

        if ($served === 'Second Player') {
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
        }

        if ($served === 'Second Player') {
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
        } catch (PDOException $e) {
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
        } catch (PDOException $e) {
            error_log('RuleEngine: failed to insert alert: ' . $e->getMessage());
        }

        $this->telegram->send($message);
    }
}
