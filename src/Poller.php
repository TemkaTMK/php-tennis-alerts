<?php

declare(strict_types=1);

class Poller
{
    public static function run(): void
    {
        $matches = FeedAdapter::getLiveMatches();

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            RuleEngine::process($match);
        }
    }
}
