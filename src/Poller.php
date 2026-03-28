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

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $this->engine->process($match);
        }
    }
}
