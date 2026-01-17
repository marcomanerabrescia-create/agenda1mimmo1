<?php
header('Content-Type: application/json; charset=utf-8');

$configPath = __DIR__ . '/../config/vapid.json';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => "Config vapid.json non trovato ($configPath)"]);
    exit;
}

$config = json_decode(file_get_contents($configPath), true);
if (!is_array($config) || empty($config['public_key'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config vapid.json non valido']);
    exit;
}

$publicKey = $config['public_key'] ?? null;
$appId = $config['app_id'] ?? null;
$subject = $config['subject'] ?? null;

echo json_encode([
    'success' => true,
    'public_key' => $publicKey,
    'app_id' => $appId,
    'subject' => $subject
]);