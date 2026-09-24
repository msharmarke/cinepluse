<?php
/**
 * Cinepulse CLI Cron Task — Showtime Occupancy Tracker Daemon
 * Iterates through active monitors, logs seat snapshots, sends alerts,
 * and purges stale caches.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be executed via CLI.\n");
}

// Force America/Toronto timezone
date_default_timezone_set('America/Toronto');

// Include Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Database;
use Cinepulse\TrackerService;

echo "[" . date('Y-m-d H:i:s') . "] Starting active occupancy monitoring loop...\n";

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage() . "\n");
}

// 1. Fetch active trackers
$stmt = $db->prepare("SELECT * FROM tracked_showtimes WHERE status = 'active'");
$stmt->execute();
$activeTrackers = $stmt->fetchAll();

if (empty($activeTrackers)) {
    echo "No active showtime monitors running. Processing cache garbage collection...\n";
    $purged = TrackerService::purgeExpiredCache();
    echo "Purged $purged expired cache files.\n";
    exit;
}

$trackerService = new TrackerService();
$now = new DateTime('now', new DateTimeZone('America/Toronto'));

foreach ($activeTrackers as $tracker) {
    $trackerId = $tracker['id'];
    $movieName = $tracker['movie_name'];
    $showStartTime = new DateTime($tracker['show_start_time'], new DateTimeZone('America/Toronto'));
    
    echo "Processing Tracker ID {$trackerId}: \"{$movieName}\" (Starts: {$tracker['show_start_time']})\n";
    
    // Check if show start time has passed (concludes monitoring 3 hours after start)
    $concludeTime = clone $showStartTime;
    $concludeTime->modify('+3 hours');
    
    if ($now > $concludeTime) {
        echo "  -> Showtime has concluded. Marking tracker as completed.\n";
        $stmt_done = $db->prepare("UPDATE tracked_showtimes SET status = 'completed' WHERE id = ?");
        $stmt_done->execute([$trackerId]);
        continue;
    }
    
    // Log new snapshot
    try {
        echo "  -> Fetching layout and logging snapshot...\n";
        $snapInfo = $trackerService->logSnapshot($trackerId);
        echo "     SUCCESS! Occupancy: {$snapInfo['occupancy_percentage']}% (Occupied: {$snapInfo['seats_occupied']}, Available: {$snapInfo['seats_available']})\n";
        
        // --- OPTIONAL OCCUPANCY ALERT PIPELINE ---
        $threshold = 75.00; // Define warning threshold limit
        if ($snapInfo['occupancy_percentage'] >= $threshold) {
            checkAndSendAlerts($db, $tracker, $snapInfo, $threshold);
        }
    } catch (Exception $e) {
        echo "     FAILED (" . $e->getMessage() . ")\n";
    }
}

// 2. Perform cache garbage collection
echo "Executing cache garbage collection...\n";
$purged = TrackerService::purgeExpiredCache();
echo "Purged $purged expired cache files.\n";

echo "[" . date('Y-m-d H:i:s') . "] Occupancy tracking loop complete.\n";

/**
 * Handle notification checks and email/social media transmissions
 */
function checkAndSendAlerts($db, $tracker, $snapInfo, $threshold) {
    $trackerId = $tracker['id'];
    $showtimeId = $tracker['showtime_id'];
    $theatreId = $tracker['theatre_id'];
    $startTime = $tracker['show_start_time'];
    $showDate = date('Y-m-d', strtotime($startTime));
    
    // 1. Check if email alert was already sent for this threshold
    $stmt_chk = $db->prepare("SELECT id FROM email_notifications_sent WHERE theatre_id = ? AND showtime_id = ? AND threshold = ?");
    $stmt_chk->execute([$theatreId, $showtimeId, $threshold]);
    $sent = $stmt_chk->fetch();
    
    if (!$sent) {
        echo "     [ALERT] Occupancy exceeds {$threshold}%. Preparing alert email...\n";
        
        // Mock email sending log (loads credentials from config.ini in production)
        $subject = "Cinepulse Alert: High Occupancy on {$tracker['movie_name']}";
        $body = "Occupancy for {$tracker['movie_name']} at {$tracker['theatre_name']} starting at {$startTime} has reached {$snapInfo['occupancy_percentage']}%.\n\nSeats Occupied: {$snapInfo['seats_occupied']}\nSeats Available: {$snapInfo['seats_available']}";
        
        // Log in database to prevent double transmission
        $stmt_log = $db->prepare("INSERT INTO email_notifications_sent (theatre_id, showtime_id, show_start_time, threshold, occupancy_percentage) VALUES (?, ?, ?, ?, ?)");
        $stmt_log->execute([$theatreId, $showtimeId, $startTime, $threshold, $snapInfo['occupancy_percentage']]);
        
        echo "     [ALERT] Email logged in notification tracker database.\n";
    }
}
