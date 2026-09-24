<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Cinepulse — Showtime Browser & Live Seats Portal
 */

// Initialize Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

Security::startSession();


// Setup DB connection status warnings (if configurations are uninitialized)
$db_configured = true;
$db_error = '';
$tables_missing = false;
try {
    $db = Cinepulse\Database::getInstance()->getConnection();
    // Validate tables
    $stmt1 = $db->query("SHOW TABLES LIKE 'tracked_showtimes'");
    if ($stmt1->rowCount() === 0) {
        $tables_missing = true;
    }
} catch (Exception $e) {
    $db_configured = false;
    $db_error = $e->getMessage();
}

// Fetch list of theaters
$locations = [];
$locFile = dirname(__DIR__) . '/config/locations.json';
if (file_exists($locFile)) {
    $locations = json_decode(file_get_contents($locFile), true) ?: [];
}

// Default query parameters
$location_id = Security::sanitizeInput($_GET['locationId'] ?? 7411, 'int');
$date = Security::sanitizeInput($_GET['date'] ?? date('Y-m-d'), 'date');
$fetch_showtimes = true;

// Cineplex Date Format
$cineplex_date = date('m+d+Y', strtotime($date));

// Fetch schedule
$showtimes_data = [];
$api_error = '';

if ($fetch_showtimes) {
    try {
        $api = new CineplexAPI();
        $raw_showtimes = $api->fetchShowtimes($location_id, $cineplex_date, isset($_GET['refresh']));
        if (isset($raw_showtimes['error'])) {
            $api_error = $raw_showtimes['error'];
            $showtimes_data = [];
        } else {
            $showtimes_data = $raw_showtimes[0]['dates'][0]['movies'] ?? [];
        }
    } catch (Exception $e) {
        $api_error = $e->getMessage();
    }
}

// Query tracked list to display target indicators
$active_trackers = [];
if ($db_configured && !$tables_missing) {
    try {
        $stmt = $db->prepare("SELECT showtime_id FROM tracked_showtimes WHERE theatre_id = ? AND status = 'active'");
        $stmt->execute([$location_id]);
        $active_trackers = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        // Log but don't fail page
        error_log("Failed to load trackers: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>🎬 Cinepulse — Movies Browser</title>
    
    <!-- Third Party Assets -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Cinepulse Layout Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
        }
        .main-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        .db-warning {
            background: linear-gradient(135deg, var(--color-warning-light) 0%, var(--color-warning) 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-md);
        }
        .db-warning h3 { margin: 0 0 5px 0; color: white; }
        .db-warning code { background: rgba(0,0,0,0.15); padding: 2px 6px; border-radius: 4px; }
        
        .session-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        .session-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
        }
        .session-card .time {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--text-primary);
        }
        .session-card .aud {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin: 4px 0 10px 0;
        }
        .tracking-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(59, 130, 246, 0.2);
            color: #2563eb;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <!-- Theme Selection Drawer -->
    <div class="dark-mode-toggle">
        <button id="toggle-dark-mode" aria-label="Toggle dark mode">
            <span id="dark-mode-icon">🌙</span>
            <span id="dark-mode-text">Dark Mode</span>
        </button>
    </div>

    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>🎬 Cinepulse</h1>
                <p>Command Center Analytics</p>
            </div>
            <nav class="sidebar-nav">
                <a href="schedule" class="active">📅 Schedule</a>
                <a href="movies">🎬 Movies</a>
                <a href="planner">🍿 Planner</a>
                <a href="tracker">📈 Tracker</a>
                <a href="dashboard">📊 Dashboard</a>
                <a href="scan-logs">🔍 Scan Logs</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">

        <!-- Database Connectivity Warnings -->
        <?php if (!$db_configured): ?>
            <div class="db-warning">
                <h3>⚠ Database Connection Failed</h3>
                <p>Cinepulse runs fine for browsing schedules, but target tracking requires a database connection. Error: <code><?php echo htmlspecialchars($db_error ?? ''); ?></code></p>
            </div>
        <?php elseif ($tables_missing): ?>
            <div class="db-warning">
                <h3>⚠ Schema Tables Missing</h3>
                <p>Database connected! But tracking schemas are missing. Please run queries inside <code>cinepluse/schema.sql</code> on your SQL environment to enable tracking.</p>
            </div>
        <?php endif; ?>

        <!-- 7-DAY THEATRICAL WEEK HORIZONTAL CAROUSEL -->
        <?php
        $todaySec = strtotime('today');
        $selectedDate = $date ?? date('Y-m-d');
        ?>
        <div class="week-strip-container">
            <?php for ($i = 0; $i < 7; $i++): 
                $daySec = strtotime("+{$i} days", $todaySec);
                $dayStr = date('Y-m-d', $daySec);
                $dayName = date('D', $daySec);
                $dayNum = date('j', $daySec);
                $monthName = date('M', $daySec);
                $isActive = ($dayStr === $selectedDate);
            ?>
                <a href="?locationId=<?php echo $location_id; ?>&date=<?php echo $dayStr; ?>&fetch_showtimes=1" class="week-pill <?php echo $isActive ? 'active' : ''; ?>">
                    <span class="day-name"><?php echo $dayName; ?></span>
                    <span class="day-num"><?php echo $dayNum; ?></span>
                    <span style="font-size: 0.65rem; opacity: 0.7;"><?php echo $monthName; ?></span>
                </a>
            <?php endfor; ?>
        </div>

        <!-- SECTION 1: SEARCH FILTER CONTROLS -->
        <div class="form-section glass-card" style="margin-bottom: 30px; padding: 25px;">
            <h2 style="margin-top:0;">🔍 Find Showtimes</h2>
            <form method="get" action="">
                <input type="hidden" name="fetch_showtimes" value="1">
                <div class="form-controls">
                    <div class="form-group">
                        <label for="locationId">🏢 Theatre Location:</label>
                        <select name="locationId" id="locationId">
                            <?php foreach ($locations as $name => $id): ?>
                                <option value="<?php echo $id; ?>" <?php echo ($location_id == $id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($name ?? ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date">📅 Choose Date:</label>
                        <input type="date" id="date" name="date" value="<?php echo $date; ?>" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group" style="align-self: flex-end; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="submit" class="button-primary">📡 Fetch Schedules</button>
                        <a href="export-pdf?locationId=<?php echo $location_id; ?>&start_date=<?php echo $date; ?>&print=1" target="_blank" class="button-secondary" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; padding: 10px 16px; border-radius: 8px;">📄 Export Theater PDF</a>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($fetch_showtimes): ?>
            <!-- FILTERS AND SORTING TOOLBAR -->
            <div class="glass-card" style="margin-bottom: 30px; padding: 15px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between;">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center;">
                    <div class="form-group" style="margin:0;">
                        <input type="text" id="movie-search" placeholder="🔍 Filter movie title..." style="width: 220px; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-light);">
                    </div>
                    
                    <div class="form-group" style="margin:0;">
                        <select id="experience-filter" style="padding: 8px 12px; border-radius:6px;">
                            <option value="">✨ All Formats</option>
                            <option value="IMAX">IMAX</option>
                            <option value="3D">3D</option>
                            <option value="VIP">VIP</option>
                            <option value="DBOX">DBOX</option>
                            <option value="UltraAVX">UltraAVX</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <select id="time-range-filter" style="padding: 8px 12px; border-radius:6px;">
                            <option value="">🕐 All Day</option>
                            <option value="morning">Morning (Before 12 PM)</option>
                            <option value="afternoon">Afternoon (12 PM - 5 PM)</option>
                            <option value="evening">Evening (5 PM - 10 PM)</option>
                            <option value="late">Late Night (After 10 PM)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin:0;">
                    <select id="sort-movies" style="padding: 8px 12px; border-radius:6px;">
                        <option value="default">Sort: Default</option>
                        <option value="name-asc">Title: A to Z</option>
                        <option value="name-desc">Title: Z to A</option>
                        <option value="runtime-desc">Runtime: Longest</option>
                        <option value="runtime-asc">Runtime: Shortest</option>
                    </select>
                </div>
            </div>

            <?php if (!empty($api_error)): ?>
                <div class="notice notice-error">
                    <strong>API Fetch Error:</strong> <?php echo htmlspecialchars($api_error ?? ''); ?>
                </div>
            <?php elseif (empty($showtimes_data)): ?>
                <div class="notice notice-info">
                    No showtimes schedule found on this date for the selected theater.
                </div>
            <?php else: ?>
                <?php
                // Stable movie color helper
                if (!function_exists('getMovieColor')) {
                    function getMovieColor($movieTitle) {
                        $hash = md5($movieTitle);
                        $hue = hexdec(substr($hash, 0, 3)) % 360;
                        return "hsl({$hue}, 65%, 38%)";
                    }
                }

                // Group showtimes by auditorium
                $auditorium_matrix = [];
                foreach ($showtimes_data as $movie) {
                    $movieTitle = $movie['name'] ?? 'Unknown';
                    $runtime = $movie['runtimeInMinutes'] ?? $movie['duration'] ?? 120;
                    if (!empty($movie['experiences'])) {
                        foreach ($movie['experiences'] as $exp) {
                            $expName = implode(', ', $exp['experienceTypes'] ?? []);
                            if (!empty($exp['sessions'])) {
                                foreach ($exp['sessions'] as $session) {
                                    $audName = $session['auditorium'] ?? $session['auditoriumName'] ?? 'Auditorium';
                                    $start_time = strtotime($session['showStartDateTime']);
                                    $start_formatted = date('g:i A', $start_time);
                                    
                                    $auditorium_matrix[$audName][] = [
                                        'movie' => $movieTitle,
                                        'start' => $start_time,
                                        'start_formatted' => $start_formatted,
                                        'start_iso' => $session['showStartDateTime'],
                                        'experience' => $expName,
                                        'runtime' => $runtime,
                                        'session_id' => $session['vistaSessionId']
                                    ];
                                }
                            }
                        }
                    }
                }

                // Natural sort on auditorium names
                uksort($auditorium_matrix, 'strnatcasecmp');

                // Sort showtimes chronologically inside each auditorium
                foreach ($auditorium_matrix as $aud => &$sessions) {
                    usort($sessions, function($a, $b) {
                        return $a['start'] <=> $b['start'];
                    });
                }
                unset($sessions);

                // Timeline settings
                $preroll_minutes = 20;
                $timeline_start_date = date('Y-m-d 10:00:00', strtotime($date));
                $timeline_start_time = strtotime($timeline_start_date);
                $total_timeline_hours = 18; // 10am to 4am = 18 hours
                $total_timeline_minutes = $total_timeline_hours * 60; // 1080 minutes
                ?>

                <!-- AUDITORIUM MATRIX GRID (Consolidated Screen Timeline) -->
                <div class="auditorium-matrix-section glass-card" style="margin-bottom: 35px; padding: 25px; overflow-x: auto;">
                    <h3 style="margin-top: 0; margin-bottom: 10px; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                        <span>📊</span> Consolidated Screen Timeline
                    </h3>
                    <p style="color: var(--text-secondary); margin-bottom: 25px; font-size: 0.9rem; margin-top: 0;">
                        Consolidated room schedule (10:00 AM - 4:00 AM next day). Hover on any movie capsule for details, click to view seats.
                    </p>
                    
                    <div class="consolidated-timeline" style="min-width: 900px; display: table; width: 100%;">
                        <!-- Timeline Ticks Header Row -->
                        <div class="timeline-header-row" style="display: table-row;">
                            <div class="timeline-row-label" style="display: table-cell; width: 130px; padding-right: 15px;"></div>
                            <div class="timeline-ticks-container" style="display: table-cell; position: relative; height: 35px; border-bottom: 1.5px solid var(--border-primary);">
                                <?php for ($i = 0; $i <= $total_timeline_hours; $i++): ?>
                                    <?php
                                    $tick_timestamp = $timeline_start_time + ($i * 3600);
                                    $tick_label = date('gA', $tick_timestamp);
                                    $tick_left = ($i / $total_timeline_hours) * 100;
                                    ?>
                                    <div class="timeline-tick" style="position: absolute; left: <?php echo $tick_left; ?>%; height: 100%; top: 0;">
                                        <div style="position: absolute; bottom: 0; left: 0; width: 1px; height: 8px; background-color: var(--border-primary);"></div>
                                        <span class="timeline-tick-label" style="position: absolute; bottom: 10px; left: 0; transform: translateX(-50%); font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); white-space: nowrap; font-family: 'Space Grotesk', sans-serif;">
                                            <?php echo $tick_label; ?>
                                        </span>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- Rows for screens -->
                        <?php foreach ($auditorium_matrix as $audName => $sessions): ?>
                            <div class="timeline-screen-row" style="display: table-row; height: 42px;">
                                <div class="timeline-row-label" style="display: table-cell; vertical-align: middle; width: 130px; padding-right: 15px; font-weight: 700; color: var(--text-primary); font-size: 0.9rem; white-space: nowrap; border-bottom: 1px solid var(--border-primary);">
                                    🏛️ <?php echo htmlspecialchars($audName); ?>
                                </div>
                                <div class="timeline-bar-track" style="display: table-cell; vertical-align: middle; position: relative; border-bottom: 1px solid var(--border-primary); background: rgba(0,0,0,0.02);">
                                    <?php foreach ($sessions as $session): ?>
                                        <?php
                                        // Calculations
                                        $duration_with_preroll = $session['runtime'] + $preroll_minutes;
                                        $sess_start_time = strtotime($session['start_iso']) - ($preroll_minutes * 60);
                                        $sess_end_time = $sess_start_time + ($duration_with_preroll * 60);
                                        
                                        $offset_mins = ($sess_start_time - $timeline_start_time) / 60;
                                        $left_percent = ($offset_mins / $total_timeline_minutes) * 100;
                                        $width_percent = ($duration_with_preroll / $total_timeline_minutes) * 100;
                                        
                                        // Bound checking
                                        if ($left_percent < 0) {
                                            $width_percent += $left_percent;
                                            $left_percent = 0;
                                        }
                                        if ($width_percent < 0) $width_percent = 0;
                                        if (($left_percent + $width_percent) > 100) {
                                            $width_percent = 100 - $left_percent;
                                        }
                                        
                                        $preroll_percent = ($preroll_minutes / $duration_with_preroll) * 100;
                                        $movie_percent = ($session['runtime'] / $duration_with_preroll) * 100;
                                        
                                        $movie_color = getMovieColor($session['movie']);
                                        ?>
                                        
                                        <div class="timeline-bar view-seats-btn"
                                             style="left: <?php echo $left_percent; ?>%; width: <?php echo $width_percent; ?>%; position: absolute; height: 28px; top: 7px; display: flex; border-radius: 6px; overflow: hidden; border: 1px solid rgba(0,0,0,0.15); cursor: pointer; box-shadow: var(--shadow-sm); transition: transform 0.2s;"
                                             data-theatre-id="<?php echo $location_id; ?>"
                                             data-showtime-id="<?php echo $session['session_id']; ?>"
                                             data-movie-name="<?php echo htmlspecialchars($session['movie']); ?>"
                                             data-movie-time="<?php echo $session['start_formatted']; ?>"
                                             data-auditorium="<?php echo htmlspecialchars($audName); ?>"
                                             title="<?php echo htmlspecialchars($session['movie']); ?> (<?php echo $session['start_formatted']; ?> - <?php echo date('g:i A', $sess_end_time); ?>)">
                                            
                                            <!-- Preroll bar -->
                                            <span class="timeline-bar-preroll" style="width: <?php echo $preroll_percent; ?>%; background: #a0aec0; height: 100%; opacity: 0.75;"></span>
                                            
                                            <!-- Movie bar -->
                                            <span class="timeline-bar-movie" style="width: <?php echo $movie_percent; ?>%; background-color: <?php echo $movie_color; ?>; height: 100%; display: flex; align-items: center; padding-left: 8px; overflow: hidden;">
                                                <span class="timeline-bar-label" style="color: #fff; font-size: 0.75rem; font-weight: 700; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;">
                                                    <?php echo htmlspecialchars($session['movie']); ?>
                                                </span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- SECTION 2: DATA DASHBOARD -->
                <?php
                // Flatten all sessions for the data table
                $all_sessions = [];
                foreach ($showtimes_data as $movie) {
                    $movieTitle = $movie['name'] ?? 'Unknown';
                    $runtime = $movie['runtimeInMinutes'] ?? $movie['duration'] ?? 120;
                    if (!empty($movie['experiences'])) {
                        foreach ($movie['experiences'] as $exp) {
                            $expName = implode(', ', $exp['experienceTypes'] ?? []);
                            if (!empty($exp['sessions'])) {
                                foreach ($exp['sessions'] as $session) {
                                    $audName = $session['auditorium'] ?? $session['auditoriumName'] ?? 'Auditorium';
                                    $start_time = strtotime($session['showStartDateTime']);
                                    
                                    $all_sessions[] = [
                                        'movie' => $movieTitle,
                                        'start' => $start_time,
                                        'start_formatted' => date('g:i A', $start_time),
                                        'experience' => $expName,
                                        'runtime' => $runtime,
                                        'auditorium' => $audName,
                                        'session_id' => $session['vistaSessionId']
                                    ];
                                }
                            }
                        }
                    }
                }
                
                // Sort chronologically
                usort($all_sessions, function($a, $b) {
                    return $a['start'] <=> $b['start'];
                });
                ?>

                <!-- DASHBOARD WIDGETS -->
                <div class="dashboard-widgets" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                    <div class="widget glass-card" style="padding: 20px; text-align: center;">
                        <h4 style="margin: 0; color: var(--text-secondary); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px;">Total Showtimes Today</h4>
                        <div style="font-size: 2.5rem; font-weight: 800; color: var(--theme-primary); line-height: 1; margin-top: 10px;"><?php echo count($all_sessions); ?></div>
                    </div>
                    <div class="widget glass-card" style="padding: 20px; text-align: center;">
                        <h4 style="margin: 0; color: var(--text-secondary); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px;">Active Watchlist</h4>
                        <div style="font-size: 2.5rem; font-weight: 800; color: var(--theme-accent); line-height: 1; margin-top: 10px;"><?php echo count($active_trackers); ?></div>
                    </div>
                    <div class="widget glass-card" style="padding: 20px; text-align: center;">
                        <h4 style="margin: 0; color: var(--text-secondary); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px;">High Occupancy Alerts</h4>
                        <div style="font-size: 2.5rem; font-weight: 800; color: #ef4444; line-height: 1; margin-top: 10px;" id="high-occupancy-count">0</div>
                    </div>
                </div>

                <!-- DASHBOARD DATA TABLE -->
                <div class="data-table-container glass-card" style="overflow-x: auto; margin-bottom: 30px;">
                    <table class="dashboard-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border-light);">
                                <th style="padding: 15px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Time</th>
                                <th style="padding: 15px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Movie</th>
                                <th style="padding: 15px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Format</th>
                                <th style="padding: 15px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Auditorium</th>
                                <th style="padding: 15px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; width: 250px;">Occupancy Heatmap</th>
                                <th style="padding: 15px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_sessions as $session): 
                                $is_tracked = in_array($session['session_id'], $active_trackers);
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-light); transition: background 0.2s;" class="session-row" onmouseover="this.style.background='var(--bg-tertiary)'" onmouseout="this.style.background='transparent'">
                                <td style="padding: 15px; font-weight: 700; font-family: monospace; font-size: 1.1rem; color: var(--text-primary);">
                                    <?php echo $session['start_formatted']; ?>
                                    <?php if ($is_tracked): ?>
                                        <span title="Tracked" style="font-size: 0.8rem;">📡</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 15px; font-weight: 600; color: var(--text-primary);">
                                    <?php echo htmlspecialchars($session['movie']); ?>
                                </td>
                                <td style="padding: 15px;">
                                    <span class="badge" style="background: var(--bg-tertiary); font-size: 0.75rem; font-weight: 700; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-primary); color: var(--text-secondary);"><?php echo htmlspecialchars($session['experience'] ?: 'Standard'); ?></span>
                                </td>
                                <td style="padding: 15px; color: var(--text-secondary);">
                                    <?php echo htmlspecialchars($session['auditorium']); ?>
                                </td>
                                <td style="padding: 15px; vertical-align: middle;">
                                    <div class="occupancy-tracker" data-theatre-id="<?php echo $location_id; ?>" data-showtime-id="<?php echo $session['session_id']; ?>" style="width: 100%; height: 8px; background: var(--bg-primary); border-radius: 4px; overflow: hidden; border: 1px solid var(--border-light);">
                                        <div class="occupancy-fill" style="width: 0%; height: 100%; background: var(--text-tertiary); transition: width 0.8s ease-out, background 0.5s ease-out;"></div>
                                    </div>
                                    <div class="ascii-capacity-bar" style="margin-top: 4px;"></div>
                                </td>
                                <td style="padding: 15px; text-align: right;">
                                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                        <button class="button-primary view-seats-btn"
                                                data-theatre-id="<?php echo $location_id; ?>"
                                                data-showtime-id="<?php echo $session['session_id']; ?>"
                                                data-movie-name="<?php echo htmlspecialchars($session['movie']); ?>"
                                                data-movie-time="<?php echo $session['start_formatted']; ?>"
                                                data-auditorium="<?php echo htmlspecialchars($session['auditorium']); ?>"
                                                style="padding: 6px 12px; font-size: 0.8rem;">🎟 View Seats</button>
                                        <button class="button-secondary" style="padding: 6px 12px; font-size: 0.8rem;"
                                                onclick="shareWatchParty('<?php echo $location_id; ?>', '<?php echo $session['session_id']; ?>', '<?php echo htmlspecialchars(addslashes($session['movie'])); ?>')">
                                                🔗 Share
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </main>
    </div>

    <!-- SEAT MAP MODAL WINDOW -->
    <div id="live-map-modal" class="modal-overlay">
        <div class="modal-content glass-card" style="max-width: 750px; width: 90%;">
            <button id="modal-close-btn" class="modal-close" aria-label="Close modal">&times;</button>
            <div id="live-map-render-area" class="modal-body-content">
                <!-- Rendered dynamically by assets/js/main.js -->
            </div>
        </div>
    </div>

    <!-- Floating tooltip for seat maps -->
    <div id="tooltip" class="tooltip" style="display: none; position: absolute; background: rgba(0,0,0,0.85); color: white; padding: 6px 12px; border-radius: 4px; font-size: 0.8rem; pointer-events: none; z-index: 10000; box-shadow: var(--shadow-md);"></div>

    <!-- Client-side Scripts -->
    <script src="assets/js/shared.js?v=<?php echo time(); ?>" defer></script>
    <script src="assets/js/design-options-modal.js?v=<?php echo time(); ?>" defer></script>
    <script src="assets/js/main.js?v=<?php echo time(); ?>" defer></script>
</body>
</html>