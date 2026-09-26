<?php
/**
 * Cinepulse — AJAX API Endpoint Dispatcher
 * Dispatches async JSON requests for layouts, snapshots, stats, and monitors.
 */

header('Content-Type: application/json');

// Force America/Toronto timezone
date_default_timezone_set('America/Toronto');

// Initialize Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\TrackerService;

// Start session securely
Security::startSession();

// Setup shared utility functions
function get_tracker_theatres($activeOnly = false) {
    return \Cinepulse\ShowtimeService::getTrackerTheatres($activeOnly);
}

function get_detailed_theatres() {
    return \Cinepulse\ShowtimeService::getDetailedTheatres();
}

$action = Security::sanitizeInput($_GET['action'] ?? $_POST['action'] ?? null, 'string');

if (!$action) {
    http_response_code(400);
    echo json_encode(['error' => 'Action parameter is required.']);
    exit;
}

try {
    switch ($action) {
        // 1. Fetch live seat map layout and availability (Search browser)
        case 'fetch_live_seat_map':
            $theatreId = Security::sanitizeInput($_GET['theatre_id'] ?? null, 'int');
            $showtimeId = Security::sanitizeInput($_GET['showtime_id'] ?? null, 'string');
            
            if (!$theatreId || !$showtimeId) {
                http_response_code(400);
                echo json_encode(['error' => 'theatre_id and showtime_id parameters are required.']);
                exit;
            }
            
            $api = new CineplexAPI();
            $layoutUrl = "https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{$theatreId}/showtime/{$showtimeId}/seat-layout";
            $availUrl = "https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{$theatreId}/showtime/{$showtimeId}/seat-availability";
            
            $layout = $api->fetchCineplexAPI($layoutUrl);
            $availability = $api->fetchCineplexAPI($availUrl);
            
            if (isset($layout['error'])) {
                http_response_code(502);
                echo json_encode(['error' => 'Failed to retrieve layout from Cineplex: ' . $layout['error']]);
                exit;
            }
            if (isset($availability['error'])) {
                http_response_code(502);
                echo json_encode(['error' => 'Failed to retrieve availability from Cineplex: ' . $availability['error']]);
                exit;
            }
            
            echo json_encode([
                'layout' => $layout,
                'availability' => $availability['seatAvailabilities'] ?? []
            ]);
            break;

        // 1b. Fetch just the occupancy stats lazily
        case 'fetch_occupancy':
            $theatreId = Security::sanitizeInput($_GET['theatre_id'] ?? null, 'int');
            $showtimeId = Security::sanitizeInput($_GET['showtime_id'] ?? null, 'string');
            
            if (!$theatreId || !$showtimeId) {
                http_response_code(400);
                echo json_encode(['error' => 'theatre_id and showtime_id parameters are required.']);
                exit;
            }
            
            $api = new CineplexAPI();
            $availUrl = "https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{$theatreId}/showtime/{$showtimeId}/seat-availability";
            $availability = $api->fetchCineplexAPI($availUrl);
            
            if (isset($availability['error'])) {
                http_response_code(502);
                echo json_encode(['error' => 'Failed to retrieve availability']);
                exit;
            }
            
            $total = 0;
            $occupied = 0;
            if (isset($availability['seatAvailabilities'])) {
                foreach ($availability['seatAvailabilities'] as $seatId => $status) {
                    $total++;
                    if ($status === 'Occupied' || $status === 'Sold' || $status === 'Broken') {
                        $occupied++;
                    }
                }
            }
            
            $percentage = $total > 0 ? round(($occupied / $total) * 100) : 0;
            echo json_encode([
                'total' => $total,
                'occupied' => $occupied,
                'percentage' => $percentage
            ]);
            break;

        case 'check_movie_availability':
            $theatreId = Security::sanitizeInput($_GET['theatre_id'] ?? null, 'int');
            $date = Security::sanitizeInput($_GET['date'] ?? null, 'string');
            $movieName = Security::sanitizeInput($_GET['movie_name'] ?? null, 'string');
            
            if (!$theatreId || !$date || !$movieName) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing parameters.']);
                exit;
            }
            
            $api = new CineplexAPI();
            $cineplex_date = date('m+d+Y', strtotime($date));
            $raw_showtimes = $api->fetchShowtimes($theatreId, $cineplex_date);
            
            $is_playing = false;
            if (!isset($raw_showtimes['error']) && !empty($raw_showtimes[0]['dates'][0]['movies'])) {
                foreach ($raw_showtimes[0]['dates'][0]['movies'] as $movie) {
                    $name = $movie['name'] ?? $movie['title'] ?? '';
                    if ($name === $movieName) {
                        $is_playing = true;
                        break;
                    }
                }
            }
            
            echo json_encode(['is_playing' => $is_playing, 'theatre_id' => $theatreId]);
            break;

        // 2. Add showtime to tracker list (POST)
        case 'add_tracker':
            Security::verifyCsrfOrDie();
            
            $theatreId = Security::sanitizeInput($_POST['theatre_id'] ?? null, 'int');
            $theatreName = Security::sanitizeInput($_POST['theatre_name'] ?? null, 'string');
            $showtimeId = Security::sanitizeInput($_POST['showtime_id'] ?? null, 'string');
            $movieName = Security::sanitizeInput($_POST['movie_name'] ?? null, 'string');
            $showStartTime = Security::sanitizeInput($_POST['show_start_time'] ?? null, 'string');
            
            if (!$theatreId || !$theatreName || !$showtimeId || !$movieName || !$showStartTime) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required tracker parameters.']);
                exit;
            }
            
            // Format start time into MySQL DATETIME
            $timestamp = strtotime($showStartTime);
            if ($timestamp === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid show start time format.']);
                exit;
            }
            $dbStartTime = date('Y-m-d H:i:s', $timestamp);
            
            $tracker = new TrackerService();
            $trackerId = $tracker->registerTracker($theatreId, $theatreName, $showtimeId, $movieName, $dbStartTime);
            
            echo json_encode([
                'success' => true,
                'message' => 'Showtime registered for active tracking.',
                'tracker_id' => $trackerId
            ]);
            break;

        // 2b. Add automatic movie tracker (POST)
        case 'add_movie_tracker':
            Security::verifyCsrfOrDie();
            
            $movieName = Security::sanitizeInput($_POST['movie_name'] ?? null, 'string');
            $experienceFilter = Security::sanitizeInput($_POST['experience_filter'] ?? null, 'string');
            $theatreId = Security::sanitizeInput($_POST['theatre_id'] ?? null, 'int');
            $startDate = Security::sanitizeInput($_POST['start_date'] ?? null, 'string');
            $endDate = Security::sanitizeInput($_POST['end_date'] ?? null, 'string');
            
            if (!$movieName || !$theatreId) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing movie_name or theatre_id.']);
                exit;
            }
            
            // Get theatre name
            $theatres = get_tracker_theatres();
            $theatreName = array_search($theatreId, $theatres);
            if (!$theatreName) {
                $theatreName = 'Theatre ID ' . $theatreId;
            }
            
            $trackerService = new TrackerService();
            $trackerId = $trackerService->registerMovieTracker($movieName, $experienceFilter, $theatreId, $theatreName, $startDate, $endDate);
            
            // Re-fetch to get matched sessions count
            $stmt = Cinepulse\Database::getInstance()->getConnection()->prepare("SELECT * FROM movie_release_trackers WHERE id = ?");
            $stmt->execute([$trackerId]);
            $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
            $matchedCount = 0;
            if ($tracker) {
                $matchedCount = $trackerService->scanAndRegisterForMovieTracker($tracker);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Automatic movie tracker registered successfully.',
                'matched_count' => $matchedCount,
                'tracker_id' => $trackerId
            ]);
            break;

        // 2c. Delete automatic movie tracker (POST)
        case 'delete_movie_tracker':
            Security::verifyCsrfOrDie();
            
            $trackerId = Security::sanitizeInput($_POST['tracker_id'] ?? null, 'int');
            if (!$trackerId) {
                http_response_code(400);
                echo json_encode(['error' => 'tracker_id parameter is required.']);
                exit;
            }
            
            $trackerService = new TrackerService();
            $trackerService->deleteMovieTracker($trackerId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Automatic movie tracker deleted successfully.'
            ]);
            break;

        // 2d. Toggle automatic movie tracker status (POST)
        case 'toggle_movie_tracker':
            Security::verifyCsrfOrDie();
            
            $trackerId = Security::sanitizeInput($_POST['tracker_id'] ?? null, 'int');
            $status = Security::sanitizeInput($_POST['status'] ?? null, 'string');
            
            if (!$trackerId || !$status) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing tracker_id or status.']);
                exit;
            }
            
            $trackerService = new TrackerService();
            $trackerService->toggleMovieTrackerStatus($trackerId, $status);
            
            echo json_encode([
                'success' => true,
                'message' => 'Movie tracker status updated to ' . $status . '.'
            ]);
            break;

        // 2e. Trigger manual scan for a movie tracker (POST)
        case 'scan_movie_tracker':
            Security::verifyCsrfOrDie();
            
            $trackerId = Security::sanitizeInput($_POST['tracker_id'] ?? null, 'int');
            if (!$trackerId) {
                http_response_code(400);
                echo json_encode(['error' => 'tracker_id parameter is required.']);
                exit;
            }
            
            $db = Cinepulse\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM movie_release_trackers WHERE id = ?");
            $stmt->execute([$trackerId]);
            $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tracker) {
                http_response_code(404);
                echo json_encode(['error' => 'Movie tracker not found.']);
                exit;
            }
            
            $trackerService = new TrackerService();
            $matchedCount = $trackerService->scanAndRegisterForMovieTracker($tracker);
            
            echo json_encode([
                'success' => true,
                'message' => "Scan complete. Registered {$matchedCount} new matching showtimes."
            ]);
            break;

        // 3. Delete showtime tracker and log files (POST)
        case 'delete_tracker':
            Security::verifyCsrfOrDie();
            
            $trackerIds = $_POST['tracker_ids'] ?? $_POST['tracker_id'] ?? null;
            if (!$trackerIds) {
                http_response_code(400);
                echo json_encode(['error' => 'tracker_id or tracker_ids parameter is required.']);
                exit;
            }
            
            $tracker = new TrackerService();
            if (is_array($trackerIds)) {
                $ids = array_map('intval', $trackerIds);
            } else {
                $ids = array_map('intval', explode(',', $trackerIds));
            }
            
            $deletedCount = 0;
            foreach ($ids as $id) {
                if ($id > 0) {
                    $tracker->deleteTracker($id);
                    $deletedCount++;
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "Successfully deleted {$deletedCount} tracker session(s) and history logs."
            ]);
            break;

        // 4. Manual trigger snapshot check (POST)
        case 'trigger_snapshot':
            Security::verifyCsrfOrDie();
            
            $trackerIds = $_POST['tracker_ids'] ?? $_POST['tracker_id'] ?? null;
            if (!$trackerIds) {
                http_response_code(400);
                echo json_encode(['error' => 'tracker_id or tracker_ids parameter is required.']);
                exit;
            }
            
            $tracker = new TrackerService();
            if (is_array($trackerIds)) {
                $ids = array_map('intval', $trackerIds);
            } else {
                $ids = array_map('intval', explode(',', $trackerIds));
            }
            
            $count = 0;
            $lastInfo = null;
            foreach ($ids as $id) {
                if ($id > 0) {
                    try {
                        $lastInfo = $tracker->logSnapshot($id);
                        $count++;
                    } catch (Exception $e) {
                        error_log("Snapshot trigger error for tracker {$id}: " . $e->getMessage());
                    }
                }
            }
            
            $msg = ($count === 1 && $lastInfo) 
                ? "Manual snapshot logged successfully. (Occupancy: {$lastInfo['occupancy_percentage']}%)" 
                : "Captured manual snapshots for {$count} showtime monitor(s).";
                
            echo json_encode([
                'success' => true,
                'message' => $msg,
                'snapshot_info' => $lastInfo,
                'count' => $count
            ]);
            break;

        case 'trigger_all_snapshots':
            Security::verifyCsrfOrDie();
            
            $tracker = new TrackerService();
            $count = $tracker->logSnapshotForAllActiveTrackers();
            
            echo json_encode([
                'success' => true,
                'message' => "Captured manual snapshots for {$count} active showtime monitor(s)."
            ]);
            break;

        // 5. Fetch statistics log history list for a tracker
        case 'get_history':
            $trackerId = Security::sanitizeInput($_GET['tracker_id'] ?? null, 'int');
            if (!$trackerId) {
                http_response_code(400);
                echo json_encode(['error' => 'tracker_id parameter is required.']);
                exit;
            }
            
            $db = Cinepulse\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM showtime_snapshots_history WHERE tracked_showtime_id = ? ORDER BY snapshot_time DESC");
            $stmt->execute([$trackerId]);
            
            // Enforce associative array mappings to prevent data duplication over the wire
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['history' => $history]);
            break;

        // 6. Load a flat snapshot seatmap file securely
        case 'get_snapshot_seatmap':
            $filename = Security::sanitizeInput($_GET['filename'] ?? null, 'string');
            if (!$filename) {
                http_response_code(400);
                echo json_encode(['error' => 'filename parameter is required.']);
                exit;
            }
            
            // Fixed range operator tracking issue by moving the hyphen to the end of the character collection class
            if (!preg_match('/^seatmap_[0-9]+_[0-9_-]+\.json$/', $filename)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied: Invalid filename structure.']);
                exit;
            }
            
            $filepath = dirname(__DIR__) . '/snapshots/' . $filename;
            if (!file_exists($filepath)) {
                http_response_code(404);
                echo json_encode(['error' => 'Snapshot seatmap file not found.']);
                exit;
            }
            
            // Read and output flat JSON content
            readfile($filepath);
            break;

        case 'clear_release_alerts':
            Security::verifyCsrfOrDie();
            
            $trackerService = new TrackerService();
            $trackerService->clearReleaseAlerts();
            
            echo json_encode([
                'success' => true,
                'message' => 'Showtime release alerts cleared successfully.'
            ]);
            break;

        case 'clear_scan_logs':
            Security::verifyCsrfOrDie();
            
            $trackerService = new TrackerService();
            $trackerService->clearScanLogs();
            
            echo json_encode([
                'success' => true,
                'message' => 'Scraper execution scan logs cleared successfully.'
            ]);
            break;

        case 'get_dashboard_analytics':
            $dateFrom = Security::sanitizeInput($_GET['date_from'] ?? null, 'string');
            $dateTo = Security::sanitizeInput($_GET['date_to'] ?? null, 'string');

            $dashService = new Cinepulse\DashboardService();
            $metrics = $dashService->getSystemMetrics($dateFrom, $dateTo);
            $analytics = $dashService->getAnalyticsData($dateFrom, $dateTo);
            $status = $dashService->getDaemonStatus();

            echo json_encode([
                'success' => true,
                'metrics' => $metrics,
                'analytics' => $analytics,
                'status' => $status
            ]);
            break;

        case 'export_csv':
            $type = Security::sanitizeInput($_GET['type'] ?? 'occupancy', 'string');
            $dateFrom = Security::sanitizeInput($_GET['date_from'] ?? null, 'string');
            $dateTo = Security::sanitizeInput($_GET['date_to'] ?? null, 'string');

            $dashService = new Cinepulse\DashboardService();
            $dashService->exportCsv($type, $dateFrom, $dateTo);
            exit;

        case 'trigger_cron_collect':
            Security::verifyCsrfOrDie();
            $phpBin = PHP_BINARY ?: 'php';
            $script = dirname(__DIR__) . '/bin/collect_showtimes.php';
            
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen("start /B " . escapeshellarg($phpBin) . " " . escapeshellarg($script) . " > NUL 2>&1", "r"));
            } else {
                exec(escapeshellarg($phpBin) . " " . escapeshellarg($script) . " > /dev/null 2>&1 &");
            }

            echo json_encode([
                'success' => true,
                'message' => 'Weekly schedule pre-cacher task launched in background.'
            ]);
            break;

        case 'trigger_cron_track':
            Security::verifyCsrfOrDie();
            $phpBin = PHP_BINARY ?: 'php';
            $script = dirname(__DIR__) . '/bin/track_occupancy.php';
            
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen("start /B " . escapeshellarg($phpBin) . " " . escapeshellarg($script) . " > NUL 2>&1", "r"));
            } else {
                exec(escapeshellarg($phpBin) . " " . escapeshellarg($script) . " > /dev/null 2>&1 &");
            }

            echo json_encode([
                'success' => true,
                'message' => 'Occupancy tracking daemon task launched in background.'
            ]);
            break;

        case 'scrape_theatrical_week':
            Security::verifyCsrfOrDie();
            $startFriday = Security::sanitizeInput($_POST['start_friday'] ?? null, 'string');
            $dashService = new Cinepulse\DashboardService();
            $result = $dashService->scrapeFullTheatricalWeek($startFriday);
            echo json_encode($result);
            break;

        case 'get_weekly_schedule':
            $date = Security::sanitizeInput($_GET['date'] ?? $_POST['date'] ?? null, 'string');
            $theatreId = Security::sanitizeInput($_GET['theatre_id'] ?? $_POST['theatre_id'] ?? null, 'int');
            $search = Security::sanitizeInput($_GET['search'] ?? $_POST['search'] ?? null, 'string');
            $filter = Security::sanitizeInput($_GET['filter'] ?? $_POST['filter'] ?? 'all', 'string');

            $dashService = new Cinepulse\DashboardService();
            $res = $dashService->getWeeklySchedule($date, $theatreId, $search, $filter);
            echo json_encode($res);
            break;

        case 'list_archives':
            $archiveService = new Cinepulse\ArchiveService();
            $archives = $archiveService->listArchives();
            echo json_encode([
                'success' => true,
                'archives' => $archives,
                'total' => count($archives)
            ]);
            break;

        case 'create_archive':
            Security::verifyCsrfOrDie();
            $label = Security::sanitizeInput($_POST['label'] ?? '', 'string');
            $archiveService = new Cinepulse\ArchiveService();
            $res = $archiveService->createArchivePackage($label);
            echo json_encode($res);
            break;

        case 'get_archive_details':
            $archiveName = Security::sanitizeInput($_GET['archive_name'] ?? $_POST['archive_name'] ?? null, 'string');
            if (!$archiveName) {
                http_response_code(400);
                echo json_encode(['error' => 'archive_name parameter is required.']);
                exit;
            }

            $archiveService = new Cinepulse\ArchiveService();
            $res = $archiveService->getArchiveDetails($archiveName);
            echo json_encode($res);
            break;

        case 'import_archive':
            Security::verifyCsrfOrDie();
            $archiveName = Security::sanitizeInput($_POST['archive_name'] ?? null, 'string');
            if (!$archiveName) {
                http_response_code(400);
                echo json_encode(['error' => 'archive_name parameter is required.']);
                exit;
            }

            $archiveService = new Cinepulse\ArchiveService();
            $res = $archiveService->importArchive($archiveName);
            echo json_encode($res);
            break;

        case 'list_theatres':
            $theatres = get_detailed_theatres();
            $activeCount = count(array_filter($theatres, fn($t) => $t['enabled']));
            echo json_encode([
                'success' => true,
                'theatres' => $theatres,
                'total' => count($theatres),
                'active_count' => $activeCount,
                'disabled_count' => count($theatres) - $activeCount
            ]);
            break;

        case 'toggle_theatre':
            Security::verifyCsrfOrDie();
            $theatreId = Security::sanitizeInput($_POST['theatre_id'] ?? null, 'int');
            $enabledParam = $_POST['enabled'] ?? null;
            
            if (!$theatreId) {
                http_response_code(400);
                echo json_encode(['error' => 'theatre_id parameter is required.']);
                exit;
            }

            $locFile = dirname(__DIR__) . '/config/locations.json';
            if (!file_exists($locFile)) {
                http_response_code(500);
                echo json_encode(['error' => 'locations.json file missing.']);
                exit;
            }

            $locations = json_decode(file_get_contents($locFile), true) ?: [];
            $found = false;
            $newStatus = false;
            $targetName = '';

            foreach ($locations as $name => &$data) {
                if (is_array($data)) {
                    if ((int)($data['id'] ?? 0) === (int)$theatreId) {
                        if ($enabledParam !== null) {
                            $data['enabled'] = filter_var($enabledParam, FILTER_VALIDATE_BOOLEAN);
                        } else {
                            $data['enabled'] = !($data['enabled'] ?? true);
                        }
                        $newStatus = $data['enabled'];
                        $targetName = $name;
                        $found = true;
                        break;
                    }
                }
            }

            if ($found) {
                file_put_contents($locFile, json_encode($locations, JSON_PRETTY_PRINT));
                echo json_encode([
                    'success' => true,
                    'theatre_id' => $theatreId,
                    'name' => $targetName,
                    'enabled' => $newStatus,
                    'message' => "Theatre '{$targetName}' active monitoring status updated to " . ($newStatus ? 'ACTIVE' : 'PAUSED')
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Theatre ID not found in locations configuration.']);
            }
            break;

        case 'add_theatre':
            Security::verifyCsrfOrDie();
            $name = Security::sanitizeInput($_POST['name'] ?? null, 'string');
            $theatreId = Security::sanitizeInput($_POST['theatre_id'] ?? null, 'int');
            $city = Security::sanitizeInput($_POST['city'] ?? 'Unknown', 'string');
            $province = Security::sanitizeInput($_POST['province'] ?? 'ON', 'string');
            $region = Security::sanitizeInput($_POST['region'] ?? 'Canada', 'string');
            $screensStr = Security::sanitizeInput($_POST['screens'] ?? 'Standard', 'string');
            $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : true;

            if (!$name || !$theatreId) {
                http_response_code(400);
                echo json_encode(['error' => 'Theater Name and ID are required.']);
                exit;
            }

            $locFile = dirname(__DIR__) . '/config/locations.json';
            $locations = file_exists($locFile) ? (json_decode(file_get_contents($locFile), true) ?: []) : [];

            $screensArr = array_map('trim', explode(',', $screensStr));
            $screensArr = array_values(array_filter($screensArr));
            if (empty($screensArr)) $screensArr = ['Standard'];

            $locations[$name] = [
                'id' => (int)$theatreId,
                'city' => $city,
                'province' => strtoupper($province),
                'region' => $region,
                'screens' => $screensArr,
                'enabled' => $enabled
            ];

            file_put_contents($locFile, json_encode($locations, JSON_PRETTY_PRINT));
            echo json_encode([
                'success' => true,
                'message' => "Theater '{$name}' (ID #{$theatreId}) added to locations list successfully."
            ]);
            break;

        case 'edit_theatre':
            Security::verifyCsrfOrDie();
            $originalName = Security::sanitizeInput($_POST['original_name'] ?? null, 'string');
            $originalId = Security::sanitizeInput($_POST['original_id'] ?? null, 'int');
            $name = Security::sanitizeInput($_POST['name'] ?? null, 'string');
            $theatreId = Security::sanitizeInput($_POST['theatre_id'] ?? null, 'int');
            $city = Security::sanitizeInput($_POST['city'] ?? 'Unknown', 'string');
            $province = Security::sanitizeInput($_POST['province'] ?? 'ON', 'string');
            $region = Security::sanitizeInput($_POST['region'] ?? 'Canada', 'string');
            $screensStr = Security::sanitizeInput($_POST['screens'] ?? 'Standard', 'string');
            $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : true;

            if (!$name || !$theatreId) {
                http_response_code(400);
                echo json_encode(['error' => 'Theater Name and ID are required.']);
                exit;
            }

            $locFile = dirname(__DIR__) . '/config/locations.json';
            if (!file_exists($locFile)) {
                http_response_code(500);
                echo json_encode(['error' => 'locations.json file missing.']);
                exit;
            }

            $locations = json_decode(file_get_contents($locFile), true) ?: [];

            // Find key to replace/update
            $keyToUpdate = null;
            foreach ($locations as $k => $d) {
                $id = is_array($d) ? ($d['id'] ?? null) : $d;
                if (($originalId && (int)$id === (int)$originalId) || 
                    ($originalName && strtolower(trim($k)) === strtolower(trim($originalName))) ||
                    ((int)$id === (int)$theatreId)) {
                    $keyToUpdate = $k;
                    break;
                }
            }

            if ($keyToUpdate !== null && $keyToUpdate !== $name) {
                unset($locations[$keyToUpdate]);
            }

            $screensArr = array_map('trim', explode(',', $screensStr));
            $screensArr = array_values(array_filter($screensArr));
            if (empty($screensArr)) $screensArr = ['Standard'];

            $locations[$name] = [
                'id' => (int)$theatreId,
                'city' => $city,
                'province' => strtoupper($province),
                'region' => $region,
                'screens' => $screensArr,
                'enabled' => $enabled
            ];

            $bytes = @file_put_contents($locFile, json_encode($locations, JSON_PRETTY_PRINT));
            if ($bytes === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to write updated location to locations.json.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => "Theater '{$name}' (ID #{$theatreId}) updated successfully."
            ]);
            break;

        case 'pause_all_theatres':
            Security::verifyCsrfOrDie();
            $locFile = dirname(__DIR__) . '/config/locations.json';
            if (!file_exists($locFile)) {
                http_response_code(500);
                echo json_encode(['error' => 'locations.json file missing.']);
                exit;
            }

            $locations = json_decode(file_get_contents($locFile), true) ?: [];
            foreach ($locations as $name => &$data) {
                if (is_array($data)) {
                    $data['enabled'] = false;
                }
            }
            unset($data);

            $bytesWritten = @file_put_contents($locFile, json_encode($locations, JSON_PRETTY_PRINT));
            if ($bytesWritten === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to write to locations.json file.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'All locations have been archived and paused. Select and enable your chosen theaters from the roster below!'
            ]);
            break;

        case 'delete_theatre':
            Security::verifyCsrfOrDie();
            $theatreId = Security::sanitizeInput($_POST['theatre_id'] ?? $_POST['id'] ?? null, 'int');
            $theatreName = Security::sanitizeInput($_POST['name'] ?? $_POST['theatre_name'] ?? null, 'string');

            if (!$theatreId && !$theatreName) {
                http_response_code(400);
                echo json_encode(['error' => 'theatre_id or name parameter is required.']);
                exit;
            }

            $locFile = dirname(__DIR__) . '/config/locations.json';
            if (!file_exists($locFile)) {
                http_response_code(500);
                echo json_encode(['error' => 'locations.json file missing.']);
                exit;
            }

            $locations = json_decode(file_get_contents($locFile), true) ?: [];
            $targetKey = null;

            foreach ($locations as $name => $data) {
                $id = is_array($data) ? ($data['id'] ?? null) : $data;
                if (($theatreId && (int)$id === (int)$theatreId) || 
                    ($theatreName && strtolower(trim($name)) === strtolower(trim($theatreName)))) {
                    $targetKey = $name;
                    break;
                }
            }

            if ($targetKey !== null) {
                unset($locations[$targetKey]);
                $bytesWritten = @file_put_contents($locFile, json_encode($locations, JSON_PRETTY_PRINT));
                if ($bytesWritten === false) {
                    http_response_code(500);
                    echo json_encode(['error' => "Failed to write to locations.json. Please check server file write permissions."]);
                    exit;
                }
                echo json_encode([
                    'success' => true,
                    'message' => "Theater '{$targetKey}' removed from locations list."
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Theater not found in locations configuration.']);
            }
            break;

        case 'clean_all_trackers':
            Security::verifyCsrfOrDie();
            try {
                $db = Cinepulse\Database::getInstance()->getConnection();
                
                // Reset active tracking tables
                try {
                    $db->exec("TRUNCATE TABLE tracked_showtimes");
                } catch (\Exception $e) {
                    $db->exec("DELETE FROM tracked_showtimes");
                }
                
                try {
                    $db->exec("TRUNCATE TABLE showtime_occupancy_log");
                } catch (\Exception $e) {
                    $db->exec("DELETE FROM showtime_occupancy_log");
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'All active trackers and occupancy telemetry logs have been wiped clean. System reset complete!'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to wipe trackers: ' . $e->getMessage()]);
            }
            break;

        case 'setup_tomorrow':
            Security::verifyCsrfOrDie();
            try {
                $tomorrowStr = date('Y-m-d', strtotime('+1 day'));
                $cineplexDate = date('m+d+Y', strtotime('+1 day'));
                
                $locations = \Cinepulse\ShowtimeService::getTrackerTheatres(true); // Active locations only
                $api = new CineplexAPI();
                $totalSaved = 0;

                foreach ($locations as $tName => $tId) {
                    try {
                        $raw = $api->fetchShowtimes($tId, $cineplexDate);
                        if (!isset($raw['error'])) {
                            $saved = \Cinepulse\ShowtimeService::saveShowtimesToDatabase($tId, $tName, $raw);
                            $totalSaved += $saved;
                        }
                    } catch (\Exception $e) {
                        // Skip location on error
                    }
                }

                echo json_encode([
                    'success' => true,
                    'date' => $tomorrowStr,
                    'showtimes_count' => $totalSaved,
                    'message' => "Successfully pre-cached {$totalSaved} showtimes for tomorrow ({$tomorrowStr}) across " . count($locations) . " active venues."
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to setup tomorrow: ' . $e->getMessage()]);
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Requested action is invalid.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error: ' . $e->getMessage()]);
}