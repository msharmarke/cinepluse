<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Cinepulse — Streamlined Showtime Browser & Live Seats Portal
 */

// Initialize Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

Security::startSession();

// Setup DB connection status
$db_configured = true;
$db_error = '';
$tables_missing = false;
try {
    $db = Cinepulse\Database::getInstance()->getConnection();
    $stmt1 = $db->query("SHOW TABLES LIKE 'tracked_showtimes'");
    if ($stmt1->rowCount() === 0) {
        $tables_missing = true;
    }
} catch (Exception $e) {
    $db_configured = false;
    $db_error = $e->getMessage();
}

// Fetch list of theaters
$locations = ShowtimeService::getTrackerTheatres(false);
$activeLocations = ShowtimeService::getTrackerTheatres(true);

// Default query parameters: pick first active location if available
$defaultLocId = !empty($activeLocations) ? reset($activeLocations) : (!empty($locations) ? reset($locations) : 7402);
$location_id = Security::sanitizeInput($_GET['locationId'] ?? $_GET['location_id'] ?? $defaultLocId, 'int');
$date = Security::sanitizeInput($_GET['date'] ?? date('Y-m-d'), 'date');
$fetch_showtimes = true;

// Find selected theatre name
$selectedTheatreName = 'Cineplex Cinema';
foreach ($locations as $name => $id) {
    if ((int)$id === (int)$location_id) {
        $selectedTheatreName = $name;
        break;
    }
}

// Cineplex Date Format
$cineplex_date = date('m+d+Y', strtotime($date));

// Fetch schedule from API
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

// Query active tracked list
$active_trackers = [];
if ($db_configured && !$tables_missing) {
    try {
        $stmt = $db->prepare("SELECT showtime_id FROM tracked_showtimes WHERE theatre_id = ? AND status = 'active'");
        $stmt->execute([$location_id]);
        $active_trackers = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        error_log("Failed to load trackers: " . $e->getMessage());
    }
}

// Flatten sessions
$all_sessions = [];
$movie_catalog = [];

foreach ($showtimes_data as $movie) {
    $movieTitle = $movie['name'] ?? $movie['title'] ?? 'Unknown';
    $runtime = (int)($movie['runtimeInMinutes'] ?? $movie['duration'] ?? 120);

    if (!isset($movie_catalog[$movieTitle])) {
        $movie_catalog[$movieTitle] = [
            'title' => $movieTitle,
            'runtime' => $runtime,
            'formats' => []
        ];
    }

    if (!empty($movie['experiences'])) {
        foreach ($movie['experiences'] as $exp) {
            $expTypes = $exp['experienceTypes'] ?? ['Standard'];
            $expName = implode(', ', $expTypes);
            
            if (!empty($exp['sessions'])) {
                foreach ($exp['sessions'] as $session) {
                    $audName = $session['auditorium'] ?? $session['auditoriumName'] ?? 'Auditorium';
                    $start_time = strtotime($session['showStartDateTime']);
                    $start_formatted = date('g:i A', $start_time);
                    
                    $all_sessions[] = [
                        'movie' => $movieTitle,
                        'start' => $start_time,
                        'start_formatted' => $start_formatted,
                        'experience' => $expName,
                        'runtime' => $runtime,
                        'auditorium' => $audName,
                        'session_id' => $session['vistaSessionId']
                    ];

                    $movie_catalog[$movieTitle]['formats'][$expName][] = [
                        'time' => $start_formatted,
                        'auditorium' => $audName,
                        'session_id' => $session['vistaSessionId']
                    ];
                }
            }
        }
    }
}

// Sort all sessions chronologically
usort($all_sessions, function($a, $b) {
    return $a['start'] <=> $b['start'];
});

// Group showtimes by auditorium for timeline
$auditorium_matrix = [];
foreach ($all_sessions as $session) {
    $auditorium_matrix[$session['auditorium']][] = $session;
}
uksort($auditorium_matrix, 'strnatcasecmp');

if (!function_exists('getMovieColor')) {
    function getMovieColor($movieTitle) {
        $hash = md5($movieTitle);
        $hue = hexdec(substr($hash, 0, 3)) % 360;
        return "hsl({$hue}, 65%, 38%)";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>🎬 Cinepulse — Streamlined Showtime Explorer</title>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Outfit', sans-serif; }

        /* Sleek KPI Pill Strip */
        .kpi-strip {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .kpi-pill {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--glass-border);
            padding: 0.5rem 1rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary);
            backdrop-filter: blur(8px);
        }
        .kpi-val {
            color: var(--theme-primary, #e50914);
            font-size: 1rem;
            font-weight: 800;
        }

        /* View Mode Switcher Pills */
        .view-switcher-btn {
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid var(--glass-border);
            background: rgba(255,255,255,0.05);
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }
        .view-switcher-btn.active {
            background: var(--theme-primary, #e50914);
            color: #ffffff;
            border-color: transparent;
        }

        /* Movie Card Styling */
        .movie-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.25rem;
        }
        .movie-card-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 1rem;
            transition: all 0.2s ease;
        }
        .movie-card-item:hover {
            transform: translateY(-2px);
            border-color: var(--theme-primary, #3b82f6);
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
                <a href="/schedule" class="active">📅 Schedule</a>
                <a href="/movies">🎬 Movies</a>
                <a href="/planner">🍿 Planner</a>
                <a href="/admin/tracker">📈 Tracker</a>
                <a href="/admin/dashboard">📊 Dashboard</a>
                <a href="/admin/scan-logs">🔍 Scan Logs</a>
            </nav>
            <div style="padding: 1rem 1.5rem; margin-top: auto;">
                <button id="openThemeModal" class="btn-dash btn-dash-secondary" style="width: 100%; justify-content: center; background: rgba(255,255,255,0.08); border: 1px solid var(--glass-border); color: #fff; padding: 0.5rem; border-radius: 8px; font-weight: 700; cursor: pointer;">🎨 Theme Options</button>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">

            <?php if (!$db_configured): ?>
                <div style="background: rgba(231, 76, 60, 0.15); border: 1px solid #e74c3c; padding: 1.25rem; border-radius: 12px; color: #ff6b6b; margin-bottom: 1.5rem;">
                    <h4 style="margin:0 0 0.4rem 0;">⚠ Database Connection Error</h4>
                    <p style="margin:0; font-size: 0.88rem;"><?php echo htmlspecialchars($db_error ?? ''); ?></p>
                </div>
            <?php endif; ?>

            <!-- Page Title & Streamlined Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <h1 style="margin: 0; font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;">📅 Showtime Explorer & Live Seating</h1>
                    <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.9rem;">Browse pre-cached theatrical showtimes and explore interactive 3D seat availability.</p>
                </div>

                <!-- KPI Summary Pills -->
                <div class="kpi-strip" style="margin-bottom: 0;">
                    <div class="kpi-pill">
                        <span>📍 Venue:</span>
                        <span class="kpi-val" style="color: #60a5fa;"><?php echo htmlspecialchars($selectedTheatreName); ?></span>
                    </div>
                    <div class="kpi-pill">
                        <span>🎬 Showtimes Today:</span>
                        <span class="kpi-val"><?php echo count($all_sessions); ?></span>
                    </div>
                    <div class="kpi-pill">
                        <span>📡 Active Monitored:</span>
                        <span class="kpi-val" style="color: #2ecc71;"><?php echo count($active_trackers); ?></span>
                    </div>
                </div>
            </div>

            <!-- STREAMLINED CONTROL DECK (Single Glassmorphic Card) -->
            <div class="glass-card" style="padding: 1.25rem; border-radius: 16px; margin-bottom: 1.5rem; background: rgba(17, 24, 39, 0.6); backdrop-filter: blur(12px); border: 1px solid var(--glass-border);">
                
                <!-- 1. Theatrical Week 7-Day Selector Strip -->
                <?php
                $todaySec = strtotime('today');
                $selectedDate = $date ?? date('Y-m-d');
                ?>
                <div style="display: flex; gap: 0.5rem; overflow-x: auto; padding-bottom: 0.75rem; margin-bottom: 1rem; border-bottom: 1px solid var(--glass-border);">
                    <?php for ($i = 0; $i < 7; $i++): 
                        $daySec = strtotime("+{$i} days", $todaySec);
                        $dayStr = date('Y-m-d', $daySec);
                        $dayName = date('D', $daySec);
                        $dayNum = date('j', $daySec);
                        $monthName = date('M', $daySec);
                        $isActive = ($dayStr === $selectedDate);
                    ?>
                        <a href="?locationId=<?php echo $location_id; ?>&date=<?php echo $dayStr; ?>&fetch_showtimes=1" style="padding: 0.45rem 0.85rem; border-radius: 10px; text-decoration: none; display: flex; flex-direction: column; align-items: center; min-width: 70px; border: 1px solid <?php echo $isActive ? 'var(--theme-primary, #e50914)' : 'rgba(255,255,255,0.08)'; ?>; background: <?php echo $isActive ? 'var(--theme-primary, #e50914)' : 'rgba(255,255,255,0.03)'; ?>; color: #ffffff; transition: all 0.2s ease;">
                            <span style="font-size: 0.68rem; text-transform: uppercase; opacity: 0.8; font-weight: 700;"><?php echo $dayName; ?></span>
                            <span style="font-size: 1.05rem; font-weight: 800; line-height: 1.1; margin: 1px 0;"><?php echo $dayNum; ?></span>
                            <span style="font-size: 0.65rem; opacity: 0.6; font-weight: 600;"><?php echo $monthName; ?></span>
                        </a>
                    <?php endfor; ?>
                </div>

                <!-- 2. Integrated Filters Bar & View Switcher -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.85rem;">
                    <form method="GET" action="" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; flex: 1;">
                        <input type="hidden" name="fetch_showtimes" value="1">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">

                        <!-- Location Select -->
                        <select name="locationId" onchange="this.form.submit()" style="background: #0f172a; color: #ffffff; border: 1px solid #334155; padding: 0.5rem 0.85rem; border-radius: 8px; font-weight: 700; font-size: 0.88rem; min-width: 210px;">
                            <?php foreach ($locations as $name => $id): ?>
                                <option value="<?php echo $id; ?>" <?php echo ((int)$location_id === (int)$id) ? 'selected' : ''; ?>>
                                    <?php echo (in_array($id, $activeLocations) ? '🟢 ' : '⚪ ') . htmlspecialchars($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Instant Title Search -->
                        <input type="text" id="movie-search" placeholder="🔍 Search title..." style="background: #0f172a; color: #ffffff; border: 1px solid #334155; padding: 0.5rem 0.85rem; border-radius: 8px; font-size: 0.88rem; min-width: 180px;">

                        <!-- Format Filter -->
                        <select id="experience-filter" style="background: #0f172a; color: #ffffff; border: 1px solid #334155; padding: 0.5rem 0.85rem; border-radius: 8px; font-size: 0.88rem;">
                            <option value="">✨ All Formats</option>
                            <option value="IMAX">IMAX</option>
                            <option value="70mm">70mm</option>
                            <option value="3D">3D</option>
                            <option value="VIP">VIP</option>
                            <option value="DBOX">DBOX</option>
                            <option value="UltraAVX">UltraAVX</option>
                        </select>

                        <!-- Time Filter -->
                        <select id="time-range-filter" style="background: #0f172a; color: #ffffff; border: 1px solid #334155; padding: 0.5rem 0.85rem; border-radius: 8px; font-size: 0.88rem;">
                            <option value="">🕐 All Day</option>
                            <option value="morning">Morning (Before 12 PM)</option>
                            <option value="afternoon">Afternoon (12–5 PM)</option>
                            <option value="evening">Evening (5–10 PM)</option>
                            <option value="late">Late Night (After 10 PM)</option>
                        </select>
                    </form>

                    <!-- View Mode Switcher & Export -->
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <button class="view-switcher-btn active" data-view="#viewTimeline">📊 Room Timeline</button>
                        <button class="view-switcher-btn" data-view="#viewMovieCards">🎬 Movie Cards</button>
                        <button class="view-switcher-btn" data-view="#viewTable">📋 Table View</button>
                        <a href="export-pdf?locationId=<?php echo $location_id; ?>&start_date=<?php echo $date; ?>" target="_blank" style="background: rgba(255,255,255,0.08); color: #ffffff; border: 1px solid var(--glass-border); padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">📄 Export PDF</a>
                    </div>
                </div>
            </div>

            <?php if (!empty($api_error)): ?>
                <div style="background: rgba(231, 76, 60, 0.15); border: 1px solid #e74c3c; padding: 1.5rem; border-radius: 12px; color: #ff6b6b; text-align: center; margin-bottom: 2rem;">
                    <strong>API Request Error:</strong> <?php echo htmlspecialchars($api_error); ?>
                </div>
            <?php elseif (empty($showtimes_data)): ?>
                <div style="background: rgba(255,255,255,0.02); border: 1px dashed var(--glass-border); padding: 3rem 1.5rem; text-align: center; border-radius: 14px;">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📅</div>
                    <h4 style="margin: 0 0 0.5rem 0; font-weight: 700;">No showtimes available for <?php echo htmlspecialchars($selectedTheatreName); ?> on <?php echo $date; ?></h4>
                    <p style="color: var(--text-muted); font-size: 0.9rem; max-width: 500px; margin: 0 auto;">Select another date or active venue from the controls deck above.</p>
                </div>
            <?php else: ?>

                <!-- VIEW 1: CONSOLIDATED ROOM TIMELINE PANEL (Default) -->
                <div id="viewTimeline" class="content-view-panel glass-card" style="padding: 1.25rem; border-radius: 16px; margin-bottom: 2rem; overflow-x: auto;">
                    <h3 style="margin-top: 0; margin-bottom: 0.25rem; font-size: 1.15rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem;">
                        <span>📊</span> Consolidated Screen Timeline
                    </h3>
                    <p style="color: var(--text-muted); margin: 0 0 1.25rem 0; font-size: 0.85rem;">Room schedules (10:00 AM - 4:00 AM next day). Hover for details, click any session capsule to view live seat map.</p>

                    <?php
                    $preroll_minutes = 20;
                    $timeline_start_date = date('Y-m-d 10:00:00', strtotime($date));
                    $timeline_start_time = strtotime($timeline_start_date);
                    $total_timeline_hours = 18;
                    $total_timeline_minutes = $total_timeline_hours * 60;
                    ?>

                    <div class="consolidated-timeline" style="min-width: 900px; display: table; width: 100%;">
                        <div class="timeline-header-row" style="display: table-row;">
                            <div class="timeline-row-label" style="display: table-cell; width: 140px; padding-right: 15px;"></div>
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

                        <?php foreach ($auditorium_matrix as $audName => $sessions): ?>
                            <div class="timeline-screen-row" style="display: table-row; height: 44px;">
                                <div class="timeline-row-label" style="display: table-cell; vertical-align: middle; width: 140px; padding-right: 15px; font-weight: 700; color: var(--text-primary); font-size: 0.88rem; white-space: nowrap; border-bottom: 1px solid var(--border-primary);">
                                    🏛️ <?php echo htmlspecialchars($audName); ?>
                                </div>
                                <div class="timeline-bar-track" style="display: table-cell; vertical-align: middle; position: relative; border-bottom: 1px solid var(--border-primary); background: rgba(0,0,0,0.15);">
                                    <?php foreach ($sessions as $session): ?>
                                        <?php
                                        $duration_with_preroll = $session['runtime'] + $preroll_minutes;
                                        $sess_start_time = $session['start'] - ($preroll_minutes * 60);
                                        $sess_end_time = $sess_start_time + ($duration_with_preroll * 60);
                                        
                                        $offset_mins = ($sess_start_time - $timeline_start_time) / 60;
                                        $left_percent = ($offset_mins / $total_timeline_minutes) * 100;
                                        $width_percent = ($duration_with_preroll / $total_timeline_minutes) * 100;
                                        
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
                                             style="left: <?php echo $left_percent; ?>%; width: <?php echo $width_percent; ?>%; position: absolute; height: 30px; top: 7px; display: flex; border-radius: 6px; overflow: hidden; border: 1px solid rgba(255,255,255,0.15); cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.3); transition: transform 0.2s;"
                                             data-theatre-id="<?php echo $location_id; ?>"
                                             data-showtime-id="<?php echo $session['session_id']; ?>"
                                             data-movie-name="<?php echo htmlspecialchars($session['movie']); ?>"
                                             data-movie-time="<?php echo $session['start_formatted']; ?>"
                                             data-auditorium="<?php echo htmlspecialchars($audName); ?>"
                                             title="<?php echo htmlspecialchars($session['movie']); ?> (<?php echo $session['start_formatted']; ?> - <?php echo date('g:i A', $sess_end_time); ?>)">
                                            
                                            <span class="timeline-bar-preroll" style="width: <?php echo $preroll_percent; ?>%; background: #64748b; height: 100%; opacity: 0.75;"></span>
                                            
                                            <span class="timeline-bar-movie" style="width: <?php echo $movie_percent; ?>%; background-color: <?php echo $movie_color; ?>; height: 100%; display: flex; align-items: center; padding-left: 8px; overflow: hidden;">
                                                <span class="timeline-bar-label" style="color: #fff; font-size: 0.75rem; font-weight: 700; text-shadow: 1px 1px 2px rgba(0,0,0,0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;">
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

                <!-- VIEW 2: MOVIE CARDS MATRIX PANEL -->
                <div id="viewMovieCards" class="content-view-panel" style="display: none; margin-bottom: 2rem;">
                    <div class="movie-card-grid">
                        <?php foreach ($movie_catalog as $mTitle => $mInfo): ?>
                            <div class="movie-card-item searchable-movie-card" data-title="<?php echo htmlspecialchars(strtolower($mTitle)); ?>">
                                <div>
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.4rem;">
                                        <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #ffffff; font-family: 'Space Grotesk', sans-serif;">🎬 <?php echo htmlspecialchars($mTitle); ?></h3>
                                        <span style="font-size: 0.75rem; background: rgba(255,255,255,0.08); color: var(--text-muted); padding: 2px 7px; border-radius: 6px; font-weight: 700;">⏱️ <?php echo $mInfo['runtime']; ?>m</span>
                                    </div>

                                    <?php foreach ($mInfo['formats'] as $fName => $times): ?>
                                        <div style="margin-top: 0.75rem;">
                                            <span style="font-size: 0.75rem; font-weight: 800; background: var(--theme-primary, #3b82f6); color: #ffffff; padding: 2px 7px; border-radius: 5px; display: inline-block; margin-bottom: 0.4rem; letter-spacing: 0.03em;">✨ <?php echo htmlspecialchars($fName); ?></span>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <?php foreach ($times as $t): ?>
                                                    <button class="view-seats-btn"
                                                            data-theatre-id="<?php echo $location_id; ?>"
                                                            data-showtime-id="<?php echo $t['session_id']; ?>"
                                                            data-movie-name="<?php echo htmlspecialchars($mTitle); ?>"
                                                            data-movie-time="<?php echo $t['time']; ?>"
                                                            data-auditorium="<?php echo htmlspecialchars($t['auditorium']); ?>"
                                                            style="background: rgba(255,255,255,0.06); border: 1px solid var(--glass-border); color: #ffffff; padding: 0.35rem 0.65rem; border-radius: 8px; font-weight: 800; font-size: 0.85rem; font-family: 'Space Grotesk', monospace; cursor: pointer; transition: all 0.2s ease;">
                                                        <?php echo $t['time']; ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- VIEW 3: COMPACT SESSIONS TABLE PANEL -->
                <div id="viewTable" class="content-view-panel glass-card" style="display: none; overflow-x: auto; margin-bottom: 2rem; border-radius: 16px;">
                    <table class="dashboard-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border-light); background: rgba(0,0,0,0.3);">
                                <th style="padding: 12px 16px; color: var(--text-secondary); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Time</th>
                                <th style="padding: 12px 16px; color: var(--text-secondary); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Movie Title</th>
                                <th style="padding: 12px 16px; color: var(--text-secondary); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Format</th>
                                <th style="padding: 12px 16px; color: var(--text-secondary); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Auditorium</th>
                                <th style="padding: 12px 16px; color: var(--text-secondary); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_sessions as $session): 
                                $is_tracked = in_array($session['session_id'], $active_trackers);
                            ?>
                            <tr class="session-row" style="border-bottom: 1px solid var(--border-light);">
                                <td style="padding: 12px 16px; font-weight: 800; font-family: 'Space Grotesk', monospace; font-size: 1rem; color: #ffffff;">
                                    <?php echo $session['start_formatted']; ?>
                                    <?php if ($is_tracked): ?>
                                        <span title="Tracked" style="font-size: 0.75rem; color: #2ecc71;">📡</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 16px; font-weight: 700; color: #ffffff;">
                                    <?php echo htmlspecialchars($session['movie']); ?>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <span style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); font-size: 0.75rem; font-weight: 700; padding: 2px 7px; border-radius: 6px;"><?php echo htmlspecialchars($session['experience'] ?: 'Standard'); ?></span>
                                </td>
                                <td style="padding: 12px 16px; color: var(--text-muted);">
                                    <?php echo htmlspecialchars($session['auditorium']); ?>
                                </td>
                                <td style="padding: 12px 16px; text-align: right;">
                                    <button class="view-seats-btn"
                                            data-theatre-id="<?php echo $location_id; ?>"
                                            data-showtime-id="<?php echo $session['session_id']; ?>"
                                            data-movie-name="<?php echo htmlspecialchars($session['movie']); ?>"
                                            data-movie-time="<?php echo $session['start_formatted']; ?>"
                                            data-auditorium="<?php echo htmlspecialchars($session['auditorium']); ?>"
                                            style="background: var(--theme-primary, #e50914); color: #ffffff; border: none; padding: 5px 12px; font-size: 0.8rem; font-weight: 700; border-radius: 6px; cursor: pointer;">🎟 View Seats</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </main>
    </div>

    <!-- SEAT MAP MODAL WINDOW -->
    <div id="live-map-modal" class="modal-overlay">
        <div class="modal-content glass-card" style="max-width: 900px; width: 100%;">
            <button id="modal-close-btn" class="modal-close" aria-label="Close modal">✕ Close</button>
            <div id="live-map-render-area" class="modal-body-content">
                <!-- Rendered dynamically by assets/js/main.js -->
            </div>
        </div>
    </div>

    <!-- Floating tooltip for seat maps -->
    <div id="tooltip" class="tooltip" style="display: none; position: absolute; background: rgba(0,0,0,0.85); color: white; padding: 6px 12px; border-radius: 4px; font-size: 0.8rem; pointer-events: none; z-index: 10000; box-shadow: var(--shadow-md);"></div>

    <!-- Client-side Scripts -->
    <script src="assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/main.js?v=<?php echo time(); ?>"></script>

    <script>
        $(document).ready(function() {
            // View Mode Switcher JS
            $('.view-switcher-btn').on('click', function() {
                $('.view-switcher-btn').removeClass('active');
                $(this).addClass('active');

                var targetView = $(this).data('view');
                $('.content-view-panel').hide();
                $(targetView).show();
            });

            // Instant Movie Filter across cards and table rows
            $('#movie-search').on('keyup input', function() {
                var query = $(this).val().toLowerCase().trim();
                $('.searchable-movie-card').each(function() {
                    var title = $(this).data('title');
                    if (!query || title.indexOf(query) !== -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });
        });
    </script>
</body>
</html>