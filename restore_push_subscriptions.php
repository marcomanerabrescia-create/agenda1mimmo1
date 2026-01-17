<?php
/**
 * Restore the latest push_subscriptions backup.
 * Executing this via browser copies the newest file from backups/ over push_subscriptions.db.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

$base = __DIR__;
$db = "$base/push_subscriptions.db";
$backupDir = "$base/backups";

header('Content-Type: text/plain; charset=utf-8');
echo "Push subscriptions restore tool\n";

if (!is_dir($backupDir)) {
    echo "ERROR: backup directory missing ($backupDir)\n";
    exit(1);
}

$files = array_filter(scandir($backupDir), function ($file) use ($backupDir) {
    return is_file("$backupDir/$file") && preg_match('/^push_subscriptions_\d{8}_\d{6}\.db$/', $file);
});

if (!$files) {
    echo "ERROR: no backup files found.\n";
    exit(1);
}

rsort($files);
$latest = reset($files);
$source = "$backupDir/$latest";

if (!is_readable($source)) {
    echo "ERROR: cannot read latest backup ($source)\n";
    exit(1);
}

if (!copy($source, $db)) {
    echo "ERROR: unable to copy backup to $db\n";
    exit(1);
}

echo "Restored backup: $latest\n";
echo "Destination: $db\n";
echo "Done.\n";
