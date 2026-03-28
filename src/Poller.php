<?php

declare(strict_types=1);

namespace App;

class Poller
{
    public function __construct(
        private FeedAdapter $feed,
        private RuleEngine $engine
    ) {}

    public function run(): void
    {
        $matches = $this->feed->getLiveMatches();

        echo "[Poller] Matches: " . count($matches) . PHP_EOL;

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $gr = $match['game_result'] ?? '';
            $sv = $match['serve_key'] ?? '';
            $p1 = $match['player1'] ?? '';
            $p2 = $match['player2'] ?? '';
            if (!empty($gr) && $gr !== '0 - 0') {
                echo "  [{$p1} vs {$p2}] serve={$sv} game_result={$gr}" . PHP_EOL;
            }

            $this->engine->process($match);
        }
    }
}
