<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2> Test Invio Push Manuale</h2>";

// Includi il file che invia i push
require_once __DIR__ . '/send_push_novendor.php';

echo "<p>✅ Script eseguito!</p>";
echo "<p>Controlla il file <strong>push_execution.log</strong> per i dettagli.</p>";
?>
