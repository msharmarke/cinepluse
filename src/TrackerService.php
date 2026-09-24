<?php
namespace Cinepulse;

use PDO;
use Exception;
use DateTime;
use DateTimeZone;

/**
 * Occupancy Monitors and Snapshot Management Service
 */
class TrackerService {
    private $db;
    private $api;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->api = new CineplexAPI();
        $this->ensureTablesExist();
    }

    /**
     * Ensure required tables for movie tracking exist
     */
    private function ensureTablesExist() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `movie_release_trackers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `movie_name` VARCHAR(255) NOT NULL,
                `experience_filter` VARCHAR(255) DEFAULT NULL,
                `theatre_id` INT NOT NULL,
                `theatre_name` VARCHAR(255) NOT NULL,
                `start_date` DATE DEFAULT NULL,
                `end_date` DATE DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `status` ENUM('active', 'paused') DEFAULT 'active',
                UNIQUE KEY `unique_movie_theatre_filter` (`movie_name`, `theatre_id`, `experience_filter`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Migration: Add columns to existing table dynamically if they don't exist
            try {
                $this->db->exec("ALTER TABLE `movie_release_trackers` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `theatre_name`");
            } catch (\Exception $e) {
                // Ignore if column exists
            }
            try {
                $this->db->exec("ALTER TABLE `movie_release_trackers` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`");
            } catch (\Exception $e) {
                // Ignore if column exists
            }

            $this->db->exec("CREATE TABLE IF NOT EXISTS `showtime_release_alerts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `movie_name` VARCHAR(255) NOT NULL,
                `theatre_name` VARCHAR(255) NOT NULL,
                `show_start_time` DATETIME NOT NULL,
                `experience_type` VARCHAR(100) NOT NULL,
                `notified_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `movie_tracker_scan_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `movie_tracker_id` INT NOT NULL,
                `movie_name` VARCHAR(255) NOT NULL,
                `theatre_name` VARCHAR(255) NOT NULL,
                `date_scanned` DATE NOT NULL,
                `scanned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `status` ENUM('success', 'error') DEFAULT 'success',
                `results_found` INT NOT NULL DEFAULT 0,
                `new_registered` INT NOT NULL DEFAULT 0,
                `error_message` TEXT DEFAULT NULL,
                INDEX `idx_tracker` (`movie_tracker_id`),
                INDEX `idx_scanned_at` (`scanned_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Exception $e) {
            error_log("Failed to initialize tracking tables: " . $e->getMessage());
        }
    }

    /**
     * Register a new showtime tracker and log an initial snapshot
     * 
     * @param int $theatreId
     * @param string $theatreName
     * @param string $showtimeId
     * @param string $movieName
     * @param string $startTimeSql
     * @return int Tracker ID
     * @throws Exception
     */
    public function registerTracker($theatreId, $theatreName, $showtimeId, $movieName, $startTimeSql) {
        // Check duplicate
        $stmt = $this->db->prepare("SELECT id, status FROM tracked_showtimes WHERE theatre_id = ? AND showtime_id = ? AND show_start_time = ?");
        $stmt->execute([$theatreId, $showtimeId, $startTimeSql]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'active') {
                return (int)$existing['id'];
            } else {
                // Reactivate
                $stmt_upd = $this->db->prepare("UPDATE tracked_showtimes SET status = 'active' WHERE id = ?");
                $stmt_upd->execute([$existing['id']]);
                return (int)$existing['id'];
            }
        }

        // Insert new
        $stmt_ins = $this->db->prepare("INSERT INTO tracked_showtimes (theatre_id, theatre_name, showtime_id, movie_name, show_start_time, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt_ins->execute([$theatreId, $theatreName, $showtimeId, $movieName, $startTimeSql]);
        $trackerId = (int)$this->db->lastInsertId();

        // Trigger first instant snapshot
        try {
            $this->logSnapshot($trackerId);
        } catch (Exception $e) {
            // Log but don't fail the registration
            error_log("Initial snapshot logging failed: " . $e->getMessage());
        }

        return $trackerId;
    }

    /**
     * Fetch layout/availability live and save a snapshot record
     * 
     * @param int $trackerId
     * @return array Snapshot data
     * @throws Exception
     */
    public function logSnapshot($trackerId) {
        $stmt = $this->db->prepare("SELECT * FROM tracked_showtimes WHERE id = ?");
        $stmt->execute([$trackerId]);
        $tracker = $stmt->fetch();

        if (!$tracker) {
            throw new Exception("Tracker session not found.");
        }

        $theatreId = $tracker['theatre_id'];
        $showtimeId = $tracker['showtime_id'];

        $layoutUrl = "https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{$theatreId}/showtime/{$showtimeId}/seat-layout";
        $availUrl = "https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{$theatreId}/showtime/{$showtimeId}/seat-availability";

        $layoutData = $this->api->fetchCineplexAPI($layoutUrl);
        $availData = $this->api->fetchCineplexAPI($availUrl);

        if (isset($layoutData['error']) || isset($availData['error']) ||
            !isset($availData['seatAvailabilities']) || !is_array($availData['seatAvailabilities']) ||
            (!isset($layoutData['standardSeats']['rows']) && !isset($layoutData['dboxSeats']['rows']))
        ) {
            throw new Exception("Failed to fetch layouts or availability from Cineplex.");
        }

        $availability = $availData['seatAvailabilities'];
        $occupied = 0; $available = 0; $broken = 0; $total_seats = 0;
        $all_seat_ids = [];

        // Parse seats
        if (isset($layoutData['standardSeats']['rows'])) {
            foreach ($layoutData['standardSeats']['rows'] as $row) {
                if (isset($row['seats'])) {
                    foreach ($row['seats'] as $seat) {
                        if (isset($seat['id'])) $all_seat_ids[] = $seat['id'];
                    }
                }
            }
        }
        if (isset($layoutData['dboxSeats']['rows'])) {
            foreach ($layoutData['dboxSeats']['rows'] as $row) {
                if (isset($row['seats'])) {
                    foreach ($row['seats'] as $seat) {
                        if (isset($seat['id'])) $all_seat_ids[] = $seat['id'];
                    }
                }
            }
        }

        $total_seats = count($all_seat_ids);
        foreach ($all_seat_ids as $seat_id) {
            $status = $availability[$seat_id] ?? 'Broken';
            if ($status === 'Occupied') $occupied++;
            elseif ($status === 'Available') $available++;
            else $broken++;
        }

        $capacity = max(0, $total_seats - $broken);
        $percentage = ($capacity > 0) ? round(($occupied / $capacity) * 100, 2) : 0.00;

        $now = new DateTime('now', new DateTimeZone('America/Toronto'));
        $snapshotTimeStr = $now->format('Y-m-d_H-i-s');
        $filename = "seatmap_{$trackerId}_{$snapshotTimeStr}.json";
        
        $snapshotsDir = dirname(__DIR__) . '/snapshots';
        if (!is_dir($snapshotsDir)) {
            @mkdir($snapshotsDir, 0755, true);
        }

        // Save layout as flat JSON file
        $filepath = $snapshotsDir . '/' . $filename;
        $saved = file_put_contents($filepath, json_encode([
            'layout' => $layoutData,
            'availability' => $availability
        ]), LOCK_EX);

        if ($saved === false) {
            throw new Exception("Unable to save seatmap snapshot to file system.");
        }

        // Log record in database
        $stmt_ins = $this->db->prepare("INSERT INTO showtime_snapshots_history 
            (tracked_showtime_id, snapshot_time, seats_occupied, seats_available, seats_broken, seats_total_layout, calculated_capacity, occupancy_percentage, seatmap_file_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->execute([
            $trackerId,
            $now->format('Y-m-d H:i:s'),
            $occupied,
            $available,
            $broken,
            $total_seats,
            $capacity,
            $percentage,
            $filename
        ]);

        return [
            'snapshot_time' => $now->format('Y-m-d H:i:s'),
            'occupancy_percentage' => $percentage,
            'seats_occupied' => $occupied,
            'seats_available' => $available
        ];
    }

    /**
     * Trigger manual snapshot capture for all active showtime monitors
     * 
     * @return int Number of successfully captured snapshots
     */
    public function logSnapshotForAllActiveTrackers() {
        $stmt = $this->db->prepare("SELECT id FROM tracked_showtimes WHERE status = 'active'");
        $stmt->execute();
        $trackers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $count = 0;
        foreach ($trackers as $t) {
            try {
                $this->logSnapshot((int)$t['id']);
                $count++;
            } catch (\Exception $e) {
                error_log("Failed manual snapshot for tracker {$t['id']}: " . $e->getMessage());
            }
        }
        return $count;
    }

    /**
     * Delete tracker session and snapshot files
     * 
     * @param int $trackerId
     * @return bool
     */
    public function deleteTracker($trackerId) {
        $snapshotsDir = dirname(__DIR__) . '/snapshots';
        
        // Find snapshot files
        $stmt_files = $this->db->prepare("SELECT seatmap_file_path FROM showtime_snapshots_history WHERE tracked_showtime_id = ?");
        $stmt_files->execute([$trackerId]);
        $files = $stmt_files->fetchAll();

        foreach ($files as $file) {
            $filepath = $snapshotsDir . '/' . $file['seatmap_file_path'];
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
        }

        // Delete database entries (foreign key handles snaps deletes)
        $stmt_del = $this->db->prepare("DELETE FROM tracked_showtimes WHERE id = ?");
        $stmt_del->execute([$trackerId]);
        return true;
    }

    /**
     * Register a new automated movie release tracker
     * 
     * @param string $movieName
     * @param string $experienceFilter
     * @param int $theatreId
     * @param string $theatreName
     * @param string $startDate (Format: Y-m-d)
     * @param string $endDate (Format: Y-m-d)
     * @return int Movie Tracker ID
     * @throws Exception
     */
    public function registerMovieTracker($movieName, $experienceFilter, $theatreId, $theatreName, $startDate = null, $endDate = null) {
        $cleanFilter = !empty($experienceFilter) ? trim($experienceFilter) : null;
        $cleanStart = !empty($startDate) ? trim($startDate) : null;
        $cleanEnd = !empty($endDate) ? trim($endDate) : null;
        
        // Check duplicate
        $stmt = $this->db->prepare("SELECT id FROM movie_release_trackers WHERE movie_name = ? AND theatre_id = ? AND (experience_filter = ? OR (experience_filter IS NULL AND ? IS NULL))");
        $stmt->execute([$movieName, $theatreId, $cleanFilter, $cleanFilter]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $id = (int)$existing['id'];
            // Re-activate and update dates if paused
            $stmt_upd = $this->db->prepare("UPDATE movie_release_trackers SET status = 'active', start_date = ?, end_date = ? WHERE id = ?");
            $stmt_upd->execute([$cleanStart, $cleanEnd, $id]);
        } else {
            // Insert new rule
            $stmt_ins = $this->db->prepare("INSERT INTO movie_release_trackers (movie_name, experience_filter, theatre_id, theatre_name, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt_ins->execute([$movieName, $cleanFilter, $theatreId, $theatreName, $cleanStart, $cleanEnd]);
            $id = (int)$this->db->lastInsertId();
        }
        
        // Scan immediately for upcoming matching showtimes
        $stmt_get = $this->db->prepare("SELECT * FROM movie_release_trackers WHERE id = ?");
        $stmt_get->execute([$id]);
        $tracker = $stmt_get->fetch(PDO::FETCH_ASSOC);
        
        if ($tracker) {
            $this->scanAndRegisterForMovieTracker($tracker);
        }
        
        return $id;
    }

    /**
     * Delete an automated movie release tracker rule
     * 
     * @param int $trackerId
     * @return bool
     */
    public function deleteMovieTracker($trackerId) {
        $stmt = $this->db->prepare("DELETE FROM movie_release_trackers WHERE id = ?");
        $stmt->execute([$trackerId]);
        return true;
    }

    /**
     * Pause or resume a movie release tracker
     * 
     * @param int $trackerId
     * @param string $status 'active' or 'paused'
     * @return bool
     */
    public function toggleMovieTrackerStatus($trackerId, $status) {
        if (!in_array($status, ['active', 'paused'])) {
            throw new Exception("Invalid status specified.");
        }
        $stmt = $this->db->prepare("UPDATE movie_release_trackers SET status = ? WHERE id = ?");
        $stmt->execute([$status, $trackerId]);
        return true;
    }

    /**
     * Retrieve all configured movie release trackers
     * 
     * @return array
     */
    public function getMovieTrackers() {
        $stmt = $this->db->prepare("SELECT * FROM movie_release_trackers ORDER BY created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Send a release notification alert and record it in the database
     * 
     * @param string $movieName
     * @param string $theatreName
     * @param string $showStartTime
     * @param string $experienceType
     * @return bool
     */
    public function sendReleaseAlert($movieName, $theatreName, $showStartTime, $experienceType) {
        try {
            // Insert into showtime_release_alerts
            $stmt = $this->db->prepare("INSERT INTO showtime_release_alerts (movie_name, theatre_name, show_start_time, experience_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$movieName, $theatreName, $showStartTime, $experienceType]);
            
            // Mock email sending
            $formattedTime = date('g:i A (M d, Y)', strtotime($showStartTime));
            $expLabel = !empty($experienceType) ? $experienceType : 'Standard';
            
            $subject = "Cinepulse Notification: New Showtime Released - {$movieName}";
            $body = "Great news! A new showtime release has been detected for:\n\n"
                  . "🎬 Movie: {$movieName}\n"
                  . "🏢 Theatre: {$theatreName}\n"
                  . "🕐 Showtime: {$formattedTime}\n"
                  . "🍿 Format: {$expLabel}\n\n"
                  . "We have automatically registered this showtime session and started active occupancy tracking. You can view layout graphs on your dashboard.";
            
            // Log to php error logs
            error_log("[ALERT] Mock Email Sent: \nSubject: {$subject}\nBody:\n{$body}\n");
            
            // Output to standard output if running in CLI context
            if (php_sapi_name() === 'cli') {
                echo "[NOTIFICATION] Sent email alert for {$movieName} at {$theatreName} on {$formattedTime} ({$expLabel})\n";
            }
        } catch (\Exception $e) {
            error_log("Failed to send release alert: " . $e->getMessage());
        }
        
        return true;
    }

    /**
     * Get recent showtime release notifications
     * 
     * @param int $limit
     * @return array
     */
    public function getReleaseAlerts($limit = 30) {
        $stmt = $this->db->prepare("SELECT * FROM showtime_release_alerts ORDER BY notified_at DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Clear all showtime release notifications
     * 
     * @return bool
     */
    public function clearReleaseAlerts() {
        $stmt = $this->db->prepare("DELETE FROM showtime_release_alerts");
        $stmt->execute();
        return true;
    }

    /**
     * Scan upcoming showtimes for a specific movie tracker rule and register any matches
     * 
     * @param array $movieTracker The movie tracker database row
     * @return int Number of newly registered showtimes
     */
    public function scanAndRegisterForMovieTracker($movieTracker) {
        $theatreId = (int)$movieTracker['theatre_id'];
        $theatreName = $movieTracker['theatre_name'];
        $movieNamePattern = trim($movieTracker['movie_name']);
        $expFilter = trim($movieTracker['experience_filter'] ?? '');
        
        $registeredCount = 0;
        
        // Determine date range to scan
        $startDateStr = $movieTracker['start_date'] ?? date('Y-m-d');
        $endDateStr = $movieTracker['end_date'] ?? date('Y-m-d', strtotime('+6 days', strtotime($startDateStr)));
        
        $startSec = strtotime($startDateStr);
        $endSec = strtotime($endDateStr);
        
        // Ensure start date is at least today to prevent scanning past cached/api showtimes
        $todaySec = strtotime(date('Y-m-d'));
        if ($startSec < $todaySec) {
            $startSec = $todaySec;
        }
        
        // Safety Capping: Limit scanning range to 14 days maximum from startSec
        $maxEndSec = strtotime('+13 days', $startSec);
        if ($endSec > $maxEndSec) {
            $endSec = $maxEndSec;
        }
        
        if ($startSec > $endSec) {
            return 0; // Invalid range
        }
        
        // Loop through dates
        $currentSec = $startSec;
        while ($currentSec <= $endSec) {
            $currentDate = date('Y-m-d', $currentSec);
            $cineplexDate = date('m+d+Y', $currentSec);
            $currentSec = strtotime('+1 day', $currentSec);
            
            $dateMatchesCount = 0;
            $dateNewRegisteredCount = 0;
            
            // Fetch showtimes
            $showtimesData = $this->api->fetchShowtimes($theatreId, $cineplexDate);
            if (isset($showtimesData['error']) || empty($showtimesData)) {
                $errDetail = $showtimesData['error'] ?? 'Empty response';
                try {
                    $stmt_log = $this->db->prepare("INSERT INTO movie_tracker_scan_logs (movie_tracker_id, movie_name, theatre_name, date_scanned, status, results_found, new_registered, error_message) VALUES (?, ?, ?, ?, 'error', 0, 0, ?)");
                    $stmt_log->execute([$movieTracker['id'], $movieNamePattern, $theatreName, $currentDate, $errDetail]);
                } catch (\Exception $ex) {
                    error_log("Failed to log scan error: " . $ex->getMessage());
                }
                continue;
            }
            
            $movies = $showtimesData[0]['dates'][0]['movies'] ?? [];
            foreach ($movies as $movie) {
                $mName = $movie['name'] ?? $movie['title'] ?? '';
                
                // Case-insensitive substring match
                if (stripos($mName, $movieNamePattern) === false) {
                    continue;
                }
                
                if (!empty($movie['experiences'])) {
                    foreach ($movie['experiences'] as $exp) {
                        $experienceTypes = $exp['experienceTypes'] ?? [];
                        
                        // Check experience filter if specified
                        if (!empty($expFilter)) {
                            $expStr = implode(', ', $experienceTypes);
                            $filterParts = preg_split('/[\s,]+/', strtolower($expFilter));
                            $matchesExp = true;
                            foreach ($filterParts as $part) {
                                if (empty($part)) continue;
                                if (stripos($expStr, $part) === false) {
                                    $matchesExp = false;
                                    break;
                                }
                            }
                            if (!$matchesExp) {
                                continue;
                            }
                        }
                        
                        // Register matched sessions
                        if (!empty($exp['sessions'])) {
                            foreach ($exp['sessions'] as $session) {
                                $dateMatchesCount++;
                                $sessionId = $session['vistaSessionId'];
                                $showStartIso = $session['showStartDateTime'];
                                
                                // Format start time into MySQL DATETIME
                                $timestamp = strtotime($showStartIso);
                                if ($timestamp === false) continue;
                                $dbStartTime = date('Y-m-d H:i:s', $timestamp);
                                
                                // Check if already registered
                                $stmt_chk = $this->db->prepare("SELECT id FROM tracked_showtimes WHERE theatre_id = ? AND showtime_id = ? AND show_start_time = ?");
                                $stmt_chk->execute([$theatreId, $sessionId, $dbStartTime]);
                                if (!$stmt_chk->fetch()) {
                                    try {
                                        $this->registerTracker($theatreId, $theatreName, $sessionId, $mName, $dbStartTime);
                                        $this->sendReleaseAlert($mName, $theatreName, $dbStartTime, implode(', ', $experienceTypes));
                                        $dateNewRegisteredCount++;
                                        $registeredCount++;
                                    } catch (Exception $e) {
                                        error_log("Auto-registration of showtime {$sessionId} failed: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Log successful scan date execution
            try {
                $stmt_log = $this->db->prepare("INSERT INTO movie_tracker_scan_logs (movie_tracker_id, movie_name, theatre_name, date_scanned, status, results_found, new_registered, error_message) VALUES (?, ?, ?, ?, 'success', ?, ?, NULL)");
                $stmt_log->execute([$movieTracker['id'], $movieNamePattern, $theatreName, $currentDate, $dateMatchesCount, $dateNewRegisteredCount]);
            } catch (\Exception $ex) {
                error_log("Failed to write success scan log: " . $ex->getMessage());
            }
        }
        
        return $registeredCount;
    }

    /**
     * Batch scanning for all active rules (or filtered by theater)
     * 
     * @param int|null $theatreId
     * @return int Total number of newly registered showtimes across all trackers
     */
    public function scanAndRegisterForAllMovieTrackers($theatreId = null) {
        if ($theatreId !== null) {
            $stmt = $this->db->prepare("SELECT * FROM movie_release_trackers WHERE status = 'active' AND theatre_id = ?");
            $stmt->execute([$theatreId]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM movie_release_trackers WHERE status = 'active'");
            $stmt->execute();
        }
        
        $trackers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalRegistered = 0;
        
        foreach ($trackers as $tracker) {
            $totalRegistered += $this->scanAndRegisterForMovieTracker($tracker);
        }
        
        return $totalRegistered;
    }

    /**
     * Get scraper execution logs
     * 
     * @param int $limit
     * @return array
     */
    public function getScanLogs($limit = 100) {
        $stmt = $this->db->prepare("SELECT * FROM movie_tracker_scan_logs ORDER BY scanned_at DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Clear all scraper execution logs
     * 
     * @return bool
     */
    public function clearScanLogs() {
        $stmt = $this->db->prepare("DELETE FROM movie_tracker_scan_logs");
        $stmt->execute();
        return true;
    }

    /**
     * Garbage Collection: Automatically purges cache files older than 24 hours
     */
    public static function purgeExpiredCache() {
        $cacheDir = dirname(__DIR__) . '/cache';
        if (!is_dir($cacheDir)) return;

        $files = glob($cacheDir . '/*.json');
        $now = time();
        $expiredCount = 0;

        foreach ($files as $file) {
            // Delete cache files older than 24 hours
            if (is_file($file) && ($now - filemtime($file) > 86400)) {
                @unlink($file);
                $expiredCount++;
            }
        }
        return $expiredCount;
    }
}
