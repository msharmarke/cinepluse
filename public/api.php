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
function get_tracker_theatres() {
    $locFile = dirname(__DIR__) . '/config/locations.json';
    if (file_exists($locFile)) {
        return json_decode(file_get_contents($locFile), true) ?: [];
    }
    return [];
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

        case 'list_archives':
            $archiveService = new Cinepulse\ArchiveService();
            $archives = $archiveService->listArchives();
            echo json_encode([
                'success' => true,
                'archives' => $archives,
                'total' => count($archives)
            ]);
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

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Requested action is invalid.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error: ' . $e->getMessage()]);
}