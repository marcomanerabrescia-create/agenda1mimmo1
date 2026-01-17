<?php
date_default_timezone_set('Europe/Rome');
/**
 * Invio push “libere” a tutti i dispositivi registrati.
 * Parametri attesi (POST JSON o form):
 *   - title  (string, obbligatorio)
 *   - message (string, obbligatorio; oppure body)
 *   - url     (opzionale, destinazione della notifica)
 *   - icon    (opzionale)
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', '1');
error_reporting(E_ALL);

function json_out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function b64u_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64u_decode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function derLength(int $len): string {
    if ($len < 0x80) {
        return chr($len);
    }
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xff) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function buildEcPrivatePem(string $rawPriv, string $rawPub): string {
    $seq  = "\x02\x01\x01";
    $seq .= "\x04\x20" . $rawPriv;
    $seq .= "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
    $seq .= "\xA1\x44\x03\x42\x00" . $rawPub;
    $der  = "\x30" . derLength(strlen($seq)) . $seq;
    $pem  = "-----BEGIN EC PRIVATE KEY-----\n";
    $pem .= chunk_split(base64_encode($der), 64, "\n");
    $pem .= "-----END EC PRIVATE KEY-----\n";
    return $pem;
}

function derToRawSignature(string $der): ?string {
    $pos = 2;
    if ($der[0] !== "\x30") {
        return null;
    }
    if ($der[$pos] !== "\x02") {
        return null;
    }
    $rLen = ord($der[$pos + 1]);
    $r = substr($der, $pos + 2, $rLen);
    $pos = $pos + 2 + $rLen;
    if ($der[$pos] !== "\x02") {
        return null;
    }
    $sLen = ord($der[$pos + 1]);
    $s = substr($der, $pos + 2, $sLen);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$title = trim($input['title'] ?? $_POST['title'] ?? $_GET['title'] ?? '');
$message = trim($input['message'] ?? $input['body'] ?? $_POST['message'] ?? $_POST['body'] ?? $_GET['message'] ?? $_GET['body'] ?? '');
$url = trim($input['url'] ?? $_POST['url'] ?? $_GET['url'] ?? '');
$icon = trim($input['icon'] ?? $_POST['icon'] ?? $_GET['icon'] ?? 'icon-192.png');
$badge = trim($input['badge'] ?? $_POST['badge'] ?? $_GET['badge'] ?? $icon);

if ($title === '' || $message === '') {
    json_out(['success' => false, 'error' => 'Titolo e messaggio obbligatori'], 400);
}

$configPath = dirname(__DIR__) . '/config/vapid.json';
if (!file_exists($configPath)) {
    json_out(['success' => false, 'error' => "Config vapid.json non trovato ($configPath)"], 500);
}
$config = json_decode(file_get_contents($configPath), true);
if (!is_array($config)) {
    json_out(['success' => false, 'error' => 'Config vapid.json non valido'], 500);
}
$vapidPublic = trim((string)($config['public_key'] ?? ''));
$vapidPrivate = trim((string)($config['private_key'] ?? ''));
$vapidSubject = $config['subject'] ?? null;
if ($vapidPublic === '' || $vapidPrivate === '') {
    json_out(['success' => false, 'error' => 'Chiavi VAPID mancanti'], 500);
}

$rawPriv = b64u_decode($vapidPrivate);
$rawPub = b64u_decode($vapidPublic);
if (strlen($rawPriv) !== 32 || strlen($rawPub) !== 65 || $rawPub[0] !== "\x04") {
    json_out(['success' => false, 'error' => 'Chiavi VAPID non nel formato atteso'], 500);
}
$pem = buildEcPrivatePem($rawPriv, $rawPub);
$privKey = openssl_pkey_get_private($pem);
if (!$privKey) {
    json_out(['success' => false, 'error' => 'Errore caricamento chiave privata VAPID'], 500);
}

$dbPath = __DIR__ . '/push_subscriptions.db';
if (!file_exists($dbPath)) {
    json_out(['success' => false, 'error' => 'Database push_subscriptions non trovato'], 500);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $subscriptions = $pdo->query("SELECT endpoint, p256dh, auth FROM subscriptions")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => 'Errore DB: ' . $e->getMessage()], 500);
}

if (!$subscriptions) {
    json_out(['success' => true, 'sent' => 0, 'failed' => 0, 'message' => 'Nessuna subscription registrata']);
}

$payloadData = [
    'title' => $title,
    'body' => $message,
    'icon' => $icon,
    'badge' => $badge,
    'data' => [
        'type' => 'free_notification',
        'url' => $url !== '' ? $url : null,
        'timestamp' => date('c'),
    ],
];

$sent = 0;
$failed = 0;
$details = [];

foreach ($subscriptions as $subscription) {
    $endpoint = $subscription['endpoint'] ?? '';
    if ($endpoint === '' || !preg_match('#^https?://[^/]+#', $endpoint, $matches)) {
        $failed++;
        $details[] = ['endpoint' => $endpoint, 'error' => 'Endpoint non valido'];
        continue;
    }

    $audience = $matches[0];
    $header = ['alg' => 'ES256', 'typ' => 'JWT'];
    $payload = ['aud' => $audience, 'exp' => time() + 3600, 'sub' => $vapidSubject];
    $jwtUnsigned = b64u_encode(json_encode($header)) . '.' . b64u_encode(json_encode($payload));
    if (!openssl_sign($jwtUnsigned, $derSignature, $privKey, OPENSSL_ALGO_SHA256)) {
        $failed++;
        $details[] = ['endpoint' => $endpoint, 'error' => 'Firma VAPID fallita'];
        continue;
    }
    $rawSig = derToRawSignature($derSignature);
    if ($rawSig === null) {
        $failed++;
        $details[] = ['endpoint' => $endpoint, 'error' => 'Conversione firma fallita'];
        continue;
    }

    $jwt = $jwtUnsigned . '.' . b64u_encode($rawSig);
    $headers = [
        'Authorization: WebPush ' . $jwt,
        'Crypto-Key: p256ecdsa=' . $vapidPublic,
        'TTL: 3600',
        'Content-Type: application/json',
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $failed++;
        $details[] = ['endpoint' => $endpoint, 'error' => 'CURL: ' . $curlErr];
        continue;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $sent++;
        continue;
    }

    $failed++;
    $details[] = ['endpoint' => $endpoint, 'error' => 'HTTP ' . $httpCode, 'response' => $response];
}

json_out([
    'success' => true,
    'sent' => $sent,
    'failed' => $failed,
    'details' => $details,
]);


