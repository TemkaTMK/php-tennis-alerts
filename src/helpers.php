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

        // Тоглолт бүрийн game score-г хадгалах (pointbypoint байхгүй үед ашиглана)
        $pdo->exec("CREATE TABLE IF NOT EXISTS match_game_state (
            match_id TEXT PRIMARY KEY,
            serve_key TEXT,
            game_result TEXT,
            game_score TEXT,
            min_server_pts INTEGER DEFAULT 99,
            max_returner_pts INTEGER DEFAULT 0,
            updated_at TEXT
        )");

        // Seed rules
        $rules = [
            [
                'name' => 'Дараалсан 2+ game эхний оноо алдсан',
                'key_name' => 'CONSEC_FIRST_POINT_LOST',
                'config' => json_encode(['consecutive' => 2]),
            ],
            [
                'name' => 'Serve дээрээ 0-30 болсон',
                'key_name' => 'SERVE_0_30',
                'config' => json_encode([]),
            ],
            [
                'name' => 'Дараалсан 2 serve game 0-30 (⚠️ ОНЦЛОГ)',
                'key_name' => 'CONSEC_SERVE_0_30',
                'config' => json_encode(['consecutive' => 2]),
            ],
        ];

        $stmt = $pdo->prepare("INSERT OR IGNORE INTO rules(name, key_name, enabled, config_json, created_at)
            VALUES(:name, :key, 1, :config, :time)");

        foreach ($rules as $rule) {
            $stmt->execute([
                ':name'   => $rule['name'],
                ':key'    => $rule['key_name'],
                ':config' => $rule['config'],
                ':time'   => date('c'),
            ]);
        }

        $initialized = true;
    }

    return $pdo;
}
