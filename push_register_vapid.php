<?php
date_default_timezone_set('Europe/Rome');
/**
 * Registrazione/gestione iscrizioni Web Push (VAPID) per una singola app.
 * Archivio: SQLite locale (push_subscriptions.db) per privacy per-app.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
error_reporting(E_ALL);
ini_set('display_errors', '1');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$configPath = dirname(__DIR__) . '/config/vapid.json';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => "Config vapid.json non trovato ($configPath)"]);
    exit;
}
$config = json_decode(file_get_contents($configPath), true);
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config vapid.json non valido']);
    exit;
}

$appId = $config['app_id'] ?? 'APP';
$dbFile = __DIR__ . '/' . ($config['db_file'] ?? 'push_subscriptions.db');

// Inizializza DB
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crea tabella se non esiste
    $db->exec("
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

    // Migra eventuale tabella senza colonna id/app_id: ricrea da zero se lo schema è diverso
    $cols = $db->query("PRAGMA table_info(subscriptions)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(fn($c) => $c['name'], $cols);
    $schemaOk = in_array('id', $colNames, true) && in_array('app_id', $colNames, true);
    if (!$schemaOk) {
        // Drop tabella vecchia e ricrea con schema corretto
        $db->exec("DROP TABLE IF EXISTS subscriptions");
        $db->exec("
        CREATE TABLE subscriptions (
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
    } else {
        // Aggiungi colonna user_id se manca
        if (!in_array('user_id', $colNames, true)) {
            $db->exec("ALTER TABLE subscriptions ADD COLUMN user_id TEXT");
        }
        if (!in_array('telefono', $colNames, true)) {
            $db->exec("ALTER TABLE subscriptions ADD COLUMN telefono TEXT");
        }
        if (!in_array('nome_cliente', $colNames, true)) {
            $db->exec("ALTER TABLE subscriptions ADD COLUMN nome_cliente TEXT");
        }
        if (!in_array('data_registrazione', $colNames, true)) {
            $db->exec("ALTER TABLE subscriptions ADD COLUMN data_registrazione DATETIME");
        }
        if (!in_array('ultimo_invio', $colNames, true)) {
            $db->exec("ALTER TABLE subscriptions ADD COLUMN ultimo_invio DATETIME");
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore DB: ' . $e->getMessage(), 'dbFile' => $dbFile]);
    exit;
}

function json_out($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->prepare('SELECT id, endpoint, created_at FROM subscriptions WHERE app_id = :app_id ORDER BY id DESC');
    $stmt->execute(['app_id' => $appId]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggiungi timestamp locale per chiarezza
    foreach ($subs as &$s) {
        $s['created_at_local'] = date('Y-m-d H:i:s', strtotime($s['created_at']));
    }
    unset($s);

    json_out(['success' => true, 'app_id' => $appId, 'subscriptions' => $subs]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $sub = $body['subscription'] ?? null;
    // user_id deve essere il codice di attivazione passato dal client
    $userId = isset($body['user_id']) ? trim((string)$body['user_id']) : '';
    if ($userId === '' && isset($_POST['user_id'])) {
        $userId = trim((string)$_POST['user_id']);
    }
    $telefono = isset($body['telefono']) ? trim((string)$body['telefono']) : '';
    if ($telefono === '' && isset($_POST['telefono'])) {
        $telefono = trim((string)$_POST['telefono']);
    }
    $nomeCliente = isset($body['nome_cliente']) ? trim((string)$body['nome_cliente']) : '';
    if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        json_out(['success' => false, 'error' => 'Payload non valido'], 400);
    }

    // Prova a recuperare telefono/nome dal file codici_attivazione.json se non passati
    $codiciPath = __DIR__ . '/codici_attivazione.json';
    if ($userId !== '' && file_exists($codiciPath)) {
        $codiciData = json_decode(file_get_contents($codiciPath), true);
        if (isset($codiciData[$userId]) && is_array($codiciData[$userId])) {
            $entry = $codiciData[$userId];
            if ($telefono === '' && !empty($entry['telefono'])) {
                $telefono = $entry['telefono'];
            }
            if ($nomeCliente === '' && !empty($entry['nome_cliente'])) {
                $nomeCliente = $entry['nome_cliente'];
            }
        }
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Se esiste già l'endpoint, aggiorna user_id/telefono invece di reinserire
    $existingStmt = $db->prepare('SELECT id FROM subscriptions WHERE endpoint = :endpoint LIMIT 1');
    $existingStmt->execute(['endpoint' => $sub['endpoint']]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $upd = $db->prepare('UPDATE subscriptions SET user_id = :user_id, telefono = :telefono, nome_cliente = :nome_cliente WHERE endpoint = :endpoint');
        $upd->execute([
            'user_id' => $userId !== '' ? $userId : null,
            'telefono' => $telefono !== '' ? $telefono : null,
            'nome_cliente' => $nomeCliente !== '' ? $nomeCliente : null,
            'endpoint' => $sub['endpoint']
        ]);
    } else {
        if ($userId !== '') {
            $cleanup = $db->prepare('DELETE FROM subscriptions WHERE user_id = :user_id');
            $cleanup->execute(['user_id' => $userId]);
        }
        $stmt = $db->prepare('INSERT INTO subscriptions (app_id, endpoint, p256dh, auth, user_id, telefono, nome_cliente, data_registrazione, ultimo_invio, user_agent) VALUES (:app_id, :endpoint, :p256dh, :auth, :user_id, :telefono, :nome_cliente, :data_registrazione, :ultimo_invio, :ua)');
        $stmt->execute([
            'app_id' => $appId,
            'endpoint' => $sub['endpoint'],
            'p256dh' => $sub['keys']['p256dh'],
            'auth' => $sub['keys']['auth'],
            'user_id' => $userId !== '' ? $userId : null,
            'telefono' => $telefono !== '' ? $telefono : null,
            'nome_cliente' => $nomeCliente !== '' ? $nomeCliente : null,
            'data_registrazione' => date('Y-m-d H:i:s'),
            'ultimo_invio' => null,
            'ua' => $ua
        ]);
    }

    json_out([
        'success' => true,
        'app_id' => $appId,
        'endpoint' => $sub['endpoint'],
        'user_id' => $userId !== '' ? $userId : null,
        'telefono' => $telefono !== '' ? $telefono : null,
        'nome_cliente' => $nomeCliente !== '' ? $nomeCliente : null
    ]);
}

// if ($method === 'DELETE') {
//     $body = json_decode(file_get_contents('php://input'), true);
//     $endpoint = $body['endpoint'] ?? '';
//     if (empty($endpoint)) {
//         json_out(['success' => false, 'error' => 'Endpoint mancante'], 400);
//     }
//     $stmt = $db->prepare('DELETE FROM subscriptions WHERE app_id = :app_id AND endpoint = :endpoint');
//     $stmt->execute(['app_id' => $appId, 'endpoint' => $endpoint]);
//     json_out(['success' => true, 'removed' => $stmt->rowCount()]);
// }

json_out(['success' => false, 'error' => 'Metodo non supportato'], 405);

