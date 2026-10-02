<?php
/**
 * Cinepulse Live Production Sync & Deploy Helper
 * Accessing this script forces git pull and OPcache reset on the live server.
 */
header('Content-Type: text/plain');

echo "=== Cinepulse Server Auto-Sync ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n";

$cmd = "cd " . escapeshellarg(dirname(__DIR__)) . " && git pull origin main 2>&1";
$output = [];
$returnVal = 0;
exec($cmd, $output, $returnVal);

echo "\nCommand: {$cmd}\n";
echo "Return Code: {$returnVal}\n";
echo "Output:\n" . implode("\n", $output) . "\n\n";

if (function_exists('opcache_reset')) {
    @opcache_reset();
    echo "OPcache flushed successfully.\n";
}

echo "=== Sync Finished ===\n";
