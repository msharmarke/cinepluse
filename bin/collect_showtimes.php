<?php
/**
 * Cinepulse CLI Cron Task — Schedule Scraper
 * Pre-caches theatrical week schedules (Friday through Thursday) for theaters in locations.json.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be executed via CLI.\n");
}

// Force America/Toronto timezone
date_default_timezone_set('America/Toronto');

// Include Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\CineplexAPI;

// Determine theatrical week start (Friday) and end (Thursday)
if (isset($argv[1]) && strtotime($argv[1])) {
    $startFridaySec = strtotime($argv[1]);
} else {
    $todaySec = strtotime('today');
    $dayOfWeek = (int)date('N', $todaySec); // 1 = Monday, 5 = Friday, 7 = Sunday
    if ($dayOfWeek === 5) {
        $startFridaySec = $todaySec;
    } else {
        $startFridaySec = strtotime('next Friday', $todaySec);
    }
}

$startFridayStr = date('Y-m-d', $startFridaySec);
$endThursdayStr = date('Y-m-d', strtotime('+6 days', $startFridaySec));

echo "[" . date('Y-m-d H:i:s') . "] Starting theatrical week schedule pre-cache pipeline ({$startFridayStr} to {$endThursdayStr})...\n";

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

// Fetch schedules for the 7 days of the theatrical week (Friday -> Thursday)
for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
    $currentDate = date('Y-m-d', strtotime("+{$dayOffset} days", $startFridaySec));
    $cineplexDate = date('m+d+Y', strtotime($currentDate));
    $dayName = date('l', strtotime($currentDate));
    
    echo "Processing Date: {$currentDate} ({$dayName})\n";
    
    foreach ($locations as $name => $id) {
        echo "  -> Pre-caching schedule for: {$name} (ID: {$id})... ";
        
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
        usleep(400000); // 400ms pause
    }
}

// After caching schedules, scan for new showtimes matching active movie release trackers
echo "[" . date('Y-m-d H:i:s') . "] Pre-caching complete for theatrical week {$startFridayStr} to {$endThursdayStr}. Successes: {$successCount}, Failures: {$failCount}.\n";
echo "Scanning schedules for movie release tracker rules...\n";

try {
    $trackerService = new Cinepulse\TrackerService();
    $totalRegistered = $trackerService->scanAndRegisterForAllMovieTrackers();
    echo "SUCCESS! Auto-registered {$totalRegistered} new matching showtimes from cached schedules.\n";
} catch (Exception $e) {
    echo "WARNING: Movie release tracker scan skipped/failed: " . $e->getMessage() . "\n";
}
