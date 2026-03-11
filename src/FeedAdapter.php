<?php

declare(strict_types=1);

class FeedAdapter {

    public static function getLiveMatches(): array
    {
        $file = dirname(__DIR__) . '/data/live.json';

        if (!file_exists($file)) {
            return [];
        }

        $json = file_get_contents($file);

        if ($json === false || trim($json) === '') {
            return [];
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }
}
