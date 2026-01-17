<?php
date_default_timezone_set('Europe/Rome');
// Debug invio Web Push VAPID 1-to-1 (copia di send_push_user.php con output HTML)
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$steps = [];
function step($label, $status = 'ok', $data = null) {
    global $steps;
    $steps[] = ['label' => $label, 'status' => $status, 'data' => $data];
}

// Utility Base64 URL
function b64u_encode($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function b64u_decode($data) { $pad = strlen($data) % 4; if ($pad) { $data .= str_repeat('=', 4 - $pad); } return base64_decode(strtr($data, '-_', '+/')); }

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
function derLength($len) { if ($len < 0x80) return chr($len); $bytes = ''; while ($len > 0) { $bytes = chr($len & 0xff) . $bytes; $len >>= 8; } return chr(0x80 | strlen($bytes)) . $bytes; }
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

// Input
$userId = isset($_POST['user_id']) ? trim((string)$_POST['user_id']) : '';
$title = isset($_POST['title']) ? trim((string)$_POST['title']) : 'Test Push';
$body  = isset($_POST['body'])  ? trim((string)$_POST['body'])  : 'Messaggio di test';
$run = isset($_POST['run']);

// Config
$configPath = dirname(__DIR__) . '/config/vapid.json';
$dbFile = __DIR__ . '/' . ($config['db_file'] ?? 'push_subscriptions.db');
$attivitaCfg = dirname(__DIR__) . '/config/attivita.json';

$cfg = null;
if (!file_exists($configPath)) {
    step('Caricamento chiavi VAPID da config/vapid.json (manca)', 'error', ['path' => $configPath]);
} else {
    $cfg = json_decode(file_get_contents($configPath), true);
    if (!is_array($cfg)) {
        step('Caricamento chiavi VAPID da config/vapid.json (non valido)', 'error', ['path' => $configPath]);
    } else {
        step('Caricamento chiavi VAPID da config/vapid.json', 'ok', $cfg);
    }
}

// Override DB da attivita
if (file_exists($attivitaCfg)) {
    $attList = json_decode(file_get_contents($attivitaCfg), true);
    if (is_array($attList)) {
        foreach ($attList as $att) {
            if (($att['id'] ?? '') === 'RISTORANTE_MIMMO' && !empty($att['db_path'])) {
                $dbFile = $att['db_path'];
                break;
            }
        }
    }
}

// Se non dobbiamo eseguire, mostra solo form
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Debug Push 1-to-1</title>
<style>
body { background: #111; color: #9f9; font-family: Consolas, 'Courier New', monospace; padding: 20px; }
.container { max-width: 1100px; margin: 0 auto; }
h1 { color: #6f6; }
form { background: #1a1a1a; padding: 15px; border: 1px solid #333; border-radius: 6px; margin-bottom: 20px; }
label { display: block; margin: 8px 0 4px; color: #9f9; }
input[type=text] { width: 100%; padding: 8px; background: #111; color: #fff; border: 1px solid #333; border-radius: 4px; }
button { margin-top: 10px; padding: 10px 16px; background: #2f8; color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
section { background: #0d0d0d; border: 1px solid #222; border-radius: 6px; padding: 12px; margin-bottom: 12px; }
.step.ok { color: #6f6; }
.step.warn { color: #ff0; }
.step.error { color: #f66; }
pre { white-space: pre-wrap; word-break: break-word; background: #000; padding: 10px; border-radius: 4px; border: 1px solid #222; }
</style>
</head>
<body>
<div class="container">
<h1>Debug Push 1-to-1 (VAPID)</h1>
<form method="post">
  <label>User ID</label>
  <input type="text" name="user_id" value="<?php
date_default_timezone_set('Europe/Rome'); echo h($userId !== '' ? $userId : '34529'); ?>">
  <label>Telefono</label>
  <input type="text" name="telefono" value="<?php
date_default_timezone_set('Europe/Rome'); echo h($telefonoInput !== '' ? $telefonoInput : '3486374897'); ?>">
  <label>Titolo</label>
  <input type="text" name="title" value="<?php
date_default_timezone_set('Europe/Rome'); echo h($title); ?>">
  <label>Messaggio</label>
  <input type="text" name="body" value="<?php
date_default_timezone_set('Europe/Rome'); echo h($body); ?>">
  <input type="hidden" name="run" value="1">
  <button type="submit">INVIA PUSH TEST</button>
</form>

<?php
date_default_timezone_set('Europe/Rome');
if (!$run) {
    echo '<p>Compila il form e invia per eseguire il test.</p>';
    echo '</div></body></html>';
    exit;
}

// Se manca config valida, stop
if (!$cfg || !is_array($cfg)) {
    echo '<p class="step error">Config VAPID non disponibile. Controlla vapid.json.</p>';
    echo '</div></body></html>';
    exit;
}

$appId = $cfg['app_id'] ?? 'APP';
$vapidPublic = $cfg['public_key'] ?? null;
$vapidPrivate = $cfg['private_key'] ?? null;
$subject = $cfg['subject'] ?? null;
if (!$vapidPublic || !$vapidPrivate) {
    step('Chiavi VAPID mancanti', 'error');
}

// Connessione DB
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscriptions (
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
    )");
    // aggiunge user_id se manca (compatibilita vecchio schema)
    $cols = $pdo->query("PRAGMA table_info(subscriptions)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(fn($c) => $c['name'], $cols);
    if (!in_array('user_id', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN user_id TEXT");
    }
    if (!in_array('telefono', $colNames, true)) {
        $pdo->exec("ALTER TABLE subscriptions ADD COLUMN telefono TEXT");
    }
    step('Connessione al database push_subscriptions.db', 'ok', ['file' => $dbFile]);
} catch (Exception $e) {
    step('Connessione al database push_subscriptions.db', 'error', $e->getMessage());
}

$sub = null;
try {
    if ($userId !== '') {
        $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        step('Ricerca subscription per user_id', $sub ? 'ok' : 'warn', ['user_id' => $userId, 'sub' => $sub]);
    }
} catch (Exception $e) {
    step('Errore query subscription', 'error', $e->getMessage());
}

if (!$sub) {
    echo '<section><div class="step error">Nessuna subscription trovata</div></section>';
    foreach ($steps as $s) {
        $cls = 'step ' . $s['status'];
        echo '<section class="' . h($cls) . '"><strong>' . h($s['label']) . '</strong><pre>' . h(print_r($s['data'], true)) . '</pre></section>';
    }
    echo '</div></body></html>';
    exit;
}

// Generazione JWT VAPID
$rawPriv = b64u_decode($vapidPrivate);
$rawPub  = b64u_decode($vapidPublic);
$jwt = null;
$privKey = null;
$headers = [];
$payloadJson = null;
try {
    if (strlen($rawPriv) !== 32 || strlen($rawPub) !== 65 || $rawPub[0] !== "\x04") {
        throw new Exception('Chiavi VAPID non nel formato atteso');
    }
    $pem = buildEcPrivatePem($rawPriv, $rawPub);
    $privKey = openssl_pkey_get_private($pem);
    if (!$privKey) {
        throw new Exception('Impossibile caricare la chiave privata VAPID');
    }
    if (!preg_match('#^https?://[^/]+#', $sub['endpoint'], $m)) {
        throw new Exception('Endpoint non valido');
    }
    $aud = $m[0];
    $header = ['alg' => 'ES256', 'typ' => 'JWT'];
    $payload = ['aud' => $aud, 'exp' => time() + 3600, 'sub' => $subject];
    $jwtUnsigned = b64u_encode(json_encode($header)) . '.' . b64u_encode(json_encode($payload));
    $derSig = '';
    if (!openssl_sign($jwtUnsigned, $derSig, $privKey, OPENSSL_ALGO_SHA256)) {
        throw new Exception('Firma VAPID fallita');
    }
    $rawSig = derToRawSignature($derSig);
    if (!$rawSig) {
        throw new Exception('Conversione firma VAPID fallita');
    }
    $jwt = $jwtUnsigned . '.' . b64u_encode($rawSig);
    step('Generazione token JWT VAPID', 'ok', ['aud' => $aud, 'header' => $header, 'payload' => $payload, 'jwt' => $jwt]);
} catch (Exception $e) {
    step('Generazione token JWT VAPID', 'error', $e->getMessage());
}

// Payload push
$payloadData = [
    'title' => $title,
    'body' => $body,
    'data' => ['debug' => true, 'at' => date('c')],
];
$payloadJson = json_encode($payloadData);

// Headers
$headers = [
    'Authorization: WebPush ' . $jwt,
    'Crypto-Key: p256ecdsa=' . $vapidPublic,
    'TTL: 60',
    'Content-Type: application/json',
];
step('Headers cURL preparati', 'ok', $headers);

$response = null; $code = null; $curlErr = null;
try {
    $endpoint = $sub['endpoint'];
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) {
        step('Invio POST a FCM', 'error', $curlErr);
    } else {
        $status = ($code >= 200 && $code < 300) ? 'ok' : (($code >= 400) ? 'error' : 'warn');
        step('Invio POST a FCM', $status, ['http_code' => $code]);
    }
} catch (Exception $e) {
    step('Invio POST a FCM', 'error', $e->getMessage());
}

// Risposta HTTP
if ($curlErr) {
    step('Risposta HTTP', 'error', ['error' => $curlErr, 'response' => $response]);
} else {
    step('Risposta HTTP', ($code >= 200 && $code < 300) ? 'ok' : 'error', ['http_code' => $code, 'response' => $response]);
}

// Output sezioni
foreach ($steps as $s) {
    $cls = 'step ' . $s['status'];
    echo '<section class="' . h($cls) . '"><strong>' . h($s['label']) . '</strong>';
    if ($s['data'] !== null) {
        echo '<pre>' . h(print_r($s['data'], true)) . '</pre>';
    }
    echo '</section>';
}

echo '<section><strong>Subscription trovata</strong><pre>' . h(print_r($sub, true)) . '</pre></section>';
echo '<section><strong>Payload JSON</strong><pre>' . h($payloadJson) . '</pre></section>';
echo '<section><strong>JWT</strong><pre>' . h($jwt) . '</pre></section>';
echo '<section><strong>Headers inviati</strong><pre>' . h(print_r($headers, true)) . '</pre></section>';
echo '<section><strong>Risposta cURL</strong><pre>' . h(print_r(['http_code' => $code, 'curl_error' => $curlErr, 'response' => $response], true)) . '</pre></section>';

echo '</div></body></html>';
?>


