<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$baseDir = __DIR__;
$subscriptionDb = $baseDir . '/push_subscriptions.db';
$serviceWorkerUrl = '/app/ristorantedamimmo/PRENOTAZIONI/sw-toilet-001.js';
$registerEndpoint = '/app/ristorantedamimmo/PRENOTAZIONI/push_register_vapid.php';

header('Content-Type: text/plain; charset=utf-8');
echo "Service worker (registered from subscribe_push.html):\n";
echo "  JavaScript URL: https://www.consulenticaniegatti.com{$serviceWorkerUrl}\n";
echo "  Scope: /app/ristorantedamimmo/PRENOTAZIONI/\n\n";

echo "Server-side registration POST target:\n";
echo "  push_register_vapid.php at https://www.consulenticaniegatti.com{$registerEndpoint}\n\n";

echo "Subscription database path (used by send_push_* and cron):\n";
echo "  {$subscriptionDb}\n";

if (!file_exists($subscriptionDb)) {
    echo "  -> File non trovato, forse la copia remota ha percorso diverso.\n";
    exit;
}

$db = new SQLite3($subscriptionDb);
$columnsResult = $db->query('PRAGMA table_info(subscriptions)');
echo "\nSubscription table schema:\n";
while ($col = $columnsResult->fetchArray(SQLITE3_ASSOC)) {
    echo sprintf(
        "  %s %s%s%s\n",
        $col['name'],
        $col['type'],
        $col['notnull'] ? ' NOT NULL' : '',
        $col['dflt_value'] !== null ? ' DEFAULT ' . $col['dflt_value'] : ''
    );
}

$count = $db->querySingle('SELECT COUNT(*) FROM subscriptions');
echo "\nTotal subscriptions: {$count}\n";

if ($count > 0) {
    echo "\nSample endpoints (first 3):\n";
    $stmt = $db->prepare('SELECT id, user_id, telefono, created_at FROM subscriptions ORDER BY id DESC LIMIT 3');
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        echo sprintf(
            "  #%d user_id=%s telefono=%s created=%s\n",
            $row['id'],
            $row['user_id'] ?: 'NULL',
            $row['telefono'] ?: 'NULL',
            $row['created_at'] ?: 'NULL'
        );
    }
}

$db->close();
