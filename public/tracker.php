<?php
/**
 * Cinepulse — Showtime Occupancy Tracker & Scrubber Dashboard
 */

// Initialize Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

Security::startSession();

// Setup DB connection
$db_configured = true;
$db_error = '';
$tables_missing = false;
$pdo = null;

try {
    $pdo = Cinepulse\Database::getInstance()->getConnection();
    // Validate schemas
    $stmt1 = $pdo->query("SHOW TABLES LIKE 'tracked_showtimes'");
    if ($stmt1->rowCount() === 0) {
        $tables_missing = true;
    }
} catch (Exception $e) {
    $db_configured = false;
    $db_error = $e->getMessage();
}

// Fetch locations list
$locations = [];
$locFile = dirname(__DIR__) . '/config/locations.json';
if (file_exists($locFile)) {
    $locations = json_decode(file_get_contents($locFile), true) ?: [];
}

// Browser selections
$location_id = Security::sanitizeInput($_GET['locationId'] ?? 7411, 'int');
$date = Security::sanitizeInput($_GET['date'] ?? date('Y-m-d'), 'date');
$fetch_showtimes = isset($_GET['fetch_showtimes']) || isset($_GET['locationId']);

// Call API
$showtimes_data = [];
$api_error = '';
if ($fetch_showtimes) {
    try {
        $api = new CineplexAPI();
        $cineplex_date = date('m+d+Y', strtotime($date));
        $raw_showtimes = $api->fetchShowtimes($location_id, $cineplex_date);
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

// Query Active, Completed, and Movie Release Trackers
$active_monitors = [];
$completed_monitors = [];
$movie_release_trackers = [];
$release_alerts = [];

if ($db_configured && !$tables_missing) {
    try {
        $trackerService = new Cinepulse\TrackerService();
        $movie_release_trackers = $trackerService->getMovieTrackers();
        $release_alerts = $trackerService->getReleaseAlerts();


        // Query Active
        $stmt_act = $pdo->prepare("SELECT t.*, 
            (SELECT occupancy_percentage FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as last_occupancy,
            (SELECT snapshot_time FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as last_snapshot_time,
            (SELECT seats_occupied FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as last_seats_occupied,
            (SELECT calculated_capacity FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as capacity,
            (SELECT COUNT(*) FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id) as snapshot_count
            FROM tracked_showtimes t 
            WHERE t.status = 'active'
            ORDER BY t.show_start_time ASC");
        $stmt_act->execute();
        $active_monitors = $stmt_act->fetchAll() ?: [];

        // Query Completed
        $stmt_comp = $pdo->prepare("SELECT t.*, 
            (SELECT occupancy_percentage FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as last_occupancy,
            (SELECT snapshot_time FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as last_snapshot_time,
            (SELECT seats_occupied FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as last_seats_occupied,
            (SELECT calculated_capacity FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id ORDER BY snapshot_time DESC LIMIT 1) as capacity,
            (SELECT COUNT(*) FROM showtime_snapshots_history WHERE tracked_showtime_id = t.id) as snapshot_count
            FROM tracked_showtimes t 
            WHERE t.status = 'completed'
            ORDER BY t.show_start_time DESC LIMIT 20");
        $stmt_comp->execute();
        $completed_monitors = $stmt_comp->fetchAll() ?: [];
    } catch (Exception $e) {
        $db_error = 'Failed to load tracking data: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>📊 Cinepulse — Showtime Tracker Dashboard</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎬</text></svg>">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; }
        .main-wrapper { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .nav-links {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        .nav-links a {
            color: white;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 18px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background 0.2s;
        }
        .nav-links a:hover { background: rgba(255, 255, 255, 0.35); }
        .nav-links a.active {
            background: var(--theme-primary);
            box-shadow: var(--shadow-sm);
        }
        .db-warning {
            background: linear-gradient(135deg, var(--color-error-light) 0%, var(--color-error) 100%);
            color: white; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px;
        }
        .db-warning h3 { margin: 0; color: white; }
        
        .tracker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .tracker-card {
            border-left: 5px solid var(--theme-primary);
            position: relative;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .tracker-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .tracker-card.completed { border-left-color: var(--color-success); }
        .tracker-card.failed { border-left-color: var(--color-error); }
        
        .tracker-card-title {
            font-size: 1.15rem; font-weight: 700; margin: 0 0 10px 0; color: var(--text-primary);
        }
        .tracker-card-meta { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 6px; }
        .tracker-card-badge {
            display: inline-block; padding: 4px 10px; font-size: 0.75rem; font-weight: 700; border-radius: 12px;
            background: var(--bg-tertiary); color: var(--text-secondary); margin-top: 8px;
        }
        .tracker-card-badge.active-badge { background: rgba(59, 130, 246, 0.2); color: #2563eb; }
        .tracker-card-badge.completed-badge { background: rgba(16, 185, 129, 0.2); color: #059669; }
        
        /* Analysis Modal Split Panel Layout */
        .analysis-split { display: flex; flex-wrap: wrap; gap: 20px; min-height: 520px; }
        .analysis-chart-side { flex: 1 1 500px; display: flex; flex-direction: column; justify-content: space-between; }
        .analysis-seatmap-side {
            flex: 1 1 500px; display: flex; flex-direction: column; border-left: 2px solid var(--border-light); padding-left: 20px;
        }
        .timeline-scrubber {
            background: var(--bg-secondary); padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 15px;
        }
        .timeline-scrubber input[type="range"] { flex: 1; cursor: pointer; }
        .timeline-scrubber button { padding: 6px 12px; font-size: 0.85rem; }
        .history-table-container {
            max-height: 180px; overflow-y: auto; margin-top: 15px; border: 1px solid var(--border-light); border-radius: 8px;
        }
        
        .session-grid-tracker {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 15px; margin-top: 15px;
        }
        .session-card-tracker {
            background: var(--bg-secondary); border: 1px solid var(--border-light); border-radius: 8px; padding: 15px;
            display: flex; flex-direction: column; justify-content: space-between; align-items: flex-start;
        }
        
        /* View Switcher Controls */
        .view-switcher-group {
            display: inline-flex; background: var(--bg-tertiary); padding: 3px; border-radius: 20px; border: 1px solid var(--border-light);
        }
        .view-switcher-btn {
            background: transparent; border: none; color: var(--text-secondary); padding: 5px 14px; font-size: 0.8rem; font-weight: 600; border-radius: 16px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 6px;
        }
        .view-switcher-btn:hover { color: var(--text-primary); }
        .view-switcher-btn.active { background: var(--theme-primary); color: white; box-shadow: var(--shadow-sm); }
        
        /* Occupancy Progress Bar */
        .occupancy-bar-track {
            width: 100%; height: 8px; background: rgba(255, 255, 255, 0.08); border-radius: 10px; overflow: hidden; margin-top: 6px; margin-bottom: 8px; border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .occupancy-bar-fill {
            height: 100%; border-radius: 10px; transition: width 0.4s ease;
        }
        .occupancy-low { background: linear-gradient(90deg, #10b981 0%, #34d399 100%); }
        .occupancy-mid { background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 100%); }
        .occupancy-high { background: linear-gradient(90deg, #ef4444 0%, #f87171 100%); }
        
        /* Countdown Badge */
        .countdown-badge {
            display: inline-flex; align-items: center; gap: 4px; font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 12px; background: var(--bg-tertiary); border: 1px solid var(--border-light); color: var(--text-secondary);
        }
        .countdown-today { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border-color: rgba(245, 158, 11, 0.3); }
        .countdown-upcoming { background: rgba(59, 130, 246, 0.15); color: #3b82f6; border-color: rgba(59, 130, 246, 0.3); }
        .countdown-past { background: rgba(107, 114, 128, 0.15); color: #9ca3af; border-color: rgba(107, 114, 128, 0.3); }

        /* Data Table Styling */
        .monitors-datatable {
            width: 100%; border-collapse: separate; border-spacing: 0; text-align: left;
        }
        .monitors-datatable th {
            background: var(--bg-tertiary); color: var(--text-secondary); font-weight: 600; padding: 12px 16px; border-bottom: 1px solid var(--border-light); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .monitors-datatable td {
            padding: 12px 16px; border-bottom: 1px solid var(--border-light); font-size: 0.85rem; color: var(--text-primary); vertical-align: middle;
        }
        .monitors-datatable tr:hover td { background: rgba(255, 255, 255, 0.02); }
        .monitors-datatable tr:last-child td { border-bottom: none; }
        
        .sortable-col {
            cursor: pointer; user-select: none; transition: color 0.2s ease;
        }
        .sortable-col:hover {
            color: var(--theme-primary); background: rgba(255, 255, 255, 0.04);
        }
        .sortable-col .sort-icon {
            font-size: 0.75rem; opacity: 0.5; margin-left: 4px; display: inline-block; transition: opacity 0.2s ease;
        }
        .sortable-col.asc .sort-icon,
        .sortable-col.desc .sort-icon {
            opacity: 1; color: var(--theme-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>🎬 Cinepulse</h1>
                <p>Command Center Analytics</p>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php">📅 Schedule</a>
                <a href="movies.php">🎬 Movies</a>
                <a href="double-feature.php">🍿 Planner</a>
                <a href="tracker.php" class="active">📈 Tracker</a>
                <a href="dashboard.php">📊 Dashboard</a>
                <a href="tracker_scan_logs.php">🔍 Scan Logs</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">

        <!-- Database Check Warnings -->
        <?php if (!$db_configured): ?>
            <div class="db-warning">
                <h3>⚠ DB connection failed</h3>
                <p>Tracking capabilities cannot be used. Config error: <?php echo htmlspecialchars($db_error); ?></p>
            </div>
            <?php exit; ?>
        <?php elseif ($tables_missing): ?>
            <div class="db-warning" style="background: linear-gradient(135deg, var(--color-warning-light) 0%, var(--color-warning) 100%);">
                <h3>⚠ Setup Database Tables</h3>
                <p>Please initialize schemas inside <code>cinepluse/schema.sql</code> on your MySQL server to continue.</p>
            </div>
            <?php exit; ?>
        <?php endif; ?>

        <!-- SECTION 1: ACTIVE MONITOR LISTINGS -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-light); padding-bottom: 12px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <h2 style="margin: 0;">📡 Active Occupancy Monitors</h2>
            
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <!-- Live Search Filter Input -->
                <div style="position: relative; min-width: 240px;">
                    <input type="text" id="monitor-search-input" placeholder="Search title, theater, date..." style="padding: 6px 14px 6px 14px; font-size: 0.85rem; border-radius: 20px; border: 1px solid var(--border-light); background: var(--bg-tertiary); color: var(--text-primary); outline: none; width: 100%;">
                </div>

                <!-- View Switcher -->
                <div class="view-switcher-group">
                    <button class="view-switcher-btn active" data-view="grid"><span>🔲</span> Grid</button>
                    <button class="view-switcher-btn" data-view="table"><span>📑</span> Table</button>
                </div>
                
                <?php if (!empty($active_monitors)): ?>
                    <button id="snapshot-all-active-btn" class="button-primary" style="font-size: 0.85rem; padding: 6px 14px; border-radius: 20px; cursor: pointer;">📸 Snapshot All Active</button>
                <?php endif; ?>
            </div>
        </div>
        <?php if (empty($active_monitors)): ?>
            <div class="notice notice-info" style="margin-bottom: 40px;">
                No active showtime monitors running currently. Search below to add a new showtime monitor.
            </div>
        <?php else: ?>
            <!-- GRID VIEW (CARDS) -->
            <div id="monitors-grid-container" class="tracker-grid">
                <?php foreach ($active_monitors as $monitor): 
                    $show_time = strtotime($monitor['show_start_time']);
                    $full_showtime_date = date('l, F j, Y @ g:i A', $show_time);
                    
                    // Countdown calculation
                    $now_time = time();
                    $diff_seconds = $show_time - $now_time;
                    if ($diff_seconds < 0) {
                        $countdown_text = "Past showtime";
                        $countdown_class = "countdown-past";
                    } elseif ($diff_seconds < 86400 && date('Y-m-d', $show_time) === date('Y-m-d', $now_time)) {
                        $countdown_text = "Starts today";
                        $countdown_class = "countdown-today";
                    } else {
                        $days = ceil($diff_seconds / 86400);
                        $countdown_text = "In {$days} day" . ($days > 1 ? 's' : '');
                        $countdown_class = "countdown-upcoming";
                    }
                    
                    // Relative snapshot time
                    $last_snap_str = 'No snapshots logged';
                    if (!empty($monitor['last_snapshot_time'])) {
                        $snap_secs = time() - strtotime($monitor['last_snapshot_time']);
                        if ($snap_secs < 60) {
                            $last_snap_str = 'just now';
                        } elseif ($snap_secs < 3600) {
                            $mins = floor($snap_secs / 60);
                            $last_snap_str = "{$mins}m ago";
                        } elseif ($snap_secs < 86400) {
                            $hours = floor($snap_secs / 3600);
                            $last_snap_str = "{$hours}h ago";
                        } else {
                            $last_snap_str = date('M j, g:i a', strtotime($monitor['last_snapshot_time']));
                        }
                    }
                    
                    // Occupancy fill
                    $occ = ($monitor['last_occupancy'] !== null) ? (float)$monitor['last_occupancy'] : null;
                    $occ_class = 'occupancy-low';
                    if ($occ !== null) {
                        if ($occ > 75) {
                            $occ_class = 'occupancy-high';
                        } elseif ($occ > 35) {
                            $occ_class = 'occupancy-mid';
                        }
                    }
                ?>
                    <div class="tracker-card glass-card">
                        <input type="checkbox" class="tracker-bulk-checkbox" value="<?php echo $monitor['id']; ?>" style="position: absolute; top: 15px; right: 15px; width: 18px; height: 18px; cursor: pointer; z-index: 10; accent-color: var(--theme-primary);">
                        
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                            <span class="countdown-badge <?php echo $countdown_class; ?>">⏱ <?php echo $countdown_text; ?></span>
                            <span class="tracker-card-badge active-badge" style="position: static; margin: 0;">ACTIVE</span>
                        </div>

                        <div class="tracker-card-title" style="padding-right: 35px; font-size: 1.1rem; font-weight: 700; margin-bottom: 6px;"><?php echo htmlspecialchars($monitor['movie_name']); ?></div>
                        
                        <div class="tracker-card-meta" style="font-size: 0.88rem; margin-bottom: 4px;">🏢 <strong><?php echo htmlspecialchars($monitor['theatre_name']); ?></strong></div>
                        <div class="tracker-card-meta" style="font-size: 0.88rem; margin-bottom: 10px; color: var(--theme-primary); font-weight: 600;">🗓 <?php echo $full_showtime_date; ?></div>

                        <!-- Occupancy Progress Bar -->
                        <div style="margin-top: 10px; margin-bottom: 12px; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; border: 1px solid var(--border-light);">
                            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; margin-bottom: 2px;">
                                <span style="color: var(--text-secondary);">Occupancy Level</span>
                                <strong style="color: var(--text-primary);"><?php echo ($occ !== null) ? number_format($occ, 1) . '%' : 'N/A'; ?></strong>
                            </div>
                            <div class="occupancy-bar-track">
                                <div class="occupancy-bar-fill <?php echo $occ_class; ?>" style="width: <?php echo ($occ !== null) ? min(100, max(5, $occ)) : 0; ?>%;"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; color: var(--text-muted);">
                                <span><?php echo ($monitor['last_seats_occupied'] !== null) ? $monitor['last_seats_occupied'] . ' occupied' : 'No seats data'; ?></span>
                                <span>Cap: <?php echo ($monitor['capacity'] ?? 'N/A'); ?> seats</span>
                            </div>
                        </div>

                        <div class="tracker-card-meta" style="font-size: 0.82rem; color: var(--text-secondary);">
                            📸 Snapshots: <strong><?php echo $monitor['snapshot_count']; ?></strong> logged <span style="color: var(--text-muted);">(<?php echo $last_snap_str; ?>)</span>
                        </div>

                        <div style="margin-top: 15px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <button class="button-secondary single-snapshot-btn" data-tracker-id="<?php echo $monitor['id']; ?>" style="padding: 6px 12px; font-size: 0.85rem; background: var(--bg-tertiary); border: 1px solid var(--border-light); color: var(--text-primary); cursor: pointer; border-radius: 6px;">📸 Snapshot</button>
                            <button class="button-primary view-analysis-btn" data-tracker-id="<?php echo $monitor['id']; ?>" data-movie-name="<?php echo htmlspecialchars($monitor['movie_name']); ?>" style="padding: 6px 12px; font-size: 0.85rem;">📊 Analyze</button>
                            <button class="button-danger delete-tracker-btn" data-tracker-id="<?php echo $monitor['id']; ?>" style="padding: 6px 12px; font-size: 0.85rem; background: var(--color-error); border-color: var(--color-error); color: white;">❌ Stop</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- TABLE VIEW (DATA TABLE) -->
            <div id="monitors-table-container" style="display: none; margin-bottom: 40px;" class="glass-card">
                <div style="overflow-x: auto;">
                    <table class="monitors-datatable">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;">
                                    <input type="checkbox" id="table-select-all" style="width: 16px; height: 16px; cursor: pointer; accent-color: var(--theme-primary);">
                                </th>
                                <th class="sortable-col" data-sort-type="string" data-col-key="movie">Movie Title <span class="sort-icon">↕</span></th>
                                <th class="sortable-col" data-sort-type="string" data-col-key="theatre">Theatre Location <span class="sort-icon">↕</span></th>
                                <th class="sortable-col" data-sort-type="number" data-col-key="date">Full Showtime Date & Time <span class="sort-icon">↕</span></th>
                                <th class="sortable-col" data-sort-type="number" data-col-key="diff">Status / Countdown <span class="sort-icon">↕</span></th>
                                <th class="sortable-col" data-sort-type="number" data-col-key="occupancy">Occupancy Level <span class="sort-icon">↕</span></th>
                                <th class="sortable-col" data-sort-type="number" data-col-key="snapshots">Snapshot Logs <span class="sort-icon">↕</span></th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_monitors as $monitor): 
                                $show_time = strtotime($monitor['show_start_time']);
                                $full_showtime_date = date('D, M j, Y @ g:i A', $show_time);
                                
                                $now_time = time();
                                $diff_seconds = $show_time - $now_time;
                                if ($diff_seconds < 0) {
                                    $countdown_text = "Past";
                                    $countdown_class = "countdown-past";
                                } elseif ($diff_seconds < 86400 && date('Y-m-d', $show_time) === date('Y-m-d', $now_time)) {
                                    $countdown_text = "Today";
                                    $countdown_class = "countdown-today";
                                } else {
                                    $days = ceil($diff_seconds / 86400);
                                    $countdown_text = "In {$days}d";
                                    $countdown_class = "countdown-upcoming";
                                }
                                
                                $occ = ($monitor['last_occupancy'] !== null) ? (float)$monitor['last_occupancy'] : null;
                                $occ_class = 'occupancy-low';
                                if ($occ !== null) {
                                    if ($occ > 75) {
                                        $occ_class = 'occupancy-high';
                                    } elseif ($occ > 35) {
                                        $occ_class = 'occupancy-mid';
                                    }
                                }
                            ?>
                                <tr data-movie="<?php echo htmlspecialchars(strtolower($monitor['movie_name'])); ?>"
                                    data-theatre="<?php echo htmlspecialchars(strtolower($monitor['theatre_name'])); ?>"
                                    data-date="<?php echo $show_time; ?>"
                                    data-diff="<?php echo $diff_seconds; ?>"
                                    data-occupancy="<?php echo ($occ !== null) ? $occ : -1; ?>"
                                    data-snapshots="<?php echo (int)$monitor['snapshot_count']; ?>">
                                    <td style="text-align: center;">
                                        <input type="checkbox" class="tracker-bulk-checkbox" value="<?php echo $monitor['id']; ?>" style="width: 16px; height: 16px; cursor: pointer; accent-color: var(--theme-primary);">
                                    </td>
                                    <td>
                                        <strong style="color: var(--text-primary); font-size: 0.95rem;"><?php echo htmlspecialchars($monitor['movie_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span style="color: var(--text-secondary);"><?php echo htmlspecialchars($monitor['theatre_name']); ?></span>
                                    </td>
                                    <td>
                                        <strong style="color: var(--theme-primary);"><?php echo $full_showtime_date; ?></strong>
                                    </td>
                                    <td>
                                        <span class="countdown-badge <?php echo $countdown_class; ?>">⏱ <?php echo $countdown_text; ?></span>
                                    </td>
                                    <td style="width: 140px;">
                                        <div style="display: flex; justify-content: space-between; font-size: 0.78rem; font-weight: 600;">
                                            <span><?php echo ($occ !== null) ? number_format($occ, 1) . '%' : 'N/A'; ?></span>
                                            <span style="color: var(--text-muted);"><?php echo ($monitor['last_seats_occupied'] ?? 0); ?>/<?php echo ($monitor['capacity'] ?? '?'); ?></span>
                                        </div>
                                        <div class="occupancy-bar-track" style="margin-top: 3px; margin-bottom: 0;">
                                            <div class="occupancy-bar-fill <?php echo $occ_class; ?>" style="width: <?php echo ($occ !== null) ? min(100, max(5, $occ)) : 0; ?>%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600;"><?php echo $monitor['snapshot_count']; ?></span> <span style="font-size: 0.78rem; color: var(--text-muted);">logged</span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <button class="button-secondary single-snapshot-btn" data-tracker-id="<?php echo $monitor['id']; ?>" style="padding: 4px 8px; font-size: 0.78rem; background: var(--bg-tertiary); border: 1px solid var(--border-light); cursor: pointer; border-radius: 4px;" title="Take Snapshot">📸</button>
                                            <button class="button-primary view-analysis-btn" data-tracker-id="<?php echo $monitor['id']; ?>" data-movie-name="<?php echo htmlspecialchars($monitor['movie_name']); ?>" style="padding: 4px 8px; font-size: 0.78rem;" title="Analyze Logs">📊</button>
                                            <button class="button-danger delete-tracker-btn" data-tracker-id="<?php echo $monitor['id']; ?>" style="padding: 4px 8px; font-size: 0.78rem; background: var(--color-error); border-color: var(--color-error); color: white;" title="Stop Monitor">❌</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- SECTION 1.5: AUTOMATIC MOVIE TRACKERS -->
        <h2 style="border-bottom: 2px solid var(--border-light); padding-bottom: 8px; margin-bottom: 20px;">🎬 Automatic Movie Release Trackers</h2>
        
        <div class="movie-trackers-container" style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 40px;">
            <!-- Create Movie Tracker Form -->
            <div class="glass-card" style="flex: 1 1 320px; padding: 20px; align-self: start;">
                <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 1.15rem; color: var(--text-primary);">➕ Create Movie Tracker</h3>
                <form id="movie-tracker-form">
                    <div class="form-group" style="margin-bottom: 15px; display: flex; flex-direction: column;">
                        <label for="mt_movie_name" style="margin-bottom: 5px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary);">🎬 Movie Title Pattern:</label>
                        <input type="text" id="mt_movie_name" name="movie_name" placeholder="e.g. Odyssey, Avengers" required style="padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-light); background: var(--bg-tertiary); color: var(--text-primary); outline: none;">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 15px; display: flex; flex-direction: column;">
                        <label for="mt_location_id" style="margin-bottom: 5px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary);">🏢 Theatre Location:</label>
                        <select name="location_id" id="mt_location_id" style="padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-light); background: var(--bg-tertiary); color: var(--text-primary); outline: none;">
                            <?php foreach ($locations as $name => $id): ?>
                                <option value="<?php echo $id; ?>">
                                    <?php echo htmlspecialchars($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 15px; display: flex; flex-direction: column;">
                        <label for="mt_experience" style="margin-bottom: 5px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary);">🍿 Experience Filter (Optional):</label>
                        <input type="text" id="mt_experience" name="experience_filter" placeholder="e.g. IMAX 70mm, VIP" style="padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-light); background: var(--bg-tertiary); color: var(--text-primary); outline: none;">
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                        <div class="form-group" style="flex: 1; display: flex; flex-direction: column;">
                            <label for="mt_start_date" style="margin-bottom: 5px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary);">📅 Start Date:</label>
                            <input type="date" id="mt_start_date" name="start_date" min="<?php echo date('Y-m-d'); ?>" style="padding: 8px; border-radius: 6px; border: 1px solid var(--border-light); background: var(--bg-tertiary); color: var(--text-primary); outline: none; font-size: 0.85rem;">
                        </div>
                        <div class="form-group" style="flex: 1; display: flex; flex-direction: column;">
                            <label for="mt_end_date" style="margin-bottom: 5px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary);">📅 End Date:</label>
                            <input type="date" id="mt_end_date" name="end_date" min="<?php echo date('Y-m-d'); ?>" style="padding: 8px; border-radius: 6px; border: 1px solid var(--border-light); background: var(--bg-tertiary); color: var(--text-primary); outline: none; font-size: 0.85rem;">
                        </div>
                    </div>
                    
                    <button type="submit" class="button-primary" style="width: 100%; border-radius: 6px;">📡 Add Release Tracker</button>
                </form>
            </div>
            
            <!-- Movie Trackers List -->
            <div style="flex: 2 1 500px;">
                <?php if (empty($movie_release_trackers)): ?>
                    <div class="notice notice-info" style="height: 100%; display: flex; align-items: center; justify-content: center; margin: 0; min-height: 250px;">
                        No automatic movie trackers registered. Create one on the left to automatically track future showtime releases!
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                        <?php foreach ($movie_release_trackers as $mt): 
                            $is_active = $mt['status'] === 'active';
                        ?>
                            <div class="glass-card movie-tracker-card" style="padding: 20px; position: relative; border-left: 5px solid <?php echo $is_active ? 'var(--theme-primary)' : 'var(--color-gray-400)'; ?>; transition: transform 0.2s;">
                                <div style="font-weight: 700; font-size: 1.15rem; margin-bottom: 8px; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-right: 50px;">
                                    <?php echo htmlspecialchars($mt['movie_name']); ?>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 6px;">
                                    🏢 Theater: <strong><?php echo htmlspecialchars($mt['theatre_name']); ?></strong>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 6px;">
                                    📅 Scan Window: <strong>
                                    <?php 
                                    if ($mt['start_date'] && $mt['end_date']) {
                                        echo date('M d', strtotime($mt['start_date'])) . ' to ' . date('M d', strtotime($mt['end_date']));
                                    } elseif ($mt['start_date']) {
                                        echo 'From ' . date('M d', strtotime($mt['start_date']));
                                    } else {
                                        echo 'Next 7 Days';
                                    }
                                    ?>
                                    </strong>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px;">
                                    🍿 Experience: <span class="badge" style="background: var(--bg-tertiary); color: var(--text-secondary); border: 1px solid var(--border-light); font-size: 0.7rem; padding: 2px 6px; font-weight: 600;"><?php echo $mt['experience_filter'] ? htmlspecialchars($mt['experience_filter']) : 'Any Format'; ?></span>
                                </div>
                                
                                <div style="display: flex; gap: 8px; align-items: center; margin-top: 15px; flex-wrap: wrap;">
                                    <button class="button-secondary scan-movie-tracker-btn" 
                                            data-tracker-id="<?php echo $mt['id']; ?>"
                                            style="padding: 4px 8px; font-size: 0.75rem; border-radius: 4px;">🔄 Scan Now</button>
                                    
                                    <button class="button-secondary toggle-movie-tracker-btn" 
                                            data-tracker-id="<?php echo $mt['id']; ?>"
                                            data-status="<?php echo $mt['status']; ?>"
                                            style="padding: 4px 8px; font-size: 0.75rem; border-radius: 4px;">
                                        <?php echo $is_active ? '⏸ Pause' : '▶ Resume'; ?>
                                    </button>
                                    
                                    <button class="button-danger delete-movie-tracker-btn" 
                                            data-tracker-id="<?php echo $mt['id']; ?>"
                                            style="padding: 4px 8px; font-size: 0.75rem; background: var(--color-error); border-color: var(--color-error); color: white; border-radius: 4px;">❌ Delete</button>
                                </div>
                                
                                <span class="badge <?php echo $is_active ? 'badge-success' : 'badge-secondary'; ?>" style="position: absolute; top: 20px; right: 20px; font-size: 0.65rem; padding: 2px 8px; border-radius: 12px; <?php echo !$is_active ? 'background: var(--color-gray-500); color: white; box-shadow: none;' : ''; ?>">
                                    <?php echo strtoupper($mt['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECTION 1.6: SHOWTIME RELEASE ALERTS CENTER -->
        <div class="glass-card" style="margin-bottom: 40px; padding: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 12px; margin-bottom: 20px;">
                <h3 style="margin: 0; font-size: 1.25rem; display: flex; align-items: center; gap: 8px; color: var(--text-primary);">
                    <span>🔔</span> Showtime Release Alerts Log
                </h3>
                <?php if (!empty($release_alerts)): ?>
                    <button id="clear-release-alerts-btn" class="button-danger" style="font-size: 0.8rem; padding: 6px 12px; background: var(--color-error); border-color: var(--color-error); color: white; border-radius: 4px; cursor: pointer;">🧹 Clear Alerts Log</button>
                <?php endif; ?>
            </div>
            
            <div class="alerts-log-container" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                <?php if (empty($release_alerts)): ?>
                    <div style="text-align: center; color: var(--text-secondary); padding: 30px 10px;">
                        <span style="font-size: 2rem; display: block; margin-bottom: 10px;">📭</span>
                        No recent showtime release alerts. Background scans will record new releases here.
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($release_alerts as $alert): 
                            $formattedTime = date('g:i A (M d, Y)', strtotime($alert['show_start_time']));
                            $alertAge = time() - strtotime($alert['notified_at']);
                            if ($alertAge < 60) {
                                $ageStr = 'just now';
                            } elseif ($alertAge < 3600) {
                                $ageStr = round($alertAge / 60) . ' mins ago';
                            } elseif ($alertAge < 86400) {
                                $ageStr = round($alertAge / 3600) . ' hours ago';
                            } else {
                                $ageStr = round($alertAge / 86400) . ' days ago';
                            }
                        ?>
                            <div style="background: var(--bg-tertiary); border: 1px solid var(--border-light); border-left: 4px solid var(--color-success); border-radius: 6px; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <span style="font-size: 1.1rem; margin-right: 8px;">🎬</span>
                                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($alert['movie_name']); ?></strong>
                                    <span style="color: var(--text-secondary);">playing in</span>
                                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($alert['theatre_name']); ?></strong>
                                    <span style="color: var(--text-secondary);">at</span>
                                    <strong style="color: var(--theme-primary);"><?php echo $formattedTime; ?></strong>
                                    <span class="badge" style="background: var(--bg-primary); border: 1px solid var(--border-light); color: var(--text-secondary); font-size: 0.65rem; padding: 1px 5px; margin-left: 5px;"><?php echo htmlspecialchars($alert['experience_type'] ?: 'Standard'); ?></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $ageStr; ?></span>
                                    <span title="Notification sent" style="color: var(--color-success); font-size: 0.95rem;">✉️</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECTION 2: ADD A NEW MONITOR TRACKER -->
        <h2 style="border-bottom: 2px solid var(--border-light); padding-bottom: 8px; margin-bottom: 20px;">➕ Register New Showtime Monitor</h2>
        <div class="form-section glass-card" style="margin-bottom: 40px; padding: 25px;">
            <form method="get" action="">
                <input type="hidden" name="fetch_showtimes" value="1">
                <div class="form-controls">
                    <div class="form-group">
                        <label for="locationId">🏢 Theatre Location:</label>
                        <select name="locationId" id="locationId">
                            <?php foreach ($locations as $name => $id): ?>
                                <option value="<?php echo $id; ?>" <?php echo ($location_id == $id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date">📅 Choose Date:</label>
                        <input type="date" id="date" name="date" value="<?php echo $date; ?>" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group" style="align-self: flex-end;">
                        <button type="submit" class="button-primary">🔍 Find Showtimes</button>
                    </div>
                </div>
            </form>

            <?php if ($fetch_showtimes): ?>
                <div style="margin-top: 25px; border-top: 1px solid var(--border-light); padding-top: 20px;">
                    <?php if (!empty($api_error)): ?>
                        <div class="notice notice-error">Failed loading schedules: <?php echo htmlspecialchars($api_error); ?></div>
                    <?php elseif (empty($showtimes_data)): ?>
                        <div class="notice notice-info">No showtimes found on this date.</div>
                    <?php else: ?>
                        <?php foreach ($showtimes_data as $movie): ?>
                            <div style="margin-bottom: 20px; background: var(--bg-tertiary); padding: 15px; border-radius: 8px;">
                                <h4 style="margin:0 0 10px 0; color:var(--text-primary); font-size:1.1rem;"><?php echo htmlspecialchars($movie['name']); ?></h4>
                                <div class="session-grid-tracker">
                                    <?php 
                                    if (!empty($movie['experiences'])):
                                        foreach ($movie['experiences'] as $exp):
                                            if (!empty($exp['sessions'])):
                                                foreach ($exp['sessions'] as $session):
                                                    $sessionId = $session['vistaSessionId'];
                                                    $start_iso = $session['showStartDateTime'];
                                                    $start_formatted = date('g:i A', strtotime($start_iso));
                                                    $auditorium = $session['auditorium'] ?? $session['auditoriumName'] ?? 'Auditorium';
                                                    
                                                    // Check if already active
                                                    $is_currently_tracked = false;
                                                    foreach ($active_monitors as $am) {
                                                        if ($am['showtime_id'] == $sessionId) {
                                                            $is_currently_tracked = true;
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                    <div class="session-card-tracker">
                                                        <span style="font-weight:700; font-size:1rem;"><?php echo $start_formatted; ?></span>
                                                        <span style="font-size:0.8rem; color:var(--text-secondary); margin: 2px 0 8px 0;"><?php echo htmlspecialchars($auditorium); ?></span>
                                                        
                                                        <?php if ($is_currently_tracked): ?>
                                                            <button class="button-secondary" disabled style="width: 100%; font-size: 0.8rem; padding: 4px 8px;">✓ Active</button>
                                                        <?php else: ?>
                                                            <button class="button-primary track-showtime-btn"
                                                                    data-theatre-id="<?php echo $location_id; ?>"
                                                                    data-theatre-name="<?php echo htmlspecialchars($locations[array_search($location_id, $locations)] ?? 'Theatre'); ?>"
                                                                    data-showtime-id="<?php echo $sessionId; ?>"
                                                                    data-movie-name="<?php echo htmlspecialchars($movie['name']); ?>"
                                                                    data-show-start-time="<?php echo $start_iso; ?>"
                                                                    style="width: 100%; font-size: 0.8rem; padding: 4px 8px;">📡 Track</button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php 
                                                endforeach;
                                            endif;
                                        endforeach;
                                    endif;
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- SECTION 3: COMPLETED LOGS HISTORY -->
        <h2 style="border-bottom: 2px solid var(--border-light); padding-bottom: 8px; margin-bottom: 20px;">✅ Completed Monitors (Last 20)</h2>
        <?php if (empty($completed_monitors)): ?>
            <div class="notice notice-info">No completed monitoring sessions recorded.</div>
        <?php else: ?>
            <div class="tracker-grid">
                <?php foreach ($completed_monitors as $monitor): 
                    $show_time = strtotime($monitor['show_start_time']);
                    $full_showtime_date = date('l, F j, Y @ g:i A', $show_time);
                    
                    $occ = ($monitor['last_occupancy'] !== null) ? (float)$monitor['last_occupancy'] : null;
                    $occ_class = 'occupancy-low';
                    if ($occ !== null) {
                        if ($occ > 75) {
                            $occ_class = 'occupancy-high';
                        } elseif ($occ > 35) {
                            $occ_class = 'occupancy-mid';
                        }
                    }
                ?>
                    <div class="tracker-card completed glass-card">
                        <input type="checkbox" class="tracker-bulk-checkbox" value="<?php echo $monitor['id']; ?>" style="position: absolute; top: 15px; right: 15px; width: 18px; height: 18px; cursor: pointer; z-index: 10; accent-color: var(--theme-primary);">
                        
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                            <span class="countdown-badge countdown-past">⏱ Past showtime</span>
                            <span class="tracker-card-badge completed-badge" style="position: static; margin: 0;">COMPLETED</span>
                        </div>

                        <div class="tracker-card-title" style="padding-right: 35px; font-size: 1.1rem; font-weight: 700; margin-bottom: 6px;"><?php echo htmlspecialchars($monitor['movie_name']); ?></div>
                        
                        <div class="tracker-card-meta" style="font-size: 0.88rem; margin-bottom: 4px;">🏢 <strong><?php echo htmlspecialchars($monitor['theatre_name']); ?></strong></div>
                        <div class="tracker-card-meta" style="font-size: 0.88rem; margin-bottom: 10px; color: var(--theme-primary); font-weight: 600;">🗓 <?php echo $full_showtime_date; ?></div>

                        <!-- Occupancy Progress Bar -->
                        <div style="margin-top: 10px; margin-bottom: 12px; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; border: 1px solid var(--border-light);">
                            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; margin-bottom: 2px;">
                                <span style="color: var(--text-secondary);">Final Occupancy</span>
                                <strong style="color: var(--text-primary);"><?php echo ($occ !== null) ? number_format($occ, 1) . '%' : 'N/A'; ?></strong>
                            </div>
                            <div class="occupancy-bar-track">
                                <div class="occupancy-bar-fill <?php echo $occ_class; ?>" style="width: <?php echo ($occ !== null) ? min(100, max(5, $occ)) : 0; ?>%;"></div>
                            </div>
                        </div>

                        <div class="tracker-card-meta" style="font-size: 0.82rem; color: var(--text-secondary);">
                            📸 Snapshots: <strong><?php echo $monitor['snapshot_count']; ?></strong> logged
                        </div>
                        
                        <div style="margin-top: 15px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <button class="button-primary view-analysis-btn" 
                                    data-tracker-id="<?php echo $monitor['id']; ?>"
                                    data-movie-name="<?php echo htmlspecialchars($monitor['movie_name']); ?>"
                                    style="padding: 6px 12px; font-size: 0.85rem;">📊 Analyze Logs</button>
                            
                            <button class="button-danger delete-tracker-btn" 
                                    data-tracker-id="<?php echo $monitor['id']; ?>"
                                    style="padding: 6px 12px; font-size: 0.85rem; background: var(--color-error); border-color: var(--color-error); color: white;">❌ Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </main>

        <!-- Floating Bulk Actions Bar -->
        <div id="bulk-actions-bar" style="position: fixed; bottom: -80px; left: 50%; transform: translateX(-50%); background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid var(--border-light); border-radius: 50px; padding: 12px 30px; display: flex; align-items: center; gap: 20px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5); z-index: 2000; transition: bottom 0.35s cubic-bezier(0.4, 0, 0.2, 1); min-width: 340px; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <label style="display: flex; align-items: center; gap: 8px; color: var(--text-primary); font-size: 0.9rem; font-weight: 600; cursor: pointer; user-select: none;">
                    <input type="checkbox" id="bulk-select-all" style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--theme-primary);"> Select All
                </label>
                <span id="bulk-selected-count" style="font-size: 0.85rem; color: var(--theme-primary); font-weight: 700; background: rgba(59, 130, 246, 0.1); padding: 2px 10px; border-radius: 20px; border: 1px solid rgba(59, 130, 246, 0.2);">0 selected</span>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button id="bulk-snapshot-btn" class="button-primary" style="font-size: 0.85rem; padding: 8px 16px; border-radius: 30px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <span>📸</span> Snapshot Selected
                </button>
                <button id="bulk-delete-btn" class="button-danger" style="font-size: 0.85rem; padding: 8px 16px; background: var(--color-error); border-color: var(--color-error); color: white; border-radius: 30px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                    <span>❌</span> Delete Selected
                </button>
            </div>
        </div>
    </div>

    <!-- ANALYSIS & TIMELINE SCRUBBER MODAL WINDOW -->
    <div id="analysis-modal" class="modal-overlay">
        <div class="modal-content glass-card" style="max-width: 1200px; width: 95%;">
            <button id="analysis-modal-close-btn" class="modal-close" aria-label="Close modal">&times;</button>
            <h2 id="analysis-modal-title" style="margin-top:0; border-bottom:1px solid var(--border-light); padding-bottom:10px;">📊 History Analysis</h2>
            
            <div class="analysis-split">
                <!-- Left Side: Trends and Statistics Log List -->
                <div class="analysis-chart-side">
                    <div>
                        <h4 style="margin:0 0 10px 0;">Occupancy Progression Trend</h4>
                        <div style="height: 250px; position: relative; margin-bottom: 20px; background: rgba(0,0,0,0.02); border-radius: 8px; border:1px solid var(--border-light); padding:10px;">
                            <canvas id="history-chart"></canvas>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="margin:0 0 5px 0;">Logs List</h4>
                        <div class="history-table-container">
                            <table style="width:100%; border-collapse: collapse; text-align:left; font-size:0.85rem;">
                                <thead>
                                    <tr style="background:var(--bg-tertiary); border-bottom:1px solid var(--border-light);">
                                        <th style="padding:8px;">Snapshot Time</th>
                                        <th style="padding:8px;">Occupancy %</th>
                                        <th style="padding:8px;">Occupied</th>
                                        <th style="padding:8px;">Available</th>
                                        <th style="padding:8px;">Broken</th>
                                    </tr>
                                </thead>
                                <tbody id="history-table-body">
                                    <!-- Populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Right Side: Interactive Scrubber and Map View -->
                <div class="analysis-seatmap-side">
                    <h4 style="margin:0 0 10px 0;">🗺 Seatmap Snapshot Viewer</h4>
                    
                    <!-- Slider scrubber controls -->
                    <div class="timeline-scrubber">
                        <button id="scrubber-prev-btn" class="button-secondary">◀ Prev</button>
                        <input type="range" id="snapshot-range-slider" min="0" max="0" value="0">
                        <button id="scrubber-next-btn" class="button-secondary">Next ▶</button>
                    </div>
                    
                    <div style="text-align: center; margin-bottom: 10px;">
                        <span style="font-size:0.85rem; color:var(--text-secondary);">Snapshot Time:</span>
                        <strong id="scrubber-current-time" style="font-size:0.9rem; color:var(--text-primary);">N/A</strong>
                    </div>

                    <div id="live-map-render-area" style="flex:1; min-height: 280px; overflow-y: auto; background: var(--bg-tertiary); border-radius:8px; padding:15px; border:1px solid var(--border-light);">
                        <!-- Seat layout rendered dynamically -->
                    </div>
                    
                    <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                        <button id="take-instant-snap-btn" class="button-primary" style="font-size:0.85rem; padding: 8px 15px;">🔄 Take Instant Snapshot</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hover tooltip element -->
    <div id="tooltip" class="tooltip" style="display: none; position: absolute; background: rgba(0,0,0,0.85); color: white; padding: 6px 12px; border-radius: 4px; font-size: 0.8rem; pointer-events: none; z-index: 10000; box-shadow: var(--shadow-md);"></div>

    <!-- JavaScript Hooks -->
    <script src="assets/js/shared.js" defer></script>
    <script src="assets/js/design-options-modal.js" defer></script>
    <script src="assets/js/tracker.js" defer></script>
    <script>
        // Inline script to bind Ajax addition trigger
        $(function() {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            
            $('.track-showtime-btn').click(function() {
                const btn = $(this);
                const postData = {
                    action: 'add_tracker',
                    theatre_id: btn.data('theatreId'),
                    theatre_name: btn.data('theatreName'),
                    showtime_id: btn.data('showtimeId'),
                    movie_name: btn.data('movieName'),
                    show_start_time: btn.data('showStartTime'),
                    csrf_token: csrfToken
                };
                
                btn.prop('disabled', true).text('⏳ Registering...');
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        alert(response.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).text('📡 Track');
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).text('📡 Track');
                });
            });

            // Add automatic movie release tracker
            $('#movie-tracker-form').submit(function(e) {
                e.preventDefault();
                const form = $(this);
                const submitBtn = form.find('button[type="submit"]');
                
                const postData = {
                    action: 'add_movie_tracker',
                    movie_name: $('#mt_movie_name').val(),
                    theatre_id: $('#mt_location_id').val(),
                    experience_filter: $('#mt_experience').val(),
                    start_date: $('#mt_start_date').val(),
                    end_date: $('#mt_end_date').val(),
                    csrf_token: csrfToken
                };
                
                submitBtn.prop('disabled', true).text('⏳ Registering...');
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        let msg = response.message;
                        if (response.matched_count > 0) {
                            msg += ` Scan matched and registered ${response.matched_count} showtimes immediately!`;
                        } else {
                            msg += ' No current showtimes matched, but we will scan for new releases in the background.';
                        }
                        alert(msg);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        submitBtn.prop('disabled', false).text('📡 Add Release Tracker');
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    submitBtn.prop('disabled', false).text('📡 Add Release Tracker');
                });
            });

            // Toggle movie tracker status (Pause/Resume)
            $('.toggle-movie-tracker-btn').click(function() {
                const btn = $(this);
                const trackerId = btn.data('trackerId');
                const currentStatus = btn.data('status');
                const newStatus = (currentStatus === 'active') ? 'paused' : 'active';
                
                btn.prop('disabled', true).text('⏳...');
                
                const postData = {
                    action: 'toggle_movie_tracker',
                    tracker_id: trackerId,
                    status: newStatus,
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).text(currentStatus === 'active' ? '⏸ Pause' : '▶ Resume');
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).text(currentStatus === 'active' ? '⏸ Pause' : '▶ Resume');
                });
            });

            // Scan movie tracker manually
            $('.scan-movie-tracker-btn').click(function() {
                const btn = $(this);
                const trackerId = btn.data('trackerId');
                const originalText = btn.text();
                
                btn.prop('disabled', true).text('⏳ Scanning...');
                
                const postData = {
                    action: 'scan_movie_tracker',
                    tracker_id: trackerId,
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        alert(response.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).text(originalText);
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).text(originalText);
                });
            });

            // Delete movie tracker rule
            $('.delete-movie-tracker-btn').click(function() {
                const btn = $(this);
                const trackerId = btn.data('trackerId');
                
                if (!confirm("Are you sure you want to delete this automatic movie tracker rule? This will stop auto-registering future showtimes, but existing registered showtimes will remain tracked unless deleted individually.")) {
                    return;
                }
                
                btn.prop('disabled', true).text('⏳...');
                
                const postData = {
                    action: 'delete_movie_tracker',
                    tracker_id: trackerId,
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).text('❌ Delete');
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).text('❌ Delete');
                });
            });

            // Clear release alerts log
            $('#clear-release-alerts-btn').click(function() {
                const btn = $(this);
                if (!confirm("Are you sure you want to clear the entire showtime release alerts history log?")) {
                    return;
                }
                
                btn.prop('disabled', true).text('⏳...');
                
                const postData = {
                    action: 'clear_release_alerts',
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).text('🧹 Clear Alerts Log');
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).text('🧹 Clear Alerts Log');
                });
            });

            // Handle bulk selections
            const bulkBar = $('#bulk-actions-bar');
            const bulkCheckboxes = $('.tracker-bulk-checkbox');
            const bulkSelectAll = $('#bulk-select-all');
            const selectedCountSpan = $('#bulk-selected-count');
            
            function updateBulkBar() {
                const checkedCheckboxes = $('.tracker-bulk-checkbox:checked');
                const checkedCount = checkedCheckboxes.length;
                
                selectedCountSpan.text(checkedCount + ' selected');
                
                if (checkedCount > 0) {
                    bulkBar.css('bottom', '20px');
                } else {
                    bulkBar.css('bottom', '-80px');
                }
                
                if (checkedCount === bulkCheckboxes.length && bulkCheckboxes.length > 0) {
                    bulkSelectAll.prop('checked', true);
                } else {
                    bulkSelectAll.prop('checked', false);
                }
            }
            
            bulkCheckboxes.change(function() {
                updateBulkBar();
            });
            
            bulkSelectAll.change(function() {
                const isChecked = $(this).is(':checked');
                bulkCheckboxes.prop('checked', isChecked);
                updateBulkBar();
            });
            
            // Bulk Delete Request
            $('#bulk-delete-btn').click(function() {
                const checkedCheckboxes = $('.tracker-bulk-checkbox:checked');
                const count = checkedCheckboxes.length;
                
                if (count === 0) return;
                
                if (!confirm(`Are you sure you want to delete the ${count} selected showtime tracker(s)? This will permanently remove their history snapshots and layout graphs.`)) {
                    return;
                }
                
                const btn = $(this);
                btn.prop('disabled', true).html('⏳ Purging...');
                
                const ids = [];
                checkedCheckboxes.each(function() {
                    ids.push($(this).val());
                });
                
                const postData = {
                    action: 'delete_tracker',
                    tracker_ids: ids.join(','),
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        alert(response.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).html('<span>❌</span> Delete Selected');
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).html('<span>❌</span> Delete Selected');
                });
            });

            // Single Snapshot Button (One-click on active card)
            $('.single-snapshot-btn').click(function() {
                const btn = $(this);
                const trackerId = btn.data('trackerId');
                const originalHtml = btn.html();
                
                btn.prop('disabled', true).html('⏳...');
                
                const postData = {
                    action: 'trigger_snapshot',
                    tracker_id: trackerId,
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        alert(response.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).html(originalHtml);
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).html(originalHtml);
                });
            });

            // Snapshot All Active (Header button)
            $('#snapshot-all-active-btn').click(function() {
                const btn = $(this);
                const originalHtml = btn.html();
                
                btn.prop('disabled', true).html('⏳ Capturing All...');
                
                const postData = {
                    action: 'trigger_all_snapshots',
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        alert(response.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).html(originalHtml);
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).html(originalHtml);
                });
            });

            // Bulk Snapshot Selected (Toolbar button)
            $('#bulk-snapshot-btn').click(function() {
                const checkedCheckboxes = $('.tracker-bulk-checkbox:checked');
                const count = checkedCheckboxes.length;
                
                if (count === 0) return;
                
                const btn = $(this);
                const originalHtml = btn.html();
                btn.prop('disabled', true).html('⏳ Capturing...');
                
                const ids = [];
                checkedCheckboxes.each(function() {
                    ids.push($(this).val());
                });
                
                const postData = {
                    action: 'trigger_snapshot',
                    tracker_ids: ids.join(','),
                    csrf_token: csrfToken
                };
                
                $.post('api', postData, function(response) {
                    if (response.success) {
                        alert(response.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).html(originalHtml);
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).html(originalHtml);
                });
            });

            // View Switcher (Grid vs Data Table)
            function applyViewPreference(view) {
                $('.view-switcher-btn').removeClass('active');
                $(`.view-switcher-btn[data-view="${view}"]`).addClass('active');
                
                if (view === 'table') {
                    $('#monitors-grid-container').hide();
                    $('#monitors-table-container').show();
                } else {
                    $('#monitors-table-container').hide();
                    $('#monitors-grid-container').show();
                }
            }
            
            // Load saved view preference
            const savedView = localStorage.getItem('cinepulse_tracker_view') || 'grid';
            applyViewPreference(savedView);
            
            $('.view-switcher-btn').click(function() {
                const targetView = $(this).data('view');
                localStorage.setItem('cinepulse_tracker_view', targetView);
                applyViewPreference(targetView);
            });
            
            // Table header select-all handler
            $('#table-select-all').change(function() {
                const isChecked = $(this).is(':checked');
                $('.tracker-bulk-checkbox').prop('checked', isChecked).trigger('change');
            });

            // Live Search Filter Handler (filters both cards and table rows)
            $('#monitor-search-input').on('keyup input', function() {
                const query = $(this).val().toLowerCase().trim();
                
                // Filter Grid Cards
                $('#monitors-grid-container .tracker-card').each(function() {
                    const text = $(this).text().toLowerCase();
                    if (!query || text.indexOf(query) !== -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
                
                // Filter Data Table Rows
                $('.monitors-datatable tbody tr').each(function() {
                    const text = $(this).text().toLowerCase();
                    if (!query || text.indexOf(query) !== -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Column Sorting Handler for Data Table
            let currentSortKey = null;
            let currentSortAsc = true;
            
            $('.sortable-col').click(function() {
                const th = $(this);
                const sortKey = th.data('colKey');
                const sortType = th.data('sortType');
                
                if (currentSortKey === sortKey) {
                    currentSortAsc = !currentSortAsc;
                } else {
                    currentSortKey = sortKey;
                    currentSortAsc = true;
                }
                
                // Update header icons & classes
                $('.sortable-col').removeClass('asc desc').find('.sort-icon').text('↕');
                th.addClass(currentSortAsc ? 'asc' : 'desc').find('.sort-icon').text(currentSortAsc ? '▲' : '▼');
                
                const tbody = $('.monitors-datatable tbody');
                const rows = tbody.find('tr').get();
                
                rows.sort(function(a, b) {
                    let valA = $(a).data(sortKey);
                    let valB = $(b).data(sortKey);
                    
                    if (sortType === 'number') {
                        valA = parseFloat(valA) || 0;
                        valB = parseFloat(valB) || 0;
                        return currentSortAsc ? (valA - valB) : (valB - valA);
                    } else {
                        valA = (valA || '').toString().toLowerCase();
                        valB = (valB || '').toString().toLowerCase();
                        return currentSortAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                    }
                });
                
                $.each(rows, function(index, row) {
                    tbody.append(row);
                });
            });
        });
    </script>
</body>
</html>
