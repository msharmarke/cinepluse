<?php
/**
 * Cinepulse CLI Cron Task — Schedule Scraper
 * Pre-caches upcoming schedules for theaters in locations.json for the next 7 days.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be executed via CLI.\n");
}

// Force America/Toronto timezone
date_default_timezone_set('America/Toronto');

// Include Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\CineplexAPI;

echo "[" . date('Y-m-d H:i:s') . "] Starting weekly schedule pre-cache pipeline...\n";

// Load location mappings
$locFile = dirname(__DIR__) . '/config/locations.json';
if (!file_exists($locFile)) {
    die("Error: locations.json configuration is missing.\n");
}

$locations = json_decode(file_get_contents($locFile), true) ?: [];
if (empty($locations)) {
    die("Error: No theatres found inside locations.json.\n");
}

try {
    $api = new CineplexAPI();
} catch (Exception $e) {
    die("API Setup Error: " . $e->getMessage() . "\n");
}

$successCount = 0;
$failCount = 0;

// Fetch schedules for the next 7 days
for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
    $currentDate = date('Y-m-d', strtotime("+$dayOffset days"));
    $cineplexDate = date('m+d+Y', strtotime($currentDate));
    
    echo "Processing Date: $currentDate\n";
    
    foreach ($locations as $name => $id) {
        echo "  -> Pre-caching schedule for: $name (ID: $id)... ";
        
        // Force refresh to download latest schedules
        $data = $api->fetchShowtimes($id, $cineplexDate, true);
        
        if (isset($data['error'])) {
            echo "FAILED (" . $data['error'] . ")\n";
            $failCount++;
        } else {
            $movies = $data[0]['dates'][0]['movies'] ?? [];
            echo "SUCCESS (" . count($movies) . " movies listed)\n";
            $successCount++;
        }
        
        // Sleep to respect API rate limits
        usleep(500000); // 500ms pause
    }
}

// After caching schedules, scan for new showtimes matching active movie release trackers
echo "[" . date('Y-m-d H:i:s') . "] Pre-caching complete. Successes: $successCount, Failures: $failCount.\n";
echo "Scanning schedules for movie release tracker rules...\n";

try {
    // Autoload TrackerService
    $trackerService = new Cinepulse\TrackerService();
    $totalRegistered = $trackerService->scanAndRegisterForAllMovieTrackers();
    echo "SUCCESS! Auto-registered $totalRegistered new matching showtimes from cached schedules.\n";
} catch (Exception $e) {
    echo "WARNING: Movie release tracker scan skipped/failed: " . $e->getMessage() . "\n";
}

