<?php
// Visualizza il contenuto di push_subscriptions.db
error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbPath = __DIR__ . '/push_subscriptions.db';
$rows = [];
$error = null;

try {
    if (!file_exists($dbPath)) {
        throw new Exception("Database non trovato: $dbPath");
    }
    $pdo = new PDO('sqlite:' . $dbPath);
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
    $stmt = $pdo->query("SELECT id, app_id, user_id, telefono, endpoint, created_at FROM subscriptions ORDER BY id DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>DB Subscription</title>
<style>
body { background: #111; color: #9f9; font-family: Consolas, 'Courier New', monospace; padding: 20px; }
table { width: 100%; border-collapse: collapse; margin-top: 15px; }
th, td { border: 1px solid #333; padding: 8px; text-align: left; }
th { background: #222; color: #6f6; }
tr:nth-child(even) { background: #181818; }
.error { color: #f66; font-weight: bold; }
.warn { color: #ff0; font-weight: bold; }
</style>
</head>
<body>
<h2>push_subscriptions.db</h2>
<?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php else: ?>
    <div>Subscription trovate: <?php echo count($rows); ?></div>
    <?php if (!count($rows)): ?>
        <div class="error">DATABASE VUOTO - Nessuna subscription</div>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>App ID</th>
                <th>User ID</th>
                <th>Telefono</th>
                <th>Endpoint (50)</th>
                <th>Created</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($r['app_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($r['user_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($r['telefono'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(substr($r['endpoint'], 0, 50)) . '...'; ?></td>
                    <td><?php echo htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
