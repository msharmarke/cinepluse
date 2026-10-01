<?php
require_once __DIR__ . '/../src/Autoloader.php';

use Cinepulse\Database;

try {
    $pdo = Database::getInstance()->getConnection();
    
    $tracked = $pdo->query("SELECT * FROM tracked_showtimes ORDER BY id DESC")->fetchAll();
    $movieTrackers = [];
    try {
        $movieTrackers = $pdo->query("SELECT * FROM movie_release_trackers ORDER BY id DESC")->fetchAll();
    } catch (\Exception $e) {}
    
    $snapshots = $pdo->query("SELECT COUNT(*) as cnt FROM showtime_snapshots_history")->fetch()['cnt'];

    echo "=== RECAP REPORT OF CURRENT TRACKERS ===\n\n";
    echo "1. TRACKED SHOWTIMES (ACTIVE MONITORS): " . count($tracked) . " total\n";
    foreach ($tracked as $t) {
        echo "   • [ID #" . $t['id'] . "] " . $t['movie_name'] . " @ " . $t['theatre_name'] . "\n";
        echo "     Show Start: " . $t['show_start_time'] . " | Status: " . strtoupper($t['status']) . " | Showtime ID: " . $t['showtime_id'] . "\n";
    }
    
    echo "\n2. AUTOMATIC MOVIE RELEASE TRACKERS: " . count($movieTrackers) . " total\n";
    foreach ($movieTrackers as $mt) {
        echo "   • [ID #" . $mt['id'] . "] " . $mt['movie_name'] . " @ " . $mt['theatre_name'] . "\n";
        echo "     Filter: " . ($mt['experience_filter'] ?? 'All Formats') . " | Status: " . strtoupper($mt['status']) . "\n";
    }

    echo "\n3. TOTAL SNAPSHOTS LOGGED: " . $snapshots . "\n";

} catch (\Exception $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}
