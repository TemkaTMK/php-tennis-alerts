<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Ulaanbaatar');

$_ENV['TELEGRAM_BOT_TOKEN'] = '8616773796:AAHNqW9O6VahYHk-rUDjk32cnrsFLfsNXXg';
$_ENV['TELEGRAM_CHAT_ID']   = '-5292312281';

putenv('TELEGRAM_BOT_TOKEN=' . $_ENV['TELEGRAM_BOT_TOKEN']);
putenv('TELEGRAM_CHAT_ID=' . $_ENV['TELEGRAM_CHAT_ID']);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Telegram.php';
require_once __DIR__ . '/RuleEngine.php';
require_once __DIR__ . '/FeedAdapter.php';
require_once __DIR__ . '/Poller.php';
