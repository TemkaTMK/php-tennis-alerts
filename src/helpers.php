<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    static $initialized = false;

    if ($pdo !== null) {
        return $pdo;
    }

    $databasePath = dirname(__DIR__) . '/storage/app.db';

    if (!is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0775, true);
    }

    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!$initialized) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            key_name TEXT UNIQUE,
            enabled INTEGER DEFAULT 1,
            config_json TEXT,
            created_at TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            match_id TEXT,
            player_name TEXT,
            rule_key TEXT,
            message TEXT,
            score_text TEXT,
            created_at TEXT,
            UNIQUE(match_id, player_name, rule_key, score_text)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS service_flags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            match_id TEXT,
            player_name TEXT,
            game_index INTEGER,
            start_0_30 INTEGER,
            created_at TEXT,
            UNIQUE(match_id, player_name, game_index)
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_service_flags_match_player
            ON service_flags(match_id, player_name)");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_match_player
            ON alerts(match_id, player_name, rule_key)");

        // Seed default rule
        $check = $pdo->query("SELECT COUNT(*) FROM rules WHERE key_name='CONSEC_SERVICE_START_0_30'")
            ->fetchColumn();

        if ((int)$check === 0) {
            $config = json_encode(['consecutive' => 2]);
            $stmt = $pdo->prepare("INSERT INTO rules(name, key_name, enabled, config_json, created_at)
                VALUES('2 consecutive service games start 0-30', 'CONSEC_SERVICE_START_0_30', 1, :config, :time)");
            $stmt->execute([':config' => $config, ':time' => date('c')]);
        }

        $initialized = true;
    }

    return $pdo;
}
