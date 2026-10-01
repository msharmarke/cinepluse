<?php
require_once __DIR__ . '/../src/Autoloader.php';

$archService = new Cinepulse\ArchiveService();
try {
    $res = $archService->importArchive('archive_2026-09-05_173445');
    print_r($res);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
