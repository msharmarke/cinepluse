<?php
/**
 * Cinepulse — Proximity Double Feature Scheduler
 */

// Initialize Autoloader
require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

Security::startSession();

// Fetch locations
$locations = [];
$locFile = dirname(__DIR__) . '/config/locations.json';
if (file_exists($locFile)) {
    $locations = json_decode(file_get_contents($locFile), true) ?: [];
}

// Request parameters
$location_id = Security::sanitizeInput($_GET['locationId'] ?? 7411, 'int');
$date = Security::sanitizeInput($_GET['date'] ?? date('Y-m-d'), 'date');
$fetch = isset($_GET['fetch']) || isset($_GET['locationId']);

$cineplex_date = date('m+d+Y', strtotime($date));
$showtimes_data = [];
$api_error = '';

if ($fetch) {
    try {
        $api = new CineplexAPI();
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

// Find compatible double features
$double_features = [];
if (!empty($showtimes_data)) {
    $flat_sessions = [];
    foreach ($showtimes_data as $movie) {
        $name = $movie['name'];
        $runtime = $movie['runtimeInMinutes'] ?? $movie['duration'] ?? 120;
        
        if (!empty($movie['experiences'])) {
            foreach ($movie['experiences'] as $exp) {
                if (!empty($exp['sessions'])) {
                    foreach ($exp['sessions'] as $sess) {
                        $flat_sessions[] = [
                            'movie_name' => $name,
                            'runtime' => $runtime,
                            'session_id' => $sess['vistaSessionId'],
                            'start_time' => strtotime($sess['showStartDateTime']),
                            'start_formatted' => date('g:i A', strtotime($sess['showStartDateTime'])),
                            'auditorium' => $sess['auditorium'] ?? $sess['auditoriumName'] ?? 'Auditorium',
                            'experience' => implode(', ', $exp['experienceTypes'] ?? [])
                        ];
                    }
                }
            }
        }
    }

    // Pair and check timing compatibility
    $total = count($flat_sessions);
    for ($i = 0; $i < $total; $i++) {
        for ($j = 0; $j < $total; $j++) {
            if ($i === $j) continue;
            // Only suggest combinations of distinct movies
            if ($flat_sessions[$i]['movie_name'] === $flat_sessions[$j]['movie_name']) continue;
            
            $compat = ShowtimeService::checkDoubleFeatureCompatibility($flat_sessions[$i], $flat_sessions[$j]);
            if ($compat['compatible']) {
                $double_features[] = [
                    'first' => $flat_sessions[$i],
                    'second' => $flat_sessions[$j],
                    'gap' => $compat['gap'],
                    'message' => $compat['message'],
                    'warning' => $compat['warning']
                ];
            }
        }
    }

    // Sort by gap length (shortest wait times first)
    usort($double_features, function($a, $b) {
        return $a['gap'] - $b['gap'];
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🍿 Cinepulse — Double Features</title>
    
    <!-- Open Graph & Twitter Social Share Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="🍿 Cinepulse — Double Feature Movie Planner">
    <meta property="og:description" content="Plan back-to-back double feature movie marathons with zero buffer conflicts and instant timetable generation.">
    <meta property="og:image" content="/assets/images/share/cinematic.jpg">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="🍿 Cinepulse — Double Feature Movie Planner">
    <meta name="twitter:description" content="Plan back-to-back double feature movie marathons with zero buffer conflicts and instant timetable generation.">
    <meta name="twitter:image" content="/assets/images/share/cinematic.jpg">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        body {
            font-family: 'Outfit', sans-serif;
        }
        .main-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
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
        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.35);
        }
        .nav-links a.active {
            background: var(--theme-primary);
            box-shadow: var(--shadow-sm);
        }
        .double-feature-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .double-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .route-path {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        .movie-leg {
            flex: 1 1 280px;
            background: var(--bg-tertiary);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--theme-primary);
        }
        .route-separator {
            font-size: 2rem;
            color: var(--text-secondary);
            text-align: center;
            align-self: center;
        }
        .badge-gap {
            background: rgba(16, 185, 129, 0.2);
            color: #059669;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #d97706;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-top: 8px;
            display: inline-block;
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
                <a href="/schedule">📅 Schedule</a>
                <a href="/movies">🎬 Movies</a>
                <a href="/planner" class="active">🍿 Planner</a>
                <a href="/admin/tracker">📈 Tracker</a>
                <a href="/admin/dashboard">📊 Dashboard</a>
                <a href="/admin/scan-logs">🔍 Scan Logs</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">

        <!-- SEARCH FILTERS -->
        <div class="form-section glass-card" style="margin-bottom: 30px; padding: 25px;">
            <h2 style="margin-top:0;">🍿 Schedule Back-To-Back Double Features</h2>
            <form method="get" action="">
                <input type="hidden" name="fetch" value="1">
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
                        <button type="submit" class="button-primary">🍿 Match Combo Features</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($fetch): ?>
            <?php if (!empty($api_error)): ?>
                <div class="notice notice-error">
                    <strong>Schedule Fetch Error:</strong> <?php echo htmlspecialchars($api_error); ?>
                </div>
            <?php elseif (empty($showtimes_data)): ?>
                <div class="notice notice-info">
                    No movie schedules found on this date for the selected theater. Try choosing another day.
                </div>
            <?php else: ?>
                <!-- Script to inject raw Cineplex showtimes payload for Track 2 builder -->
                <script>
                    window.allMoviesData = <?php echo json_encode($showtimes_data); ?>;
                    window.locationIdParam = <?php echo $location_id; ?>;
                </script>

                <!-- Track Selector Tabs -->
                <div class="tabs-container" style="margin-bottom: 25px; display: flex; gap: 10px; border-bottom: 2px solid var(--border-primary); padding-bottom: 10px; overflow-x: auto;">
                    <button type="button" class="tab-btn" data-tab="track-3" style="background: none; border: none; padding: 10px 20px; font-weight: bold; font-size: 1.1rem; color: var(--text-primary); border-bottom: 3px solid var(--color-primary-500); cursor: pointer; transition: all 0.2s;">
                        ✨ Auto-Optimizer (Track 3)
                    </button>
                    <button type="button" class="tab-btn active" data-tab="track-1" style="background: none; border: none; padding: 10px 20px; font-weight: bold; font-size: 1.1rem; color: var(--text-secondary); cursor: pointer; transition: all 0.2s;">
                        📋 All Pairs (Track 1)
                    </button>
                    <button type="button" class="tab-btn" data-tab="track-2" style="background: none; border: none; padding: 10px 20px; font-weight: bold; font-size: 1.1rem; color: var(--text-secondary); cursor: pointer; transition: all 0.2s;">
                        🛠️ Custom Builder (Track 2)
                    </button>
                </div>

                <!-- Track 3 Content Container (Auto-Optimizer) -->
                <div id="tab-content-track-3" class="tab-content" style="display: block;">
                    <div class="custom-builder-container glass-card" style="padding: 25px; margin-bottom: 25px;">
                        <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--theme-primary); display: flex; align-items: center; gap: 10px;">
                            <span>✨</span> AI Auto-Optimizer
                        </h3>
                        <p style="color: var(--text-secondary); margin-bottom: 25px; font-size: 0.95rem;">
                            Select 2 or 3 movies you want to see today. The optimizer will instantly calculate the top 3 best itineraries with the perfect layover gaps (15 to 45 mins).
                        </p>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 25px;">
                            <div class="form-group" style="flex: 1 1 200px;">
                                <label for="opt-movie-1" style="font-weight:600; display:block; margin-bottom:5px;">🍿 Movie 1:</label>
                                <select id="opt-movie-1" style="width: 100%; padding: 10px; border-radius: 6px;"></select>
                            </div>
                            <div class="form-group" style="flex: 1 1 200px;">
                                <label for="opt-movie-2" style="font-weight:600; display:block; margin-bottom:5px;">🍿 Movie 2:</label>
                                <select id="opt-movie-2" style="width: 100%; padding: 10px; border-radius: 6px;"></select>
                            </div>
                            <div class="form-group" style="flex: 1 1 200px;">
                                <label for="opt-movie-3" style="font-weight:600; display:block; margin-bottom:5px;">🍿 Movie 3 (Optional):</label>
                                <select id="opt-movie-3" style="width: 100%; padding: 10px; border-radius: 6px;"></select>
                            </div>
                            <div class="form-group" style="align-self: flex-end;">
                                <button type="button" id="run-optimizer-btn" class="button-primary" style="padding: 10px 20px;">⚡ Run Optimizer</button>
                            </div>
                        </div>

                        <div id="optimizer-results" style="display: none; margin-top: 30px;">
                            <h4 style="margin-top: 0; border-bottom: 1px solid var(--border-light); padding-bottom: 10px;">🏆 Top 3 Optimal Itineraries</h4>
                            <div id="optimizer-list" style="display: flex; flex-direction: column; gap: 15px; margin-top: 15px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Track 1 Container -->
                <div id="tab-content-track-1" class="tab-content" style="display: none;">
                    <?php if (empty($double_features)): ?>
                        <div class="notice notice-info" style="margin-bottom: 25px;">
                            No compatible double features schedules found on this date for the selected theater. Try using the Custom Builder tab!
                        </div>
                    <?php else: ?>
                        <h3 style="margin-bottom: 20px;">🎉 Found <?php echo count($double_features); ?> Compatible Double Feature Combos</h3>
                        
                        <!-- Client-side Filters Bar -->
                        <div class="filters-container glass-card" style="margin-bottom: 25px; padding: 15px 20px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between;">
                            <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center; width: 100%;">
                                <div class="form-group" style="margin:0; flex: 1 1 200px;">
                                    <input type="text" id="combo-search" placeholder="🔍 Filter by movie title..." style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-light); background: var(--bg-tertiary); color: var(--text-primary);">
                                </div>
                                
                                <div class="form-group" style="margin:0;">
                                    <select id="gap-filter" style="padding: 8px 12px; border-radius:6px; background: var(--bg-tertiary); color: var(--text-primary); border: 1px solid var(--border-light);">
                                        <option value="">⏱ All Layover Gaps</option>
                                        <option value="30">⏱ Max 30 min wait</option>
                                        <option value="60">⏱ Max 60 min wait</option>
                                        <option value="90">⏱ Max 90 min wait</option>
                                        <option value="120">⏱ Max 120 min wait</option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin:0;">
                                    <select id="experience-filter" style="padding: 8px 12px; border-radius:6px; background: var(--bg-tertiary); color: var(--text-primary); border: 1px solid var(--border-light);">
                                        <option value="">✨ All Formats</option>
                                        <option value="ultraavx">UltraAVX</option>
                                        <option value="imax">IMAX</option>
                                        <option value="3d">3D</option>
                                        <option value="vip">VIP</option>
                                        <option value="d-box">D-BOX</option>
                                        <option value="screenx">ScreenX</option>
                                        <option value="regular">Regular</option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin:0; margin-left: auto;">
                                    <select id="sort-combos" style="padding: 8px 12px; border-radius:6px; background: var(--bg-tertiary); color: var(--text-primary); border: 1px solid var(--border-light);">
                                        <option value="gap-asc">Sort: Gap (Shortest First)</option>
                                        <option value="gap-desc">Sort: Gap (Longest First)</option>
                                        <option value="time-asc">Sort: Start Time (Earliest First)</option>
                                        <option value="time-desc">Sort: Start Time (Latest First)</option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin:0;">
                                    <button type="button" id="apply-filters-btn" class="button-primary" style="padding: 8px 18px; border-radius: 6px; font-weight: 600; font-size: 0.9rem; border: none; cursor: pointer; transition: background 0.2s;">🍿 Apply Filters</button>
                                </div>
                            </div>
                        </div>

                        <div class="double-features-list">
                            <?php foreach ($double_features as $combo): 
                                $first = $combo['first'];
                                $second = $combo['second'];
                            ?>
                                <div class="double-feature-card glass-card"
                                     data-first-movie="<?php echo htmlspecialchars(strtolower($first['movie_name'] ?? '')); ?>"
                                     data-second-movie="<?php echo htmlspecialchars(strtolower($second['movie_name'] ?? '')); ?>"
                                     data-first-exp="<?php echo htmlspecialchars(strtolower($first['experience'] ?? '')); ?>"
                                     data-second-exp="<?php echo htmlspecialchars(strtolower($second['experience'] ?? '')); ?>"
                                     data-gap="<?php echo $combo['gap']; ?>"
                                     data-start-time="<?php echo $first['start_time']; ?>">
                                    <div class="route-path">
                                        <!-- First Leg -->
                                        <div class="movie-leg">
                                            <h4 style="margin:0 0 5px 0; color:var(--text-primary);"><?php echo htmlspecialchars($first['movie_name']); ?></h4>
                                            <p style="margin: 0; font-size:0.85rem; color:var(--text-secondary);">
                                                🕐 Start: <strong><?php echo $first['start_formatted']; ?></strong> | <?php echo $first['experience']; ?>
                                                <br>🚪 <?php echo htmlspecialchars($first['auditorium']); ?> | ⏱ <?php echo $first['runtime']; ?> mins
                                            </p>
                                        </div>

                                        <div class="route-separator">➡️</div>

                                        <!-- Second Leg -->
                                        <div class="movie-leg" style="border-left-color: var(--theme-secondary);">
                                            <h4 style="margin:0 0 5px 0; color:var(--text-primary);"><?php echo htmlspecialchars($second['movie_name']); ?></h4>
                                            <p style="margin: 0; font-size:0.85rem; color:var(--text-secondary);">
                                                🕐 Start: <strong><?php echo $second['start_formatted']; ?></strong> | <?php echo $second['experience']; ?>
                                                <br>🚪 <?php echo htmlspecialchars($second['auditorium']); ?> | ⏱ <?php echo $second['runtime']; ?> mins
                                            </p>
                                        </div>
                                    </div>

                                    <div style="border-top: 1px solid var(--border-light); padding-top: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                        <div>
                                            <span class="badge-gap">⏱ Layover Gap: <?php echo $combo['gap']; ?> minutes</span>
                                            <p style="margin: 6px 0 0 0; font-size:0.9rem; color: var(--text-secondary);"><?php echo htmlspecialchars($combo['message']); ?></p>
                                        </div>
                                        <?php if (!empty($combo['warning'])): ?>
                                            <span class="badge-warning">⚠ <?php echo htmlspecialchars($combo['warning']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div> <!-- End of tab-content-track-1 -->

                <!-- Track 2 Content Container -->
                <div id="tab-content-track-2" class="tab-content" style="display: none;">
                    <div class="custom-builder-container glass-card" style="padding: 25px; margin-bottom: 25px;">
                        <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--text-primary); display: flex; align-items: center; gap: 10px;">
                            <span>🛠️</span> Interactive Custom Builder (Track 2)
                        </h3>
                        <p style="color: var(--text-secondary); margin-bottom: 25px; font-size: 0.95rem;">
                            Pick any two movie showtimes to analyze scheduling, time overlaps, auditorium room locations, and layout details.
                        </p>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 25px; align-items: stretch; margin-bottom: 25px;">
                            <!-- Movie 1 Selector -->
                            <div style="flex: 1 1 300px; background: var(--bg-tertiary); padding: 20px; border-radius: 12px; border: 1px solid var(--border-primary); display: flex; flex-direction: column; justify-content: space-between;">
                                <div>
                                    <h4 style="margin-top:0; margin-bottom: 15px; color: var(--color-primary-500); display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 1.2rem;">🍿</span> First Show (Movie 1)
                                    </h4>
                                    <div class="form-group" style="margin-bottom: 15px;">
                                        <label for="builder-movie-1" style="font-size:0.85rem; font-weight:600; margin-bottom: 6px; display: block;">Select Movie:</label>
                                        <select id="builder-movie-1" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-primary); background: var(--bg-secondary); color: var(--text-primary); font-size: 15px;"></select>
                                    </div>
                                    <div class="form-group" style="margin-bottom: 0; display: none;" id="builder-showtime-1-container">
                                        <label for="builder-showtime-1" style="font-size:0.85rem; font-weight:600; margin-bottom: 6px; display: block;">Select Showtime:</label>
                                        <select id="builder-showtime-1" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-primary); background: var(--bg-secondary); color: var(--text-primary); font-size: 15px;"></select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Arrow Divider -->
                            <div style="display: flex; align-items: center; justify-content: center; font-size: 2rem; color: var(--color-primary-300); min-width: 40px;">➡️</div>
                            
                            <!-- Movie 2 Selector -->
                            <div style="flex: 1 1 300px; background: var(--bg-tertiary); padding: 20px; border-radius: 12px; border: 1px solid var(--border-primary); display: flex; flex-direction: column; justify-content: space-between;">
                                <div>
                                    <h4 style="margin-top:0; margin-bottom: 15px; color: var(--color-accent-purple); display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 1.2rem;">🍿</span> Second Show (Movie 2)
                                    </h4>
                                    <div class="form-group" style="margin-bottom: 15px;">
                                        <label for="builder-movie-2" style="font-size:0.85rem; font-weight:600; margin-bottom: 6px; display: block;">Select Movie:</label>
                                        <select id="builder-movie-2" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-primary); background: var(--bg-secondary); color: var(--text-primary); font-size: 15px;"></select>
                                    </div>
                                    <div class="form-group" style="margin-bottom: 0; display: none;" id="builder-showtime-2-container">
                                        <label for="builder-showtime-2" style="font-size:0.85rem; font-weight:600; margin-bottom: 6px; display: block;">Select Showtime:</label>
                                        <select id="builder-showtime-2" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-primary); background: var(--bg-secondary); color: var(--text-primary); font-size: 15px;"></select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Analysis Results -->
                        <div id="builder-analysis-area" style="display: none; background: var(--bg-secondary); border: 2px solid var(--border-primary); border-radius: 16px; padding: 30px; margin-top: 25px; box-shadow: var(--shadow-md);">
                            
                            <!-- Header for social media -->
                            <div id="builder-social-header" style="text-align: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-light);">
                                <h2 style="margin: 0 0 10px 0; font-size: 2rem; font-weight: 800; background: var(--gradient-hero); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">🍿 Cinepulse Double Feature</h2>
                                <p id="builder-date-display" style="margin: 0 0 5px 0; color: var(--text-primary); font-size: 1.2rem; font-weight: 600;"></p>
                                <p id="builder-location-display" style="margin: 0; color: var(--text-secondary); font-size: 1rem;"></p>
                            </div>

                            <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">
                                <div id="builder-card-1" style="flex: 1 1 250px; padding: 20px; border-radius: 12px; background: var(--bg-tertiary); border-left: 5px solid var(--color-primary-500); box-shadow: var(--shadow-sm);"></div>
                                <div id="builder-card-2" style="flex: 1 1 250px; padding: 20px; border-radius: 12px; background: var(--bg-tertiary); border-left: 5px solid var(--color-accent-purple); box-shadow: var(--shadow-sm);"></div>
                            </div>
                            <!-- Proximity / Layover Metrics badge -->
                            <div id="builder-metrics-bar" style="padding: 20px; border-radius: 12px; font-weight: 600; display: flex; flex-direction: column; gap: 15px; background: var(--bg-tertiary); box-shadow: var(--shadow-sm);"></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </main>
    </div>

    <!-- Reusing Modals & JS from Main App -->
    <script src="assets/js/shared.js?v=<?php echo time(); ?>" defer></script>
    <script src="assets/js/design-options-modal.js?v=<?php echo time(); ?>" defer></script>
    <script src="assets/js/main.js?v=<?php echo time(); ?>" defer></script>
</body>
</html>
