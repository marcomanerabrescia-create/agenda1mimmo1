<?php
date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json');

function loadVapidConfig()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = __DIR__ . '/../config/vapid.json';
    if (!file_exists($path)) {
        throw new Exception("Config vapid.json non trovato ($path)");
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        throw new Exception('Config vapid.json non valido');
    }
    $public = trim((string)($data['public_key'] ?? ''));
    $private = trim((string)($data['private_key'] ?? ''));
    if ($public === '' || $private === '') {
        throw new Exception('Chiavi VAPID mancanti in config/vapid.json');
    }
    $cached = [
        'public_key' => $public,
        'private_key' => $private,
        'app_id' => $data['app_id'] ?? null,
        'subject' => $data['subject'] ?? null,
    ];
    return $cached;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if ($data && isset($data['action']) && $data['action'] === 'send_single') {
    $phone = $data['phone'] ?? null;
    $message = $data['message'] ?? '';
    $title = $data['title'] ?? 'Ristorante da MIMMO';
    
    if (!$phone || !$message) {
        echo json_encode(['success' => false, 'error' => 'Telefono o messaggio mancante']);
        exit;
    }
    
    try {
        $dbPath = __DIR__ . '/push_subscriptions.db';
        $db = new PDO('sqlite:' . $dbPath);
        
        // normalizza telefono (solo cifre) per confronto flessibile
        $phoneNorm = preg_replace('/\D+/', '', $phone);

        // Cerca nella tabella subscriptions (campo phone o telefono)
        $stmt = $db->prepare("
            SELECT endpoint, p256dh, auth
            FROM subscriptions
            WHERE (phone = :phone_raw OR telefono = :phone_raw)
               OR REPLACE(REPLACE(REPLACE(COALESCE(phone, telefono, ''), '+', ''), ' ', ''), '-', '') = :phone_norm
            LIMIT 1
        ");
        $stmt->execute([':phone_raw' => $phone, ':phone_norm' => $phoneNorm]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subscription) {
            echo json_encode(['success' => false, 'error' => 'Utente non registrato']);
            exit;
        }
        
        $vapidCfg = loadVapidConfig();
        $vapidPublic = $vapidCfg['public_key'];
        $vapidPrivate = $vapidCfg['private_key'];
        $vapidSubject = $vapidCfg['subject'] ?? null;

        // Costruisci JWT VAPID (copia dal broadcast)
        $parsedUrl = parse_url($subscription['endpoint']);
        $aud = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        $exp = time() + 43200;

        $header = ['alg' => 'ES256', 'typ' => 'JWT'];
        $payload = ['aud' => $aud, 'exp' => $exp, 'sub' => $vapidSubject];
        $jwtUnsigned = b64u_encode(json_encode($header)) . '.' . b64u_encode(json_encode($payload));

        // Firma JWT (usa le funzioni esistenti)
        $rawPriv = b64u_decode($vapidPrivate);
        $rawPub = b64u_decode($vapidPublic);
        $pem = buildEcPrivatePem($rawPriv, $rawPub);
        $privKey = openssl_pkey_get_private($pem);
        openssl_sign($jwtUnsigned, $rawSig, $privKey, OPENSSL_ALGO_SHA256);
        $jwt = $jwtUnsigned . '.' . b64u_encode($rawSig);

        // Prepara headers
        $headers = [
            'Authorization: WebPush ' . $jwt,
            'Crypto-Key: p256ecdsa=' . $vapidPublic,
            'TTL: 60',
            'Content-Type: application/json'
        ];

        // Prepara notifica
        $notification = [
            'title' => $title,
            'body' => $message,
            'icon' => 'https://www.consulenticaniegatti.com/app/ristorantedamimmo/PRENOTAZIONI/icon-192.png',
            'badge' => 'https://www.consulenticaniegatti.com/app/ristorantedamimmo/PRENOTAZIONI/icon-192.png',
            'requireInteraction' => false,
            'timestamp' => time()
        ];

        // Invia via curl
        $ch = curl_init($subscription['endpoint']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            echo json_encode(['success' => false, 'error' => 'cURL: ' . $err]);
        } elseif ($code >= 200 && $code < 300) {
            echo json_encode(['success' => true, 'sent' => 1, 'phone' => $phone]);
        } else {
            echo json_encode(['success' => false, 'error' => 'HTTP ' . $code]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<?php
date_default_timezone_set('Europe/Rome');
/**
 * Invio Web Push senza vendor/composer.
 * - Legge config/vapid.json per le chiavi VAPID (public/private)
 * - DB SQLite con colonne: endpoint, p256dh, auth
 * - Invio body vuoto (nessun Content-Type)
 * - Header: Authorization: WebPush <jwt> ; Crypto-Key: p256ecdsa=<chiave pubblica>
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');
$logFile = __DIR__ . '/push_execution.log';
file_put_contents($logFile, "\n========== " . date('Y-m-d H:i:s') . " =========\n", FILE_APPEND);

// ===============================
// PROTEZIONE FACOLTATIVA CON TOKEN
// Imposta un token non vuoto per abilitarla, es: $CRON_TOKEN = 'metti-qui-un-token';
// Se resta vuoto, nessun controllo token viene applicato.
// ===============================
$CRON_TOKEN = ''; // <- opzionale. Se valorizzato, richiede ?token=...
$token = $_GET['token'] ?? '';
if (!empty($CRON_TOKEN) && $token !== $CRON_TOKEN) {
    http_response_code(403);
    exit("Accesso negato (token mancante o errato)\n");
}

// ===============================
// INPUT
// ===============================
$appId = $_GET['app'] ?? 'RISTORANTE_MIMMO';
$WINDOW_MINUTES = 5; // finestra di tolleranza minuti sugli orari push

// ===============================
// UTILS
// ===============================
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

function derLength($len) {
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

// Converte privKey(raw 32B) + pubKey(raw 65B, 0x04||X||Y) in PEM EC PRIVATE KEY (SEC1)
function buildEcPrivatePem($rawPriv, $rawPub) {
    $seq  = "\x02\x01\x01";                  // version
    $seq .= "\x04\x20" . $rawPriv;           // privateKey OCTET STRING
    $seq .= "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"; // parameters prime256v1
    $seq .= "\xA1\x44\x03\x42\x00" . $rawPub; // publicKey BIT STRING
    $der = "\x30" . derLength(strlen($seq)) . $seq;
    $pem = "-----BEGIN EC PRIVATE KEY-----\n";
    $pem .= chunk_split(base64_encode($der), 64, "\n");
    $pem .= "-----END EC PRIVATE KEY-----\n";
    return $pem;
}

// Converte firma DER (openssl_sign) in R||S (64B) per ES256
function derToRawSignature($der) {
    $pos = 2; // skip 0x30 len
    if ($der[0] !== "\x30") {
        return null;
    }
    // integer r
    if ($der[$pos] !== "\x02") return null;
    $rLen = ord($der[$pos + 1]);
    $r = substr($der, $pos + 2, $rLen);
    $pos = $pos + 2 + $rLen;
    // integer s
    if ($der[$pos] !== "\x02") return null;
    $sLen = ord($der[$pos + 1]);
    $s = substr($der, $pos + 2, $sLen);
    // pad to 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

// ===============================
// CARICA CONFIG
try {
    $vapidCfg = loadVapidConfig();
} catch (Exception $e) {
    exit("ERRORE: " . $e->getMessage() . "\n");
}

$appId = $_GET['app'] ?? $vapidCfg['app_id'] ?? 'RISTORANTE_MIMMO';
$vapidPublic = $vapidCfg['public_key'];
$vapidPrivate = $vapidCfg['private_key'];
$vapidSubject = $vapidCfg['subject'] ?? null;

// DB iscrizioni (locale)
$dbPath = __DIR__ . '/push_subscriptions.db';
error_log("DEBUG: send_push_novendor reading push_subscriptions at $dbPath");
// DB promozioni (per orari) - path assoluto unico
$promoDbPath = '/web/htdocs/www.consulenticaniegatti.com/app/ristorantedamimmo/promozioni-toilet-001.db';

if (!$vapidPublic || !$vapidPrivate || !$dbPath) {
    exit("ERRORE: chiavi VAPID mancanti nel file config/vapid.json\n");
}

// ===============================
// PREPARA CHIAVI
// ===============================
$rawPriv = b64u_decode($vapidPrivate);
$rawPub  = b64u_decode($vapidPublic);
if (strlen($rawPriv) !== 32 || strlen($rawPub) !== 65 || $rawPub[0] !== "\x04") {
    exit("ERRORE: chiavi VAPID non nel formato atteso\n");
}
$pem = buildEcPrivatePem($rawPriv, $rawPub);
$privKey = openssl_pkey_get_private($pem);
if (!$privKey) {
    exit("ERRORE: impossibile caricare la chiave privata VAPID\n");
}

// ===============================
// CARICA SUBS DAL DB
// ===============================
if (!file_exists($dbPath)) {
    exit("ERRORE: DB subscription non trovato: $dbPath\n");
}

// Carica le subscription VAPID
$pdoSubs = new PDO('sqlite:' . $dbPath);
$pdoSubs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$subs = $pdoSubs->query("SELECT endpoint, p256dh, auth FROM subscriptions")->fetchAll(PDO::FETCH_ASSOC);

if (!$subs) {
file_put_contents($logFile, "Subscription trovate: 0\n", FILE_APPEND);
    exit("Nessuna subscription trovata.\n");
}
file_put_contents($logFile, "Subscription trovate: " . count($subs) . "\n", FILE_APPEND);
error_log("========== INIZIO INVIO PUSH ==========");
foreach ($subs as $index => $sub) {
    error_log(" [{$index}] Invio a: " . substr($sub['endpoint'], 0, 70) . "...");
    // l'invio avviene più sotto, questo blocco serve solo a loggare prima del ciclo di invio effettivo
}
error_log("========== FINE INVIO PUSH ==========");

// Carica promozioni valide oggi e push_attivo
if (!file_exists($promoDbPath)) {
    exit("ERRORE: DB promozioni non trovato: $promoDbPath\n");
}

$pdoPromo = new PDO('sqlite:' . $promoDbPath);
$pdoPromo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$oggi = date('Y-m-d');
$lastResetFile = __DIR__ . '/last_reset.txt';
$lastReset = @file_get_contents($lastResetFile);
if ($lastReset !== $oggi) {
    $pdoPromo->exec("UPDATE promozioni SET push_sent_today = ''");
    file_put_contents($lastResetFile, $oggi);
}

$now = date('H:i');

$stmtPromo = $pdoPromo->prepare("
    SELECT * FROM promozioni
    WHERE push_attivo = 1
      AND attivo = 1
      AND date(:oggi) BETWEEN date(data_inizio) AND date(data_fine)
");
$stmtPromo->execute(['oggi' => $oggi]);
$promos = $stmtPromo->fetchAll(PDO::FETCH_ASSOC);
file_put_contents($logFile, "Promo trovate: " . count($promos) . "\n", FILE_APPEND);

$daInviare = [];

foreach ($promos as $promo) {
    file_put_contents($logFile, "Promo ID: {$promo['id']} | push_attivo: {$promo['push_attivo']} | data_inizio: {$promo['data_inizio']} | data_fine: {$promo['data_fine']}\n", FILE_APPEND);
    $orari = json_decode($promo['push_orari'] ?? '[]', true);
    if (!is_array($orari) || empty($orari)) {
        continue;
    }

    // Orari gi� inviati oggi
    $giaInviati = [];
    if (!empty($promo['push_sent_today'])) {
        $sentData = json_decode($promo['push_sent_today'], true);
        if (is_array($sentData) && ($sentData['data'] ?? '') === $oggi) {
            $giaInviati = $sentData['orari'] ?? [];
        }
    }

    foreach ($orari as $orario) {
        $diffMin = (strtotime("$oggi $now") - strtotime("$oggi $orario")) / 60;
        $giaInviato = in_array($orario, $giaInviati);

        // log visibile per capire cosa succede
        echo "[CHECK] Promo {$promo['id']} {$promo['titolo']} | orario $orario | diff_min=" . round($diffMin,1) . " | gia_inviato=" . ($giaInviato ? 'SI' : 'NO') . "\n";

        // Log dettagliato per capire gli orari valutati
        $logLine = sprintf(
            "%s - Orario promo: %s | Ora attuale: %s | Diff: %.2f minuti | In range: %s | Gia inviato: %s\n",
            date('H:i:s'),
            $orario,
            $now,
            $diffMin,
            ($diffMin >= -2 && $diffMin <= 10) ? 'SI' : 'NO',
            $giaInviato ? 'SI' : 'NO'
        );
        file_put_contents(__DIR__ . '/debug_orari.log', $logLine, FILE_APPEND);
        file_put_contents($logFile, "Orario: $orario | Ora: $now | Diff: $diffMin min | Range OK: " . (($diffMin >= -2 && $diffMin <= 10) ? 'SI' : 'NO') . " | Gia inviato: " . ($giaInviato ? 'SI' : 'NO') . "\n", FILE_APPEND);

        // Invia solo se l'orario è nel range -2/+10 minuti rispetto all'ora attuale
        if ($diffMin >= -2 && $diffMin <= 10 && !$giaInviato) {
            $daInviare[] = [
                'promo' => $promo,
                'orario' => $orario,
                'giaInviati' => $giaInviati
            ];
        }
    }
}

if (empty($daInviare)) {
    echo "Nessuna promo da inviare in questa finestra ($now)\n";
    exit;
}

// ===============================
// PREPARA JWT VAPID
// ===============================
$now = time();
$exp = $now + 3600;
// audience = origin dell'endpoint
$firstEndpoint = $subs[0]['endpoint'];
if (!preg_match('#^https?://[^/]+#', $firstEndpoint, $m)) {
    exit("ERRORE: endpoint non valido\n");
}
$aud = $m[0];

$header = ['alg' => 'ES256', 'typ' => 'JWT'];
$payload = ['aud' => $aud, 'exp' => $exp, 'sub' => $vapidSubject];
$jwtUnsigned = b64u_encode(json_encode($header)) . '.' . b64u_encode(json_encode($payload));

$derSig = '';
if (!openssl_sign($jwtUnsigned, $derSig, $privKey, OPENSSL_ALGO_SHA256)) {
    exit("ERRORE: firma VAPID fallita\n");
}
$rawSig = derToRawSignature($derSig);
if (!$rawSig) {
    exit("ERRORE: conversione firma\n");
}
$jwt = $jwtUnsigned . '.' . b64u_encode($rawSig);

// ===============================
// INVIO
// ===============================
$headers = [
    'Authorization: WebPush ' . $jwt,
    'Crypto-Key: p256ecdsa=' . $vapidPublic,
    'TTL: 60',
    'Content-Type: application/json'
];

echo "== Invio Web Push NO-VENDOR ($appId) ==\n";
echo "Totale subscription: " . count($subs) . "\n";
echo "Promo da inviare ora: " . count($daInviare) . "\n";

$okTotal = 0;
$failTotal = 0;
$failDetails = [];

foreach ($daInviare as $item) {
    $promo = $item['promo'];
    $orario = $item['orario'];
    $giaInviati = $item['giaInviati'];

    echo "\n-- Promo ID {$promo['id']} ({$promo['titolo']}) orario $orario --\n";

    foreach ($subs as $idx => $row) {
        $endpoint = $row['endpoint'];
        error_log("Invio push #{$idx} a endpoint: " . substr($endpoint, 0, 60));
        try {
            // Costruisci payload notifica
            $notification = [
                'title' => $promo['titolo'],
                'body' => $promo['messaggio'],
                // Icone puntano ai file reali nella cartella PRENOTAZIONI di questa app
                'icon' => 'https://www.consulenticaniegatti.com/app/ristorantedamimmo/PRENOTAZIONI/icon-192.png',
                'badge' => 'https://www.consulenticaniegatti.com/app/ristorantedamimmo/PRENOTAZIONI/icon-192.png',
                'url' => 'https://www.consulenticaniegatti.com/app/ristorantedamimmo/',
                'requireInteraction' => false,
                'tag' => 'promo-' . $promo['id']
            ];

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err) {
                echo "[FAIL] $endpoint | CURL: $err\n";
                $failTotal++;
                $failDetails[] = "[CURL] $endpoint | $err";
                error_log("❌ Push #{$idx} FALLITO: CURL $err");
            } elseif ($code >= 200 && $code < 300) {
                echo "[OK] $endpoint | HTTP $code\n";
                $okTotal++;
                error_log("✅ Push #{$idx} inviato con successo (HTTP $code)");
            } else {
                echo "[FAIL] $endpoint | HTTP $code | Resp: $resp\n";
                $failTotal++;
                $failDetails[] = "[HTTP $code] $endpoint | $resp";
                error_log("❌ Push #{$idx} FALLITO: HTTP $code | $resp");
            }
        } catch (Exception $e) {
            $failTotal++;
            $failDetails[] = "[EXCEPTION] $endpoint | " . $e->getMessage();
            error_log("❌ Push #{$idx} FALLITO: " . $e->getMessage());
        }
    }
    error_log("Totale push inviati (iterazione promo ID {$promo['id']}): " . count($subs));

    // aggiorna push_sent_today per marcare l'orario come inviato
    $giaInviati[] = $orario;
    $newSent = json_encode(['data' => $oggi, 'orari' => array_values(array_unique($giaInviati))]);
    $upd = $pdoPromo->prepare("UPDATE promozioni SET push_sent_today = :sent WHERE id = :id");
    $upd->execute(['sent' => $newSent, 'id' => $promo['id']]);
}

// Riepilogo finale
$summary = sprintf("RIEPILOGO: OK=%d, FAIL=%d\n", $okTotal, $failTotal);
echo $summary;
file_put_contents($logFile, $summary, FILE_APPEND);
if ($failTotal > 0 && !empty($failDetails)) {
    $failLog = "DETTAGLI FAIL:\n" . implode("\n", $failDetails) . "\n";
    echo $failLog;
    file_put_contents($logFile, $failLog, FILE_APPEND);
}


