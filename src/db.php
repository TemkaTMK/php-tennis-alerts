<?php

declare(strict_types=1);

function db(): PDO {

    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $databasePath = dirname(__DIR__) . '/storage/app.db';

    if (!is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0777, true);
    }

    $pdo = new PDO('sqlite:' . $databasePath);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /*
    RULES TABLE
    */

    $pdo->exec("CREATE TABLE IF NOT EXISTS rules (

        id INTEGER PRIMARY KEY AUTOINCREMENT,

        name TEXT,
        key_name TEXT UNIQUE,

        enabled INTEGER DEFAULT 1,

        config_json TEXT,

        created_at TEXT

    )");

    /*
    ALERT LOG
    */

    $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (

        id INTEGER PRIMARY KEY AUTOINCREMENT,

        match_id TEXT,

        player_name TEXT,

        rule_key TEXT,

        message TEXT,

        score_text TEXT,

        created_at TEXT

    )");

    /*
    SERVICE FLAGS
    */

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_flags (

        id INTEGER PRIMARY KEY AUTOINCREMENT,

        match_id TEXT,

        player_name TEXT,

        game_index INTEGER,

        start_0_30 INTEGER,

        created_at TEXT

    )");

    seedRule($pdo);

    return $pdo;
}


/*
DEFAULT RULE
*/

function seedRule($pdo){

    $check = $pdo->query("SELECT COUNT(*) FROM rules WHERE key_name='CONSEC_SERVICE_START_0_30'")
        ->fetchColumn();

    if($check > 0){
        return;
    }

    $config = json_encode([
        "consecutive" => 2
    ]);

    $stmt = $pdo->prepare("INSERT INTO rules(name,key_name,enabled,config_json,created_at)

        VALUES(

        '2 consecutive service games start 0-30',
        'CONSEC_SERVICE_START_0_30',
        1,
        :config,
        :time
        )

    ");

    $stmt->execute([
        ":config" => $config,
        ":time" => date('c')
    ]);

}
