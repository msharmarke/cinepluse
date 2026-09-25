<?php
namespace Cinepulse;

use PDO;
use Exception;
use DateTime;
use DateTimeZone;

/**
 * Dashboard & Analytics Service for Cinepulse
 * Handles stats aggregation, revenue estimates, cron status, log parsing, and CSV exports.
 */
class DashboardService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get daemon background execution status and next run timer
     * 
     * @return array
     */
    public function getDaemonStatus() {
        $logFile = dirname(__DIR__) . '/bin/track_occupancy.log';
        $lockFile = dirname(__DIR__) . '/bin/.cron_running.lock';
        $now = time();

        $isRunning = false;
        $runStartTime = null;

        if (file_exists($lockFile)) {
            $lockData = @json_decode(file_get_contents($lockFile), true);
            if ($lockData && isset($lockData['started_at'])) {
                $lockStart = strtotime($lockData['started_at']);
                if ($now - $lockStart < 1800) {
                    $isRunning = true;
                    $runStartTime = $lockData['started_at'];
                } else {
                    @unlink($lockFile); // Purge stale lock
                }
            }
        }

        // Calculate next 15-minute cron interval
        $currentMinute = (int)date('i');
        $currentSecond = (int)date('s');
        $minsUntilNext = 15 - ($currentMinute % 15);
        if ($minsUntilNext === 15) $minsUntilNext = 0;
        
        $nextRunTimestamp = $now + ($minsUntilNext * 60) - $currentSecond;
        if ($nextRunTimestamp <= $now) {
            $nextRunTimestamp += 900;
        }

        // Last log time
        $lastLogTime = file_exists($logFile) ? date('Y-m-d H:i:s', filemtime($logFile)) : 'N/A';

        return [
            'is_running' => $isRunning,
            'started_at' => $runStartTime,
            'last_log_time' => $lastLogTime,
            'next_run_time' => date('Y-m-d H:i:s', $nextRunTimestamp),
            'seconds_until_next' => max(0, $nextRunTimestamp - $now)
        ];
    }

    /**
     * Fetch global aggregate system statistics
     * 
     * @param string $dateFrom (Y-m-d)
     * @param string $dateTo (Y-m-d)
     * @return array
     */
    public function getSystemMetrics($dateFrom = null, $dateTo = null) {
        $dateFrom = $dateFrom ?: date('Y-m-d', strtotime('-30 days'));
        $dateTo = $dateTo ?: date('Y-m-d');
        
        $dateFromSql = $dateFrom . ' 00:00:00';
        $dateToSql = $dateTo . ' 23:59:59';

        // 1. Showtimes stats
        $stmt = $this->db->prepare("SELECT 
            COUNT(*) as total_showtimes,
            COUNT(DISTINCT theatre_id) as theatres_count,
            COUNT(DISTINCT movie_name) as movies_count
            FROM showtimes
            WHERE show_start_time >= ? AND show_start_time <= ?");
        $stmt->execute([$dateFromSql, $dateToSql]);
        $showtimeStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Active and Completed Trackers
        $stmt_act = $this->db->query("SELECT COUNT(*) as active_cnt FROM tracked_showtimes WHERE status = 'active'");
        $activeTrackers = $stmt_act->fetchColumn() ?: 0;

        $stmt_comp = $this->db->query("SELECT COUNT(*) as completed_cnt FROM tracked_showtimes WHERE status = 'completed'");
        $completedTrackers = $stmt_comp->fetchColumn() ?: 0;

        // 3. Snapshots log stats
        $stmt_snaps = $this->db->prepare("SELECT 
            COUNT(*) as total_snapshots,
            AVG(occupancy_percentage) as avg_occupancy,
            MAX(occupancy_percentage) as max_occupancy,
            SUM(seats_occupied) as total_seats_occupied
            FROM showtime_snapshots_history
            WHERE snapshot_time >= ? AND snapshot_time <= ?");
        $stmt_snaps->execute([$dateFromSql, $dateToSql]);
        $snapStats = $stmt_snaps->fetch(PDO::FETCH_ASSOC) ?: [];

        // 4. Revenue Estimation: Ticket Price * Seats Occupied
        $stmt_rev = $this->db->prepare("SELECT 
            SUM(s.ticket_price * sol.seats_occupied) as total_revenue,
            SUM(sol.seats_occupied) as tickets_sold
            FROM showtime_occupancy_log sol
            INNER JOIN showtimes s ON sol.theatre_id = s.theatre_id 
                AND sol.showtime_id = s.showtime_id 
                AND DATE(sol.show_start_time) = DATE(s.show_start_time)
            WHERE sol.show_start_time >= ? AND sol.show_start_time <= ?
                AND s.ticket_price IS NOT NULL AND s.ticket_price > 0");
        $stmt_rev->execute([$dateFromSql, $dateToSql]);
        $revStats = $stmt_rev->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
            'total_showtimes' => (int)($showtimeStats['total_showtimes'] ?? 0),
            'theatres_count' => (int)($showtimeStats['theatres_count'] ?? 0),
            'movies_count' => (int)($showtimeStats['movies_count'] ?? 0),
            'active_trackers' => (int)$activeTrackers,
            'completed_trackers' => (int)$completedTrackers,
            'total_snapshots' => (int)($snapStats['total_snapshots'] ?? 0),
            'avg_occupancy' => round((float)($snapStats['avg_occupancy'] ?? 0), 2),
            'max_occupancy' => round((float)($snapStats['max_occupancy'] ?? 0), 2),
            'estimated_revenue' => round((float)($revStats['total_revenue'] ?? 0), 2),
            'tickets_sold' => (int)($revStats['tickets_sold'] ?? 0)
        ];
    }

    /**
     * Get chart analytics data (Daily occupancy trends, theater comparisons, top movies)
     * 
     * @param string $dateFrom
     * @param string $dateTo
     * @return array
     */
    public function getAnalyticsData($dateFrom = null, $dateTo = null) {
        $dateFrom = $dateFrom ?: date('Y-m-d', strtotime('-14 days'));
        $dateTo = $dateTo ?: date('Y-m-d');
        
        $dateFromSql = $dateFrom . ' 00:00:00';
        $dateToSql = $dateTo . ' 23:59:59';

        // 1. Daily Occupancy Trend
        $stmt_daily = $this->db->prepare("SELECT 
            DATE(snapshot_time) as date,
            ROUND(AVG(occupancy_percentage), 2) as avg_occ,
            COUNT(*) as snapshots_count
            FROM showtime_snapshots_history
            WHERE snapshot_time >= ? AND snapshot_time <= ?
            GROUP BY DATE(snapshot_time)
            ORDER BY DATE(snapshot_time) ASC");
        $stmt_daily->execute([$dateFromSql, $dateToSql]);
        $dailyTrend = $stmt_daily->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Occupancy by Theatre
        $stmt_theatres = $this->db->prepare("SELECT 
            t.theatre_name,
            ROUND(AVG(h.occupancy_percentage), 2) as avg_occ,
            COUNT(DISTINCT t.id) as sessions_tracked
            FROM tracked_showtimes t
            INNER JOIN showtime_snapshots_history h ON t.id = h.tracked_showtime_id
            WHERE h.snapshot_time >= ? AND h.snapshot_time <= ?
            GROUP BY t.theatre_id, t.theatre_name
            ORDER BY avg_occ DESC");
        $stmt_theatres->execute([$dateFromSql, $dateToSql]);
        $theatreStats = $stmt_theatres->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 3. Top Movies by Average Occupancy
        $stmt_movies = $this->db->prepare("SELECT 
            t.movie_name,
            ROUND(AVG(h.occupancy_percentage), 2) as avg_occ,
            MAX(h.occupancy_percentage) as peak_occ,
            COUNT(DISTINCT t.id) as showtimes_tracked
            FROM tracked_showtimes t
            INNER JOIN showtime_snapshots_history h ON t.id = h.tracked_showtime_id
            WHERE h.snapshot_time >= ? AND h.snapshot_time <= ?
            GROUP BY t.movie_name
            ORDER BY avg_occ DESC LIMIT 10");
        $stmt_movies->execute([$dateFromSql, $dateToSql]);
        $topMovies = $stmt_movies->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'daily_trend' => $dailyTrend,
            'theatres' => $theatreStats,
            'top_movies' => $topMovies
        ];
    }

    /**
     * Read and parse CLI daemon log entries
     * 
     * @param string $logType ('track' or 'collect')
     * @param int $linesCount
     * @return array
     */
    public function getLogs($logType = 'track', $linesCount = 100) {
        $filename = ($logType === 'collect') ? 'collect_showtimes.log' : 'track_occupancy.log';
        $filepath = dirname(__DIR__) . '/bin/' . $filename;

        if (!file_exists($filepath)) {
            return [];
        }

        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $slice = array_slice($lines, -$linesCount);
        return array_reverse($slice);
    }

    /**
     * Export database records as downloadable CSV
     * 
     * @param string $type ('occupancy' or 'showtimes')
     * @param string $dateFrom
     * @param string $dateTo
     */
    public function exportCsv($type = 'occupancy', $dateFrom = null, $dateTo = null) {
        $dateFrom = $dateFrom ?: date('Y-m-d', strtotime('-30 days'));
        $dateTo = $dateTo ?: date('Y-m-d');
        
        $dateFromSql = $dateFrom . ' 00:00:00';
        $dateToSql = $dateTo . ' 23:59:59';

        if ($type === 'showtimes') {
            $stmt = $this->db->prepare("SELECT 
                show_start_time, show_end_time, movie_name, screen_name, theatre_id, theatre_name, showtime_id, movie_runtime_minutes, experience_types, ticket_price
                FROM showtimes
                WHERE show_start_time >= ? AND show_start_time <= ?
                ORDER BY show_start_time ASC");
            $stmt->execute([$dateFromSql, $dateToSql]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="cinepulse_showtimes_' . $dateFrom . '_to_' . $dateTo . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Start Time', 'End Time', 'Movie Title', 'Auditorium', 'Theatre ID', 'Theatre Name', 'Showtime ID', 'Runtime (mins)', 'Experiences', 'Price']);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
            exit;
        } else {
            // Occupancy snapshots export
            $stmt = $this->db->prepare("SELECT 
                h.snapshot_time, t.show_start_time, t.movie_name, t.theatre_name, t.showtime_id, h.seats_occupied, h.seats_available, h.seats_broken, h.seats_total_layout, h.calculated_capacity, h.occupancy_percentage
                FROM showtime_snapshots_history h
                INNER JOIN tracked_showtimes t ON h.tracked_showtime_id = t.id
                WHERE h.snapshot_time >= ? AND h.snapshot_time <= ?
                ORDER BY h.snapshot_time DESC");
            $stmt->execute([$dateFromSql, $dateToSql]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="cinepulse_occupancy_' . $dateFrom . '_to_' . $dateTo . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Snapshot Time', 'Show Start Time', 'Movie Title', 'Theatre Name', 'Showtime ID', 'Occupied Seats', 'Available Seats', 'Broken Seats', 'Total Seats', 'Capacity', 'Occupancy %']);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
            exit;
        }
    }

    /**
     * Scrape full theatrical week (Friday through Thursday) for all configured locations
     * 
     * @param string|null $startFriday Y-m-d format
     * @return array Summary of scrape results
     */
    public function scrapeFullTheatricalWeek($startFriday = null) {
        if ($startFriday && strtotime($startFriday)) {
            $startFridaySec = strtotime($startFriday);
        } else {
            $todaySec = strtotime('today');
            $dayOfWeek = (int)date('N', $todaySec); // 1 = Monday, 5 = Friday, 7 = Sunday
            if ($dayOfWeek === 5) {
                $startFridaySec = $todaySec;
            } else if ($dayOfWeek < 5) {
                $startFridaySec = strtotime('last Friday', $todaySec);
            } else {
                $startFridaySec = strtotime('last Friday', $todaySec);
            }
        }

        $startFridayStr = date('Y-m-d', $startFridaySec);
        $endThursdayStr = date('Y-m-d', strtotime('+6 days', $startFridaySec));

        $locFile = dirname(__DIR__) . '/config/locations.json';
        if (!file_exists($locFile)) {
            throw new Exception("locations.json configuration file is missing.");
        }

        $locations = json_decode(file_get_contents($locFile), true) ?: [];
        if (empty($locations)) {
            throw new Exception("No theatres configured in locations.json.");
        }

        $api = new CineplexAPI();
        $totalSavedShowtimes = 0;
        $totalSuccesses = 0;
        $totalFailures = 0;
        $dayBreakdown = [];

        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $currentDate = date('Y-m-d', strtotime("+{$dayOffset} days", $startFridaySec));
            $cineplexDate = date('m+d+Y', strtotime($currentDate));
            $dayName = date('l', strtotime($currentDate));
            $daySavedCount = 0;

            foreach ($locations as $name => $id) {
                try {
                    $data = $api->fetchShowtimes($id, $cineplexDate, true);
                    if (isset($data['error'])) {
                        $totalFailures++;
                    } else {
                        $saved = ShowtimeService::saveShowtimesToDatabase($id, $name, $data);
                        $totalSavedShowtimes += $saved;
                        $daySavedCount += $saved;
                        $totalSuccesses++;
                    }
                } catch (\Exception $ex) {
                    $totalFailures++;
                }
                usleep(150000); // 150ms delay
            }

            $dayBreakdown[] = [
                'date' => $currentDate,
                'day_name' => $dayName,
                'showtimes_saved' => $daySavedCount
            ];
        }

        // Auto-register showtimes matching active movie trackers
        $registeredTrackers = 0;
        try {
            $trackerService = new TrackerService();
            $registeredTrackers = $trackerService->scanAndRegisterForAllMovieTrackers();
        } catch (\Exception $e) {
            // Non-fatal
        }

        return [
            'success' => true,
            'start_date' => $startFridayStr,
            'end_date' => $endThursdayStr,
            'total_saved_showtimes' => $totalSavedShowtimes,
            'total_successes' => $totalSuccesses,
            'total_failures' => $totalFailures,
            'auto_registered_trackers' => $registeredTrackers,
            'day_breakdown' => $dayBreakdown,
            'message' => "Successfully scraped theatrical week ({$startFridayStr} to {$endThursdayStr})! Saved {$totalSavedShowtimes} showtimes across " . count($locations) . " locations."
        ];
    }

    /**
     * Fetch weekly showtimes with tracking statuses and latest occupancy data
     * 
     * @param string $date Y-m-d format
     * @param int|null $theatreId
     * @param string|null $search
     * @param string|null $filter ('all', 'tracked', 'untracked')
     * @return array
     */
    public function getWeeklySchedule($date = null, $theatreId = null, $search = null, $filter = 'all') {
        $date = $date ?: date('Y-m-d');
        $params = [];
        
        $sql = "SELECT 
            s.*,
            t.id as tracker_id,
            t.status as tracker_status,
            h.occupancy_percentage as latest_occupancy,
            h.seats_occupied as latest_occupied_seats,
            h.seats_available as latest_available_seats,
            h.seats_total_layout as latest_total_seats,
            h.snapshot_time as latest_snapshot_time
            FROM showtimes s
            LEFT JOIN tracked_showtimes t 
                ON s.theatre_id = t.theatre_id 
                AND s.showtime_id = t.showtime_id 
                AND DATE(s.show_start_time) = DATE(t.show_start_time)
            LEFT JOIN (
                SELECT h1.*
                FROM showtime_snapshots_history h1
                INNER JOIN (
                    SELECT tracked_showtime_id, MAX(id) as max_id
                    FROM showtime_snapshots_history
                    GROUP BY tracked_showtime_id
                ) latest ON h1.id = latest.max_id
            ) h ON t.id = h.tracked_showtime_id
            WHERE DATE(s.show_start_time) = ?";
        
        $params[] = $date;

        if ($theatreId) {
            $sql .= " AND s.theatre_id = ?";
            $params[] = (int)$theatreId;
        }

        if ($search) {
            $sql .= " AND (s.movie_name LIKE ? OR s.screen_name LIKE ? OR s.theatre_name LIKE ?)";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($filter === 'tracked') {
            $sql .= " AND t.id IS NOT NULL";
        } else if ($filter === 'untracked') {
            $sql .= " AND t.id IS NULL";
        }

        $sql .= " ORDER BY s.movie_name ASC, s.show_start_time ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group showtimes by movie_name
        $grouped = [];
        foreach ($rows as $row) {
            $movie = $row['movie_name'];
            if (!isset($grouped[$movie])) {
                $grouped[$movie] = [
                    'movie_name' => $movie,
                    'runtime' => (int)($row['movie_runtime_minutes'] ?? 120),
                    'experience_types' => @json_decode($row['experience_types'], true) ?: [],
                    'showtimes' => []
                ];
            }

            $grouped[$movie]['showtimes'][] = [
                'id' => (int)$row['id'],
                'theatre_id' => (int)$row['theatre_id'],
                'theatre_name' => $row['theatre_name'],
                'showtime_id' => $row['showtime_id'],
                'screen_name' => $row['screen_name'],
                'show_start_time' => $row['show_start_time'],
                'show_start_formatted' => date('g:i A', strtotime($row['show_start_time'])),
                'ticket_price' => (float)($row['ticket_price'] ?? 14.99),
                'tracker_id' => $row['tracker_id'] ? (int)$row['tracker_id'] : null,
                'tracker_status' => $row['tracker_status'] ?? null,
                'latest_occupancy' => $row['latest_occupancy'] !== null ? round((float)$row['latest_occupancy'], 1) : null,
                'latest_occupied_seats' => $row['latest_occupied_seats'] !== null ? (int)$row['latest_occupied_seats'] : null,
                'latest_total_seats' => $row['latest_total_seats'] !== null ? (int)$row['latest_total_seats'] : null,
                'latest_snapshot_time' => $row['latest_snapshot_time'] ?? null
            ];
        }

        return [
            'success' => true,
            'date' => $date,
            'total_showtimes' => count($rows),
            'movies_count' => count($grouped),
            'movies' => array_values($grouped)
        ];
    }
}
