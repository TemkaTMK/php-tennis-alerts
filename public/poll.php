<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

// Secret шалгалт
$secret = $_ENV['POLL_SECRET'] ?? '';
$provided = $_GET['secret'] ?? '';

if (empty($secret) || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Rate limiting (IP бүрт 60 секундэд 1 удаа)
$rateLimitDir = dirname(__DIR__) . '/storage/rate_limit';
if (!is_dir($rateLimitDir)) {
    mkdir($rateLimitDir, 0775, true);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lockFile = $rateLimitDir . '/' . md5($ip) . '.lock';

if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limited. Try again later.']);
    exit;
}

touch($lockFile);

// Polling ажиллуулах
$pdo = db();
$telegram = new App\Telegram(
    $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    $_ENV['TELEGRAM_CHAT_ID'] ?? ''
);
$ruleEngine = new App\RuleEngine($telegram, $pdo);
$feed = new App\FeedAdapter($_ENV['TENNIS_API_KEY'] ?? '');
$poller = new App\Poller($feed, $ruleEngine);

$poller->run();

echo json_encode(['status' => 'OK']);
