<?php

declare(strict_types=1);

class RuleEngine {

    public static function process($match){

        $pdo = db();

        $rules = $pdo->query("SELECT * FROM rules WHERE enabled=1")
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach($rules as $rule){

            if($rule["key_name"] == "CONSEC_SERVICE_START_0_30"){

                self::check030($match,$rule);

            }

        }

    }


    private static function check030($match,$rule){

        $pdo = db();

        $matchId = $match["match_id"] ?? "";
        $server  = $match["server"] ?? "";
        $game    = $match["game_index"] ?? 0;

        $serverPts = $match["point_score"]["server"] ?? -1;
        $returnPts = $match["point_score"]["returner"] ?? -1;

        if($serverPts != 0 || $returnPts != 2){
            return;
        }

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO service_flags
            (match_id,player_name,game_index,start_0_30,created_at)
            VALUES
            (:match,:player,:game,1,:time)
        ");

        $stmt->execute([
            ":match"=>$matchId,
            ":player"=>$server,
            ":game"=>$game,
            ":time"=>date("c")
        ]);

        $check = $pdo->prepare("
            SELECT * FROM service_flags
            WHERE match_id=:match
            AND player_name=:player
            ORDER BY game_index DESC
            LIMIT 2
        ");

        $check->execute([
            ":match"=>$matchId,
            ":player"=>$server
        ]);

        $rows = $check->fetchAll(PDO::FETCH_ASSOC);

        if(count($rows) < 2){
            return;
        }

        $message =
        "🎾 PATTERN ALERT\n".
        "Match: ".$match["player1"]." vs ".$match["player2"]."\n".
        "Rule: 2 service games start 0-30\n".
        "Player: ".$server."\n".
        "Score: ".$match["score_text"];

        $log = $pdo->prepare("
            INSERT INTO alerts
            (match_id,player_name,rule_key,message,score_text,created_at)
            VALUES
            (:match,:player,'CONSEC_SERVICE_START_0_30',:msg,:score,:time)
        ");

        $log->execute([
            ":match"=>$matchId,
            ":player"=>$server,
            ":msg"=>$message,
            ":score"=>$match["score_text"],
            ":time"=>date("c")
        ]);

        Telegram::send($message);

    }

}
