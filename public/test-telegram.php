<?php
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

header('Content-Type: application/json');

$token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$chatId = $_ENV['TELEGRAM_CHAT_ID'] ?? '';

$result = [
    'token_set' => !empty($token),
    'token_length' => strlen($token),
    'token_preview' => substr($token, 0, 10) . '...',
    'chat_id_set' => !empty($chatId),
    'chat_id' => $chatId,
];

// Try to send
if (!empty($token) && !empty($chatId)) {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => '✅ Railway тест - ' . date('H:i:s'),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    $result['telegram_response'] = json_decode($response, true);
    $result['curl_error'] = $err;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
