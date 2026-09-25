<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

Security::startSession();

// Setup DB connection status
$db_configured = true;
$tables_missing = false;
$active_trackers = [];
try {
    $db = Cinepulse\Database::getInstance()->getConnection();
    $stmt1 = $db->query("SHOW TABLES LIKE 'tracked_showtimes'");
    if ($stmt1->rowCount() === 0) {
        $tables_missing = true;
    } else {
        $stmt_act = $db->prepare("SELECT vista_session_id FROM tracked_showtimes WHERE status = 'active'");
        $stmt_act->execute();
        $active_trackers = $stmt_act->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }
} catch (Exception $e) {
    $db_configured = false;
}

// Fetch list of theaters
$locations = [];
$locFile = dirname(__DIR__) . '/config/locations.json';
if (file_exists($locFile)) {
    $locations = json_decode(file_get_contents($locFile), true) ?: [];
}

$movie_id = Security::sanitizeInput($_GET['movie_id'] ?? '', 'string');
$movie_name_filter = $_GET['movie_name'] ?? '';
$location_id = Security::sanitizeInput($_GET['locationId'] ?? '', 'int');
$date = Security::sanitizeInput($_GET['date'] ?? date('Y-m-d'), 'date');

$api = new CineplexAPI();
$movies_list = [];
$cineplex_date = date('m+d+Y', strtotime($date));

// Aggregate from multiple major hubs (Toronto, Vaughan, Vancouver, Montreal) to build a massive global list
$hubs = $location_id ? [$location_id] : [7402, 7408, 1422, 9406]; 
$movies_map = [];

// Also inject the highly anticipated movies so they are always scannable even if not playing today at these specific hubs
$injected_movies = [
    ['id' => 'odyssey-2026', 'name' => 'The Odyssey', 'smallPosterImageUrl' => 'https://media.cineplex.com/placeholder.jpg'],
    ['id' => 'avengers-doomsday', 'name' => 'Avengers Doomsday', 'smallPosterImageUrl' => 'https://media.cineplex.com/placeholder.jpg'],
    ['id' => 'dune-3', 'name' => 'Dune 3', 'smallPosterImageUrl' => 'https://media.cineplex.com/placeholder.jpg']
];
foreach ($injected_movies as $im) {
    $movies_map[$im['id']] = $im;
}

foreach ($hubs as $hub) {
    $feed = $api->fetchShowtimes($hub, $cineplex_date);
    if (!isset($feed['error']) && !empty($feed[0]['dates'][0]['movies'])) {
        foreach ($feed[0]['dates'][0]['movies'] as $movie) {
            $movies_map[$movie['id']] = $movie;
        }
    }
}
$movies_list = array_values($movies_map);

// Fetch showtimes if location and movie selected
$showtimes_data = [];
$all_sessions = [];
if ($movie_name_filter && $location_id) {
    $cineplex_date = date('m+d+Y', strtotime($date));
    $raw_showtimes = $api->fetchShowtimes($location_id, $cineplex_date);
    if (!isset($raw_showtimes['error'])) {
        $raw_movies = $raw_showtimes[0]['dates'][0]['movies'] ?? [];
        
        // Filter and flatten only for the selected movie
        foreach ($raw_movies as $movie) {
            $currentName = $movie['name'] ?? 'Unknown';
            if ($currentName === $movie_name_filter || (isset($movie['id']) && $movie['id'] == $movie_id)) {
                $runtime = $movie['runtimeInMinutes'] ?? $movie['duration'] ?? 120;
                if (!empty($movie['experiences'])) {
                    foreach ($movie['experiences'] as $exp) {
                        $expName = implode(', ', $exp['experienceTypes'] ?? []);
                        if (!empty($exp['sessions'])) {
                            foreach ($exp['sessions'] as $session) {
                                $audName = $session['auditorium'] ?? $session['auditoriumName'] ?? 'Auditorium';
                                $start_time = strtotime($session['showStartDateTime']);
                                
                                $all_sessions[] = [
                                    'movie' => $currentName,
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
        }
        usort($all_sessions, function($a, $b) {
            return $a['start'] <=> $b['start'];
        });
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>🎬 Cinepulse — Global Movies</title>
    
    <!-- Open Graph & Twitter Social Share Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="🎬 Cinepulse — Global Movies Directory">
    <meta property="og:description" content="Explore currently playing movies, theater availability, and live seating maps across Cinepulse locations.">
    <meta property="og:image" content="/assets/images/share/portal.jpg">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="🎬 Cinepulse — Global Movies Directory">
    <meta name="twitter:description" content="Explore currently playing movies, theater availability, and live seating maps across Cinepulse locations.">
    <meta name="twitter:image" content="/assets/images/share/portal.jpg">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; }
        .main-wrapper { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .nav-links a.active { background: var(--theme-primary); box-shadow: var(--shadow-sm); }
        
        .global-movie-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px;
        }
        .global-movie-card {
            background: var(--bg-secondary); border: 1px solid var(--border-light); border-radius: 8px;
            padding: 15px; transition: transform 0.2s; cursor: pointer; text-decoration: none; color: inherit;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .global-movie-card:hover { transform: translateY(-3px); border-color: var(--theme-primary); box-shadow: var(--shadow-md); }
        .global-movie-title { font-weight: 700; font-size: 1.1rem; color: var(--text-primary); margin-bottom: 10px; }
        .global-movie-meta { font-size: 0.8rem; color: var(--text-secondary); }
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
                <a href="/schedule">📅 Schedule</a>
                <a href="/movies" class="active">🎬 Movies</a>
                <a href="/planner">🍿 Planner</a>
                <a href="/admin/tracker">📈 Tracker</a>
                <a href="/admin/dashboard">📊 Dashboard</a>
                <a href="/admin/scan-logs">🔍 Scan Logs</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">

        <?php if (!$movie_name_filter): ?>
            <div class="form-section glass-card" style="margin-bottom: 30px; padding: 25px;">
                <h2 style="margin-top:0;">🌐 Global Movies Directory</h2>
                <p style="color: var(--text-secondary);">Select a movie to find out which theaters are playing it today.</p>
                
                <input type="text" id="global-movie-search" placeholder="🔍 Search movie titles..." style="width: 100%; max-width: 400px; padding: 10px 15px; border-radius: 6px; border: 1px solid var(--border-light); margin-bottom: 20px;">
                
                <div class="global-movie-grid" id="movie-grid">
                    <?php if (is_array($movies_list) && !empty($movies_list)): ?>
                        <?php foreach ($movies_list as $mv): 
                            // The Cineplex movies endpoint structure might vary, adjust safely
                            $mName = $mv['name'] ?? $mv['title'] ?? 'Unknown';
                            $mId = $mv['id'] ?? '';
                        ?>
                        <a href="?movie_name=<?php echo urlencode($mName); ?>&movie_id=<?php echo urlencode($mId); ?>" class="global-movie-card">
                            <div class="global-movie-title"><?php echo htmlspecialchars($mName); ?></div>
                            <div class="global-movie-meta">Tap to find theaters ➔</div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: var(--color-warning);">No movies returned from global feed. Fallback: Search using the Schedule tab.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            
            <div style="margin-bottom: 20px;">
                <a href="movies.php" style="color: var(--theme-primary); text-decoration: none; font-weight: 600;">← Back to Global Directory</a>
            </div>
            
            <div class="form-section glass-card" style="margin-bottom: 30px; padding: 25px;">
                <h2 style="margin-top:0;">🍿 <?php echo htmlspecialchars($movie_name_filter); ?></h2>
                
                <div style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="date-selector">📅 Scan Date:</label>
                        <input type="date" id="date-selector" name="date" value="<?php echo $date; ?>" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-light);">
                    </div>
                </div>
                
                <?php if (!$location_id): ?>
                    <div id="scanning-status" style="color: var(--text-secondary); margin-bottom: 15px;">
                        <span class="spinner" style="display: inline-block; animation: spin 1s linear infinite;">⏳</span> Scanning theaters for availability...
                    </div>
                    
                    <div class="global-movie-grid" id="availability-grid">
                        <!-- Populated by JS -->
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($location_id): ?>
                <?php if (!empty($all_sessions)): ?>
                    <!-- DASHBOARD WIDGETS -->
                    <div class="dashboard-widgets" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                        <div class="widget glass-card" style="padding: 20px; text-align: center;">
                            <h4 style="margin: 0; color: var(--text-secondary); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px;">Total Showtimes</h4>
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
                <?php else: ?>
                    <div class="glass-card" style="padding: 30px; text-align: center; color: var(--text-secondary);">
                        <p>No showtimes found for <strong><?php echo htmlspecialchars($movie_name_filter); ?></strong> at this location on this date.</p>
                    </div>
                <?php endif; ?>
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
    <script src="assets/js/design-options-modal.js?v=<?php echo time(); ?>" defer></script>
    <script src="assets/js/main.js?v=<?php echo time(); ?>" defer></script>
    <script>
        // Simple client-side search for global movie list
        document.getElementById('global-movie-search')?.addEventListener('input', function(e) {
            const val = e.target.value.toLowerCase();
            document.querySelectorAll('.global-movie-card').forEach(card => {
                const title = card.querySelector('.global-movie-title').textContent.toLowerCase();
                if (title.includes(val)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Global Theater Availability Scanner
        <?php if ($movie_name_filter && !$location_id): ?>
            const locations = <?php echo json_encode($locations); ?>;
            const targetMovie = <?php echo json_encode($movie_name_filter); ?>;
            const targetDate = document.getElementById('date-selector').value;
            const grid = document.getElementById('availability-grid');
            const status = document.getElementById('scanning-status');
            
            // Map object to array
            const locationsArr = Object.keys(locations).map(key => ({ name: key, id: locations[key] }));
            
            // Shuffle to scan randomly for a cool effect, or just take top 10 to prevent rate limits
            // We will scan top 15 most popular locations first
            const scanTargets = locationsArr.slice(0, 15);
            let foundCount = 0;
            
            const renderCard = (loc, isPlaying) => {
                if (!isPlaying) return; // Only render if playing
                foundCount++;
                const url = `?movie_name=${encodeURIComponent(targetMovie)}&locationId=${loc.id}&date=${targetDate}`;
                grid.innerHTML += `
                    <a href="${url}" class="global-movie-card" style="border-left: 4px solid var(--color-success);">
                        <div class="global-movie-title" style="font-size: 1rem;">${loc.name}</div>
                        <div class="global-movie-meta" style="color: var(--color-success); font-weight: 700;">🟢 Playing Today</div>
                    </a>
                `;
            };

            const scanTheaters = async () => {
                grid.innerHTML = '';
                foundCount = 0;
                status.innerHTML = `<span style="display:inline-block; animation:spin 1s linear infinite;">⏳</span> Scanning theaters...`;
                
                const promises = scanTargets.map(loc => 
                    fetch(`api.php?action=check_movie_availability&theatre_id=${loc.id}&date=${targetDate}&movie_name=${encodeURIComponent(targetMovie)}`)
                        .then(res => res.json())
                        .then(data => renderCard(loc, data.is_playing))
                        .catch(err => console.error(err))
                );
                
                await Promise.allSettled(promises);
                
                if (foundCount === 0) {
                    status.innerHTML = `❌ No theaters found playing this movie.`;
                } else {
                    status.innerHTML = `✅ Found ${foundCount} theaters playing this movie.`;
                }
            };
            
            scanTheaters();
            
            document.getElementById('date-selector').addEventListener('change', () => {
                window.location.href = `?movie_name=${encodeURIComponent(targetMovie)}&date=${document.getElementById('date-selector').value}`;
            });
        <?php endif; ?>
        
        <?php if ($location_id): ?>
            document.getElementById('date-selector')?.addEventListener('change', () => {
                window.location.href = `?movie_name=${encodeURIComponent(<?php echo json_encode($movie_name_filter); ?>)}&locationId=<?php echo $location_id; ?>&date=${document.getElementById('date-selector').value}`;
            });
        <?php endif; ?>
    </script>
    <script src="assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
</body>
</html>
