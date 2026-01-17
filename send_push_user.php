/**
 * Invio Web Push VAPID 1-to-1 per un singolo user_id (codice attivazione).
 * Input (JSON, POST): { user_id: "...", title: "...", body: "...", data: {...} }
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

$configPath = dirname(__DIR__) . '/config/vapid.json';
// Usa sempre il DB locale nella stessa cartella (allineato a debug_push_1to1.php)
$dbFile = __DIR__ . '/push_subscriptions.db';

function out($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    out(['success' => false, 'error' => 'Composer autoload mancante (vendor/autoload.php). Esegui \"composer install\" in PRENOTAZIONI.'], 500);
}
require_once $autoload;

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

// Carica config VAPID
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

// Input: accetta JSON, POST form, o query string per test
$json = json_decode(file_get_contents('php://input'), true);
$userId = isset($json['user_id']) ? trim((string)$json['user_id']) : '';
$telefonoInput = isset($json['telefono']) ? trim((string)$json['telefono']) : '';
if ($telefonoInput === '' && isset($json['phone'])) {
    $telefonoInput = trim((string)$json['phone']);
}
if ($userId === '' && isset($_POST['user_id'])) {
    $userId = trim((string)$_POST['user_id']);
}
if ($telefonoInput === '' && isset($_POST['telefono'])) {
    $telefonoInput = trim((string)$_POST['telefono']);
}
if ($telefonoInput === '' && isset($_POST['phone'])) {
    $telefonoInput = trim((string)$_POST['phone']);
}
if ($userId === '' && isset($_GET['user_id'])) {
    $userId = trim((string)$_GET['user_id']);
}
if ($telefonoInput === '' && isset($_GET['telefono'])) {
    $telefonoInput = trim((string)$_GET['telefono']);
}
if ($telefonoInput === '' && isset($_GET['phone'])) {
    $telefonoInput = trim((string)$_GET['phone']);
}
// normalizza telefono (solo cifre) per confronto flessibile
$telefonoNorm = preg_replace('/\D+/', '', $telefonoInput);
$title = isset($json['title']) ? trim((string)$json['title']) : (isset($_POST['title']) ? trim((string)$_POST['title']) : 'Messaggio');
$body = isset($json['body']) ? trim((string)$json['body']) : (isset($_POST['body']) ? trim((string)$_POST['body']) : '');
$dataPayload = isset($json['data']) && is_array($json['data']) ? $json['data'] : [];

if ($userId === '' && $telefonoInput === '') {
    out(['success' => false, 'error' => 'user_id o telefono mancanti'], 400);
}

// Carica subscription per user_id
try {
    error_log("send_push_user: user_id ricevuto = {$userId}");
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        app_id TEXT NOT NULL,
        endpoint TEXT NOT NULL UNIQUE,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        user_id TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        failed_attempts INTEGER DEFAULT 0
    )");
    // Assicura la colonna user_id se DB esistente
    $cols = $pdo->query("PRAGMA table_info(subscriptions)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(fn($c) => $c['name'], $cols);
    if (!in_array('user_id', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN user_id TEXT");
    }
    if (!in_array('failed_attempts', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN failed_attempts INTEGER DEFAULT 0");
    }
    $sub = null;
    $countFound = 0;

    // 1) prova match per user_id (se presente)
    if ($userId !== '') {
        $stmt = $pdo->prepare("SELECT endpoint, p256dh, auth FROM subscriptions WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id = :uid");
        $countStmt->execute(['uid' => $userId]);
        $countFound = (int)$countStmt->fetchColumn();
        error_log("send_push_user: subscription per user_id={$userId} => {$countFound}");
    }

    // 2) se non trovata e telefono presente, prova match per telefono
    if (!$sub && $telefonoInput !== '') {
        $stmtTel = $pdo->prepare("
            SELECT endpoint, p256dh, auth
            FROM subscriptions
            WHERE (
                   telefono = :tel_raw
                   OR REPLACE(REPLACE(REPLACE(telefono, '+', ''), ' ', ''), '-', '') = :tel_norm
              )
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtTel->execute([
            'tel_raw' => $telefonoInput,
            'tel_norm' => $telefonoNorm
        ]);
        $sub = $stmtTel->fetch(PDO::FETCH_ASSOC);
        error_log("send_push_user: fallback per telefono raw={$telefonoInput} norm={$telefonoNorm} trovato=" . ($sub ? 'SI' : 'NO'));
    }

    // 3) se ancora nulla, prova una subscription senza user_id (vecchie registrazioni)
    if (!$sub) {
        $stmt2 = $pdo->prepare("SELECT endpoint, p256dh, auth FROM subscriptions WHERE (user_id IS NULL OR user_id = '') ORDER BY id DESC LIMIT 1");
        $stmt2->execute();
        $sub = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            out(['success' => false, 'error' => 'NO_SUBSCRIPTION'], 400);
        }
    }
} catch (Exception $e) {
    out(['success' => false, 'error' => 'Errore DB: ' . $e->getMessage()], 500);
}

// Prepara chiavi
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

// JWT VAPID
$now = time();
$exp = $now + 3600;
if (!preg_match('#^https?://[^/]+#', $sub['endpoint'], $m)) {
    out(['success' => false, 'error' => 'Endpoint non valido'], 500);
}
$aud = $m[0];
$header = ['alg' => 'ES256', 'typ' => 'JWT'];
$payload = ['aud' => $aud, 'exp' => $exp, 'sub' => $subject];
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
    'data' => $dataPayload,
];
$payloadJson = json_encode($payloadData);

$headers = [
    'Authorization: WebPush ' . $jwt,
    'Crypto-Key: p256ecdsa=' . $vapidPublic,
    'TTL: 60',
    'Content-Type: application/json',
];

// INVIO
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
if ($code == 404 || $code == 410) {
    $failedLogFile = __DIR__ . '/subscriptions_failed.log';
    $failedLine = sprintf("[%s] HTTP %d | endpoint: %s | response: %s\n", date('Y-m-d H:i:s'), $code, $endpoint, $resp);
    file_put_contents($failedLogFile, $failedLine, FILE_APPEND);
    error_log("send_push_user: endpoint con HTTP $code - mantengo la subscription");
    out(['success' => false, 'error' => 'HTTP ' . $code, 'response' => $resp], $code);
}
if ($code < 200 || $code >= 300) {
    out(['success' => false, 'error' => 'HTTP ' . $code, 'response' => $resp], $code);
}

// LOG PER DEBUG
$logFile = __DIR__ . '/push_invio.log';
$logData = date('Y-m-d H:i:s') . " | user_id: {$userId} | telefono: {$telefonoInput} | title: {$title} | body: {$body} | endpoint: " . substr($sub['endpoint'], 0, 50) . "... | HTTP: {$code} | Response: {$resp}\n";
file_put_contents($logFile, $logData, FILE_APPEND);

out(['success' => true, 'http' => $code, 'response' => $resp, 'user_id' => $userId]);

