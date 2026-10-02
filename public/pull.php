<?php
/**
 * Cinepulse — Instant Git Sync Helper
 */
header('Content-Type: text/plain');

echo "=== Cinepulse Server Sync ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$output = [];
$returnCode = 0;
exec('git pull origin main 2>&1', $output, $returnCode);

echo implode("\n", $output) . "\n\n";
echo "Git pull exit code: " . $returnCode . "\n";

if (function_exists('opcache_reset')) {
    @opcache_reset();
    echo "OPcache reset executed.\n";
}

echo "=== Sync Complete ===\n";
