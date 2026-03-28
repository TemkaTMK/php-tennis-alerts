<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

require_once __DIR__ . '/src/helpers.php';

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Ulaanbaatar');

$interval = (int) ($_ENV['POLL_INTERVAL'] ?? 10);

echo "[Worker] Starting polling every {$interval} seconds..." . PHP_EOL;

while (true) {
    try {
        $pdo = db();
        $telegram = new App\Telegram(
            $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
            $_ENV['TELEGRAM_CHAT_ID'] ?? ''
        );
        $ruleEngine = new App\RuleEngine($telegram, $pdo);
        $feed = new App\FeedAdapter($_ENV['TENNIS_API_KEY'] ?? '');
        $poller = new App\Poller($feed, $ruleEngine);

        $poller->run();

        echo "[" . date('H:i:s') . "] Poll OK" . PHP_EOL;
    } catch (\Throwable $e) {
        error_log("[Worker] Error: " . $e->getMessage());
        echo "[" . date('H:i:s') . "] Error: " . $e->getMessage() . PHP_EOL;
    }

    sleep($interval);
}
