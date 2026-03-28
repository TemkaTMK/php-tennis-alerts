<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$pdo = db();
$telegram = new App\Telegram(
    $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    $_ENV['TELEGRAM_CHAT_ID'] ?? ''
);
$ruleEngine = new App\RuleEngine($telegram, $pdo);
$feed = new App\FeedAdapter($_ENV['TENNIS_API_KEY'] ?? '');
$poller = new App\Poller($feed, $ruleEngine);

$poller->run();

echo "OK\n";
