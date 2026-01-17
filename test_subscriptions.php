<?php
/**
 * Elenco subscription push (debug)
 */
date_default_timezone_set('Europe/Rome');
header('Content-Type: text/html; charset=utf-8');

$dbFile = __DIR__ . '/push_subscriptions.db';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!file_exists($dbFile)) {
    echo "<h2>NESSUNA SUBSCRIPTION TROVATA (file mancante)</h2>";
    exit;
}

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
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt = $pdo->query("SELECT id, app_id, user_id, endpoint, created_at FROM subscriptions ORDER BY datetime(created_at) DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<h2>ERRORE DB: " . h($e->getMessage()) . "</h2>";
    exit;
}

$count = is_array($rows) ? count($rows) : 0;
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Subscriptions Push</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; background: #fff; }
    th, td { padding: 8px 10px; border: 1px solid #ddd; text-align: left; font-size: 14px; }
    th { background: #f0f0f0; }
    .muted { color: #666; }
  </style>
</head>
<body>
  <h1>Subscriptions Push</h1>
  <p>Totale: <strong><?php echo (int)$count; ?></strong></p>
  <?php if ($count === 0): ?>
    <h3>NESSUNA SUBSCRIPTION TROVATA</h3>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>User ID</th>
          <th>Endpoint (80ch)</th>
          <th>Creato</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo h($r['user_id']); ?></td>
            <td class="muted"><?php echo h(substr($r['endpoint'], 0, 80)); ?></td>
            <td><?php echo h($r['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
