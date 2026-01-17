<?php
date_default_timezone_set('Europe/Rome');
/**
 * Invio Web Push VAPID 1-to-1 (versione semplice, form-POST).
 * Input (POST x-www-form-urlencoded):
 *   user_id, title, body, message_id (opzionale)
 * Output: JSON { success: bool, error?: string, http?: int, response?: string }
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', '1');
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function out($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function b64u_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64u_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

// Converte privKey(raw 32B) + pubKey(raw 65B) in PEM EC PRIVATE KEY (SEC1)
function buildEcPrivatePem($rawPriv, $rawPub) {
    $seq  = "\x02\x01\x01";
    $seq .= "\x04\x20" . $rawPriv;
    $seq .= "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
    $seq .= "\xA1\x44\x03\x42\x00" . $rawPub;
    $der = "\x30" . derLength(strlen($seq)) . $seq;
    $pem = "-----BEGIN EC PRIVATE KEY-----\n";
    $pem .= chunk_split(base64_encode($der), 64, "\n");
    $pem .= "-----END EC PRIVATE KEY-----\n";
    return $pem;
}

function derLength($len) {
    if ($len < 0x80) return chr($len);
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xff) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function derToRawSignature($der) {
    $pos = 2; // skip 0x30 len
    if ($der[0] !== "\x30") return null;
    if ($der[$pos] !== "\x02") return null;
    $rLen = ord($der[$pos + 1]);
    $r = substr($der, $pos + 2, $rLen);
    $pos = $pos + 2 + $rLen;
    if ($der[$pos] !== "\x02") return null;
    $sLen = ord($der[$pos + 1]);
    $s = substr($der, $pos + 2, $sLen);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

// Config VAPID
$configPath = dirname(__DIR__) . '/config/vapid.json';
if (!file_exists($configPath)) {
    out(['success' => false, 'error' => "Config vapid.json non trovato"], 500);
}
$cfg = json_decode(file_get_contents($configPath), true);
if (!is_array($cfg)) {
    out(['success' => false, 'error' => 'Config vapid.json non valido'], 500);
}
$appId = $cfg['app_id'] ?? 'APP';
$vapidPublic = $cfg['public_key'] ?? null;
$vapidPrivate = $cfg['private_key'] ?? null;
$subject = $cfg['subject'] ?? null;
if (!$vapidPublic || !$vapidPrivate) {
    out(['success' => false, 'error' => 'Chiavi VAPID mancanti'], 500);
}

// Debug rapido: mostra config e path DB usati
if (isset($_GET['debug'])) {
    out([
        'success' => true,
        'config_path' => $configPath,
        'public_key' => $vapidPublic,
        'app_id' => $appId,
        'subject' => $subject,
        'db_file' => ($cfg['db_file'] ?? 'push_subscriptions.db'),
        'db_path' => __DIR__ . '/' . ($cfg['db_file'] ?? 'push_subscriptions.db')
    ]);
}

// DB locale (usa db_file se presente nel config)
$dbFile = __DIR__ . '/' . ($cfg['db_file'] ?? 'push_subscriptions.db');

// Input form POST
$userId = isset($_POST['user_id']) ? trim((string)$_POST['user_id']) : '';
$messageId = isset($_POST['message_id']) ? trim((string)$_POST['message_id']) : '';
$title = isset($_POST['title']) ? trim((string)$_POST['title']) : 'Messaggio dal centro';
$body  = isset($_POST['body'])  ? trim((string)$_POST['body'])  : '';

if ($userId === '') {
    out(['success' => false, 'error' => 'user_id mancante'], 400);
}

// Carica subscription
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Schema compatibile con debug_push_1to1.php
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        app_id TEXT NOT NULL,
        endpoint TEXT NOT NULL UNIQUE,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        user_id TEXT,
        telefono TEXT,
        nome_cliente TEXT,
        data_registrazione DATETIME DEFAULT CURRENT_TIMESTAMP,
        ultimo_invio DATETIME,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
    ");
    $cols = $pdo->query("PRAGMA table_info(subscriptions)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(fn($c) => $c['name'], $cols);
    if (!in_array('user_id', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN user_id TEXT");
    }
    if (!in_array('telefono', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN telefono TEXT");
    }
    if (!in_array('nome_cliente', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN nome_cliente TEXT");
    }
    if (!in_array('data_registrazione', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN data_registrazione DATETIME");
    }
    if (!in_array('ultimo_invio', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN ultimo_invio DATETIME");
    }
    $sub = null;

    $stmt = $pdo->prepare("SELECT endpoint, p256dh, auth FROM subscriptions WHERE user_id = :user_id ORDER BY id DESC LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        out(['success' => false, 'error' => 'NO_SUBSCRIPTION'], 400);
    }
} catch (Exception $e) {
    out(['success' => false, 'error' => 'Errore DB: ' . $e->getMessage()], 500);
}

// Prepara chiavi e JWT
$rawPriv = b64u_decode($vapidPrivate);
$rawPub  = b64u_decode($vapidPublic);
if (strlen($rawPriv) !== 32 || strlen($rawPub) !== 65 || $rawPub[0] !== "\x04") {
    out(['success' => false, 'error' => 'Chiavi VAPID non nel formato atteso'], 500);
}
$pem = buildEcPrivatePem($rawPriv, $rawPub);
$privKey = openssl_pkey_get_private($pem);
if (!$privKey) {
    out(['success' => false, 'error' => 'Impossibile caricare la chiave privata VAPID'], 500);
}
if (!preg_match('#^https?://[^/]+#', $sub['endpoint'], $m)) {
    out(['success' => false, 'error' => 'Endpoint non valido'], 500);
}
$aud = $m[0];
$header = ['alg' => 'ES256', 'typ' => 'JWT'];
$payload = ['aud' => $aud, 'exp' => time() + 3600, 'sub' => $subject];
$jwtUnsigned = b64u_encode(json_encode($header)) . '.' . b64u_encode(json_encode($payload));
$derSig = '';
if (!openssl_sign($jwtUnsigned, $derSig, $privKey, OPENSSL_ALGO_SHA256)) {
    out(['success' => false, 'error' => 'Firma VAPID fallita'], 500);
}
$rawSig = derToRawSignature($derSig);
if (!$rawSig) {
    out(['success' => false, 'error' => 'Conversione firma VAPID fallita'], 500);
}
$jwt = $jwtUnsigned . '.' . b64u_encode($rawSig);

// Payload push
$payloadData = [
    'title' => $title,
    'body' => $body,
    'data' => [
        'debug' => true,
        'at' => date('c'),
        'message_id' => $messageId !== '' ? $messageId : null
    ],
];
$payloadJson = json_encode($payloadData);

// Headers e invio
$headers = [
    'Authorization: WebPush ' . $jwt,
    'Crypto-Key: p256ecdsa=' . $vapidPublic,
    'TTL: 60',
    'Content-Type: application/json',
];

$endpoint = $sub['endpoint'];
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    out(['success' => false, 'error' => 'CURL: ' . $err], 500);
}
if ($code < 200 || $code >= 300) {
    out(['success' => false, 'error' => 'HTTP ' . $code, 'response' => $resp], $code);
}

out(['success' => true, 'http' => $code, 'response' => $resp, 'user_id' => $userId]);


