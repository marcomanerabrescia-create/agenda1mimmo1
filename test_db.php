<?php
header('Content-Type: text/html; charset=utf-8');

$dbPath = __DIR__ . '/push_subscriptions.db';

echo "<h2> Verifica Database Push Subscriptions</h2>";

if (!file_exists($dbPath)) {
    echo "<p style='color:red'>❌ Database non esiste: $dbPath</p>";
    exit;
}

echo "<p>✅ Database esiste: $dbPath</p>";
echo "<p> Dimensione: " . filesize($dbPath) . " bytes</p>";

try {
    $db = new SQLite3($dbPath);
    
    $result = $db->query("SELECT COUNT(*) as count FROM subscriptions");
    $row = $result->fetchArray();
    $count = $row['count'];
    
    echo "<h3> Totale subscriptions: <strong>$count</strong></h3>";
    
    if ($count > 0) {
        echo "<h3> Lista subscriptions:</h3>";
        $result = $db->query("SELECT * FROM subscriptions ORDER BY id DESC");
        
        echo "<table border='1' cellpadding='10' style='border-collapse:collapse'>";
        echo "<tr><th>ID</th><th>Endpoint (primi 50 char)</th><th>Created At</th></tr>";
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . substr($row['endpoint'], 0, 50) . "...</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:orange'>⚠️ Nessuna subscription nel database!</p>";
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Errore: " . $e->getMessage() . "</p>";
}
?>
