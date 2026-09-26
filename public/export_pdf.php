<?php
/**
 * Cinepulse — Publication-Ready Executive Weekly PDF & Print Exporter
 * Generates an executive, auditorium-based & movie-based weekly schedule report
 * featuring visual room timeline grids, start & finish time ranges, and A4 PDF export capabilities.
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

Security::startSession();

// Fetch theater locations map (enabled locations first)
$activeLocations = ShowtimeService::getTrackerTheatres(true);
$allLocations = ShowtimeService::getTrackerTheatres(false);
$locations = !empty($activeLocations) ? $activeLocations : $allLocations;

// Default to first active theater ID if available
$defaultTheatreId = !empty($activeLocations) ? reset($activeLocations) : (!empty($allLocations) ? reset($allLocations) : 7402);
$theatreId = Security::sanitizeInput($_GET['locationId'] ?? $_GET['location_id'] ?? $defaultTheatreId, 'int');

// Find theatre name
$theatreName = 'Cineplex Cinema';
foreach ($locations as $name => $id) {
    if ((int)$id === (int)$theatreId) {
        $theatreName = $name;
        break;
    }
}

// Determine theatrical week start (Friday) and end (Thursday)
if (!empty($_GET['start_date']) && strtotime($_GET['start_date'])) {
    $inputSec = strtotime($_GET['start_date']);
    $dayOfWeek = (int)date('N', $inputSec);
    if ($dayOfWeek === 5) {
        $startFridaySec = strtotime('today', $inputSec);
    } else {
        $startFridaySec = strtotime('last Friday', $inputSec);
    }
} else {
    $todaySec = strtotime('today');
    $dayOfWeek = (int)date('N', $todaySec);
    if ($dayOfWeek === 5) {
        $startFridaySec = $todaySec;
    } else {
        $startFridaySec = strtotime('next Friday', $todaySec);
    }
}

$startFridayStr = date('Y-m-d', $startFridaySec);
$endThursdayStr = date('Y-m-d', strtotime('+6 days', $startFridaySec));
$oneDayPerPage = isset($_GET['one_per_page']) && $_GET['one_per_page'] === '1';

// Check DB cache for pre-cached showtimes first
$cachedDbShowtimes = [];
try {
    $db = \Cinepulse\Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM showtimes WHERE theatre_id = ? AND show_date BETWEEN ? AND ? ORDER BY show_start_time ASC");
    $stmt->execute([$theatreId, $startFridayStr, $endThursdayStr]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $cachedDbShowtimes[$row['show_date']][] = $row;
    }
} catch (Exception $e) {
    $cachedDbShowtimes = [];
}

// Color generator helper function for movie bars
if (!function_exists('getMovieColorPdf')) {
    function getMovieColorPdf($movieTitle) {
        $hash = md5($movieTitle);
        $hue = hexdec(substr($hash, 0, 3)) % 360;
        return "hsl({$hue}, 65%, 40%)";
    }
}

// Fetch showtimes for all 7 days of the week
$api = new CineplexAPI();
$weekDays = [];
$totalShowtimeCount = 0;
$totalMoviesCount = 0;
$uniqueExperiences = [];
$uniqueAuditoriums = [];
$allMovieTitlesMap = [];

for ($i = 0; $i < 7; $i++) {
    $currentSec = strtotime("+{$i} days", $startFridaySec);
    $currentDate = date('Y-m-d', $currentSec);
    $cineplexDate = date('m+d+Y', $currentSec);
    $dayLabel = date('l, F j, Y', $currentSec);
    $dayName = date('l', $currentSec);

    $moviesList = [];
    $auditoriumMatrix = [];
    $allDaySessions = [];

    if (!empty($cachedDbShowtimes[$currentDate])) {
        // Build from database cache
        foreach ($cachedDbShowtimes[$currentDate] as $row) {
            $movieTitle = $row['movie_name'] ?? 'Unknown Title';
            $allMovieTitlesMap[$movieTitle] = true;
            $expName = $row['experience_type'] ?? 'Standard';
            $aud = $row['auditorium_name'] ?? 'Auditorium';
            $uniqueExperiences[$expName] = true;
            $uniqueAuditoriums[$aud] = true;

            $startTime = strtotime($currentDate . ' ' . ($row['show_start_time'] ?? '12:00:00'));
            $runtime = (int)($row['runtime_minutes'] ?? 120);
            $endTime = $startTime + ($runtime * 60);

            $startFormatted = date('g:i A', $startTime);
            $endFormatted = date('g:i A', $endTime);
            $timeRange = $startFormatted . ' – ' . $endFormatted;

            $sessionData = [
                'movie' => $movieTitle,
                'runtime' => $runtime,
                'experience' => $expName,
                'auditorium' => $aud,
                'start' => $startTime,
                'start_formatted' => $startFormatted,
                'end' => $endTime,
                'end_formatted' => $endFormatted,
                'time_range' => $timeRange,
                'session_id' => $row['showtime_id'] ?? ''
            ];

            $allDaySessions[] = $sessionData;
            $auditoriumMatrix[$aud][] = $sessionData;

            if (!isset($moviesList[$movieTitle])) {
                $moviesList[$movieTitle] = [
                    'title' => $movieTitle,
                    'runtime' => $runtime,
                    'formats' => []
                ];
            }

            $key = $expName . ' | ' . $aud;
            if (!isset($moviesList[$movieTitle]['formats'][$key])) {
                $moviesList[$movieTitle]['formats'][$key] = [
                    'experience' => $expName,
                    'auditorium' => $aud,
                    'times' => []
                ];
            }

            $moviesList[$movieTitle]['formats'][$key]['times'][] = [
                'time' => $startFormatted,
                'end_time' => $endFormatted,
                'time_range' => $timeRange,
                'timestamp' => $startTime,
                'session_id' => $row['showtime_id'] ?? ''
            ];
            $totalShowtimeCount++;
        }
    } else {
        // Fallback to Live API
        $showtimesData = [];
        try {
            $raw = $api->fetchShowtimes($theatreId, $cineplexDate);
            if (!isset($raw['error'])) {
                $showtimesData = $raw[0]['dates'][0]['movies'] ?? [];
            }
        } catch (Exception $e) {
            $showtimesData = [];
        }

        foreach ($showtimesData as $movie) {
            $movieTitle = $movie['name'] ?? $movie['title'] ?? 'Unknown Title';
            $runtime = (int)($movie['runtimeInMinutes'] ?? $movie['runtime'] ?? $movie['duration'] ?? 120);
            $allMovieTitlesMap[$movieTitle] = true;

            if (!isset($moviesList[$movieTitle])) {
                $moviesList[$movieTitle] = [
                    'title' => $movieTitle,
                    'runtime' => $runtime,
                    'formats' => []
                ];
            }

            if (!empty($movie['experiences'])) {
                foreach ($movie['experiences'] as $exp) {
                    $expTypes = $exp['experienceTypes'] ?? ['Standard'];
                    $expName = implode(', ', $expTypes);
                    $uniqueExperiences[$expName] = true;
                    
                    if (!empty($exp['sessions'])) {
                        foreach ($exp['sessions'] as $session) {
                            $aud = $session['auditorium'] ?? $session['auditoriumName'] ?? 'Auditorium';
                            $uniqueAuditoriums[$aud] = true;

                            $startTime = strtotime($session['showStartDateTime']);
                            $endTime = $startTime + ($runtime * 60);

                            $startFormatted = date('g:i A', $startTime);
                            $endFormatted = date('g:i A', $endTime);
                            $timeRange = $startFormatted . ' – ' . $endFormatted;

                            $sessionData = [
                                'movie' => $movieTitle,
                                'runtime' => $runtime,
                                'experience' => $expName,
                                'auditorium' => $aud,
                                'start' => $startTime,
                                'start_formatted' => $startFormatted,
                                'end' => $endTime,
                                'end_formatted' => $endFormatted,
                                'time_range' => $timeRange,
                                'session_id' => $session['vistaSessionId'] ?? ''
                            ];

                            $allDaySessions[] = $sessionData;
                            $auditoriumMatrix[$aud][] = $sessionData;

                            $key = $expName . ' | ' . $aud;
                            if (!isset($moviesList[$movieTitle]['formats'][$key])) {
                                $moviesList[$movieTitle]['formats'][$key] = [
                                    'experience' => $expName,
                                    'auditorium' => $aud,
                                    'times' => []
                                ];
                            }

                            $moviesList[$movieTitle]['formats'][$key]['times'][] = [
                                'time' => $startFormatted,
                                'end_time' => $endFormatted,
                                'time_range' => $timeRange,
                                'timestamp' => $startTime,
                                'session_id' => $session['vistaSessionId'] ?? ''
                            ];
                            $totalShowtimeCount++;
                        }
                    }
                }
            }
        }
    }

    // Sort auditorium matrix naturally by auditorium name
    uksort($auditoriumMatrix, 'strnatcasecmp');

    // Sort sessions in each auditorium chronologically
    foreach ($auditoriumMatrix as &$sessions) {
        usort($sessions, function($a, $b) {
            return $a['start'] <=> $b['start'];
        });
    }
    unset($sessions);

    // Sort times inside each format chronologically
    foreach ($moviesList as &$m) {
        foreach ($m['formats'] as &$f) {
            usort($f['times'], function($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });
        }
    }
    unset($m, $f);

    // Sort movies alphabetically
    ksort($moviesList);

    $weekDays[] = [
        'date' => $currentDate,
        'label' => $dayLabel,
        'day_name' => $dayName,
        'movies' => $moviesList,
        'auditorium_matrix' => $auditoriumMatrix,
        'all_sessions' => $allDaySessions
    ];
}

$totalMoviesCount = count($allMovieTitlesMap);
$prevWeekStart = date('Y-m-d', strtotime('-7 days', $startFridaySec));
$nextWeekStart = date('Y-m-d', strtotime('+7 days', $startFridaySec));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📄 Executive PDF Report — <?php echo htmlspecialchars($theatreName); ?> (<?php echo date('M j', $startFridaySec); ?> - <?php echo date('M j, Y', strtotime('+6 days', $startFridaySec)); ?>)</title>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="/assets/css/design-options-modal.css">

    <style>
        :root {
            --pdf-bg: #0b0f19;
            --pdf-card-bg: #111827;
            --pdf-text: #f9fafb;
            --pdf-text-muted: #9ca3af;
            --pdf-border: #374151;
            --pdf-accent: #3b82f6;
            --pdf-accent-gold: #f59e0b;
        }

        body.ink-saver-mode {
            --pdf-bg: #f8fafc;
            --pdf-card-bg: #ffffff;
            --pdf-text: #0f172a;
            --pdf-text-muted: #475569;
            --pdf-border: #cbd5e1;
            --pdf-accent: #2563eb;
            --pdf-accent-gold: #d97706;
        }

        * { box-sizing: border-box; }
        
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--pdf-bg);
            color: var(--pdf-text);
            margin: 0;
            padding: 0;
            font-size: 14px;
            line-height: 1.45;
        }

        /* Top Toolbar Controls */
        .no-print-bar {
            background: rgba(17, 24, 39, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(12px);
        }

        .no-print-bar h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-action {
            background: #e50914;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-action:hover {
            transform: translateY(-1px);
        }
        .btn-action-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .btn-action-secondary.active {
            background: #3b82f6;
            border-color: #3b82f6;
        }

        .select-custom {
            background: #0f172a;
            color: #ffffff;
            border: 1px solid #334155;
            padding: 8px 14px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.88rem;
            font-weight: 600;
        }

        /* Document Wrapper */
        .pdf-document-wrapper {
            max-width: 1120px;
            margin: 30px auto;
            background: var(--pdf-card-bg);
            color: var(--pdf-text);
            padding: 36px 42px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--pdf-border);
        }

        .pdf-cover-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--pdf-border);
            padding-bottom: 20px;
            margin-bottom: 24px;
            gap: 20px;
        }

        .brand-section h1 {
            font-size: 1.9rem;
            font-weight: 800;
            margin: 0;
            color: var(--pdf-text);
            font-family: 'Space Grotesk', sans-serif;
        }
        .brand-section h1 span.gold { color: var(--pdf-accent-gold); }
        .brand-section p { margin: 4px 0 0 0; font-size: 1.1rem; font-weight: 700; color: var(--pdf-accent); }

        .metadata-badge-group { text-align: right; }
        .date-badge {
            background: var(--pdf-accent);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 800;
            font-size: 0.9rem;
            display: inline-block;
        }

        /* Executive Metrics Strip */
        .metrics-summary-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 28px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--pdf-border);
            padding: 14px 18px;
            border-radius: 12px;
        }
        .summary-stat-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--pdf-text-muted); font-weight: 700; }
        .summary-stat-value { font-size: 1.45rem; font-weight: 800; color: var(--pdf-text); margin-top: 2px; }

        /* Day Section */
        .pdf-day-section {
            margin-bottom: 32px;
            border: 1px solid var(--pdf-border);
            border-radius: 14px;
            overflow: hidden;
            background: rgba(0, 0, 0, 0.15);
        }
        .day-header-banner {
            background: rgba(59, 130, 246, 0.15);
            border-bottom: 1px solid var(--pdf-border);
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 800;
            font-size: 1.05rem;
        }
        .day-showtime-counter {
            font-size: 0.8rem;
            background: rgba(255,255,255,0.1);
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: 600;
        }

        /* VISUAL ROOM TIMELINE GRAPH IN PDF */
        .pdf-timeline-container {
            padding: 16px;
            border-bottom: 1px solid var(--pdf-border);
            background: rgba(0, 0, 0, 0.2);
            overflow-x: auto;
        }
        .pdf-timeline-header-ticks {
            display: flex;
            position: relative;
            height: 24px;
            border-bottom: 1px solid var(--pdf-border);
            margin-left: 130px;
            margin-bottom: 8px;
        }
        .pdf-tick {
            position: absolute;
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--pdf-text-muted);
            transform: translateX(-50%);
            font-family: 'Space Grotesk', monospace;
        }
        .pdf-aud-row {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            height: 32px;
        }
        .pdf-aud-label {
            width: 130px;
            font-weight: 700;
            font-size: 0.8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 10px;
            color: var(--pdf-text);
        }
        .pdf-aud-track {
            flex: 1;
            height: 28px;
            position: relative;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            overflow: hidden;
        }
        .pdf-show-bar {
            position: absolute;
            height: 100%;
            top: 0;
            border-radius: 4px;
            display: flex;
            align-items: center;
            padding: 0 6px;
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.2);
        }

        /* AUDITORIUM-FIRST GRID CARDS */
        .pdf-aud-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 14px;
            padding: 16px;
        }
        .pdf-aud-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--pdf-border);
            border-radius: 10px;
            padding: 14px;
        }
        .pdf-aud-card-header {
            font-weight: 800;
            font-size: 0.95rem;
            color: var(--pdf-accent);
            border-bottom: 1px solid var(--pdf-border);
            padding-bottom: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .pdf-session-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.06);
            font-size: 0.83rem;
        }
        .pdf-session-item:last-child { border-bottom: none; }
        .pdf-time-range {
            font-weight: 800;
            color: var(--pdf-accent-gold);
            font-family: 'Space Grotesk', monospace;
            font-size: 0.85rem;
        }

        /* Movie-First View Cards */
        .pdf-movie-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--pdf-border);
            border-radius: 10px;
            padding: 14px;
            margin: 14px;
        }
        .movie-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            border-bottom: 1px solid var(--pdf-border);
            padding-bottom: 6px;
        }
        .movie-card-title { font-weight: 800; font-size: 1.05rem; color: #ffffff; }
        .movie-card-runtime { font-size: 0.8rem; color: var(--pdf-text-muted); }

        .pdf-document-footer {
            margin-top: 30px;
            padding-top: 16px;
            border-top: 2px solid var(--pdf-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.78rem;
            color: var(--pdf-text-muted);
        }

        @media print {
            .no-print-bar { display: none !important; }
            body { background: #ffffff !important; color: #000000 !important; }
            .pdf-document-wrapper { box-shadow: none !important; margin: 0 !important; max-width: 100% !important; background: #fff !important; color: #000 !important; }
            .pdf-day-section { page-break-inside: avoid; border-color: #000 !important; background: #fff !important; }
            .day-header-banner { background: #f1f5f9 !important; color: #000 !important; border-bottom: 2pt solid #000 !important; }
            .pdf-aud-card { background: #fff !important; border-color: #cbd5e1 !important; }
            .pdf-time-range { color: #d97706 !important; }
        }
    </style>
</head>
<body class="ink-saver-mode">

    <!-- Web Controls Toolbar -->
    <div class="no-print-bar">
        <h2>📄 Executive Showtime PDF Exporter</h2>

        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <input type="text" id="pdfSearchInput" class="select-custom" placeholder="🔍 Search movie or auditorium..." style="width: 220px;">

            <form method="GET" action="/export-pdf" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <select name="locationId" class="select-custom" onchange="this.form.submit()">
                    <?php foreach ($locations as $name => $id): ?>
                        <option value="<?php echo $id; ?>" <?php echo ((int)$theatreId === (int)$id) ? 'selected' : ''; ?>>
                            <?php echo (in_array($id, $activeLocations) ? '🟢 ' : '⚪ ') . htmlspecialchars($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="start_date" class="select-custom" onchange="this.form.submit()">
                    <option value="<?php echo $startFridayStr; ?>" selected>Theatrical Week: <?php echo date('M j', $startFridaySec); ?> - <?php echo date('M j', strtotime('+6 days', $startFridaySec)); ?></option>
                    <option value="<?php echo $prevWeekStart; ?>">Previous Week (<?php echo date('M j', strtotime($prevWeekStart)); ?>)</option>
                    <option value="<?php echo $nextWeekStart; ?>">Next Week (<?php echo date('M j', strtotime($nextWeekStart)); ?>)</option>
                </select>
            </form>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button id="btnToggleAudView" class="btn-action btn-action-secondary active" type="button">🍿 Auditorium View</button>
            <button id="btnToggleMovieView" class="btn-action btn-action-secondary" type="button">🎬 Movie View</button>
            <button id="btnToggleTimeline" class="btn-action btn-action-secondary active" type="button">📊 Timeline Grid</button>
            
            <button id="downloadPdfBtn" class="btn-action" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);" type="button">
                📥 Download PDF File
            </button>
            <button id="toggleInkSaverBtn" class="btn-action btn-action-secondary" type="button">🌓 Theme</button>
            <button onclick="window.print()" class="btn-action btn-action-secondary" type="button">🖨️ Print</button>
        </div>
    </div>

    <!-- Main Printable PDF Container -->
    <div class="pdf-container pdf-document-wrapper">
        
        <!-- Header Banner -->
        <div class="pdf-cover-header">
            <div class="brand-section">
                <h1>🎬 CINEPULSE <span class="gold">EXECUTIVE SCHEDULE</span></h1>
                <p>📍 <?php echo htmlspecialchars($theatreName); ?></p>
            </div>
            <div class="metadata-badge-group">
                <div class="date-badge">
                    🗓️ <?php echo date('F j', $startFridaySec); ?> – <?php echo date('F j, Y', strtotime('+6 days', $startFridaySec)); ?>
                </div>
                <div style="font-size: 0.8rem; color: var(--pdf-text-muted); margin-top: 4px;">
                    Generated: <strong><?php echo date('Y-m-d H:i:s'); ?> EST</strong> | Venue ID: <code>#<?php echo $theatreId; ?></code>
                </div>
            </div>
        </div>

        <!-- Metrics Summary Strip -->
        <div class="metrics-summary-strip">
            <div class="summary-stat-item">
                <div class="summary-stat-label">Total Showtimes</div>
                <div class="summary-stat-value"><?php echo number_format($totalShowtimeCount); ?></div>
            </div>
            <div class="summary-stat-item">
                <div class="summary-stat-label">Active Auditoriums</div>
                <div class="summary-stat-value"><?php echo count($uniqueAuditoriums); ?></div>
            </div>
            <div class="summary-stat-item">
                <div class="summary-stat-label">Movies Catalog</div>
                <div class="summary-stat-value"><?php echo number_format($totalMoviesCount); ?></div>
            </div>
            <div class="summary-stat-item">
                <div class="summary-stat-label">Formats Offered</div>
                <div class="summary-stat-value"><?php echo count($uniqueExperiences); ?></div>
            </div>
        </div>

        <!-- 7-Day Schedule Listings -->
        <?php foreach ($weekDays as $index => $day): ?>
            <div class="pdf-day-section">
                
                <!-- Day Header Banner -->
                <div class="day-header-banner">
                    <span>📅 <?php echo htmlspecialchars($day['label']); ?></span>
                    <span class="day-showtime-counter">
                        🏛️ <?php echo count($day['auditorium_matrix']); ?> Auditoriums Active &bull; 🎬 <?php echo count($day['all_sessions']); ?> Showtimes Scheduled
                    </span>
                </div>

                <?php if (empty($day['all_sessions'])): ?>
                    <div style="padding: 1.5rem; font-style: italic; color: var(--pdf-text-muted); text-align: center;">
                        No pre-cached showtimes scheduled for this date.
                    </div>
                <?php else: ?>

                    <!-- VISUAL AUDITORIUM ROOM TIMELINE GRAPH GRID -->
                    <div class="pdf-timeline-container pdf-timeline-panel">
                        <div style="font-size: 0.78rem; font-weight: 700; color: var(--pdf-accent); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">
                            📊 Auditorium Room Timeline Graph (10:00 AM – 3:00 AM)
                        </div>

                        <?php
                        $dayStartSec = strtotime($day['date'] . ' 10:00:00');
                        $dayTotalMins = 17 * 60; // 17 hours timeline window (10am to 3am)
                        ?>
                        
                        <!-- Hour Ticks -->
                        <div class="pdf-timeline-header-ticks">
                            <?php for ($h = 0; $h <= 17; $h += 2): 
                                $tickSec = $dayStartSec + ($h * 3600);
                                $tickLeft = ($h / 17) * 100;
                            ?>
                                <div class="pdf-tick" style="left: <?php echo $tickLeft; ?>%;">
                                    <?php echo date('gA', $tickSec); ?>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <!-- Rows per Auditorium -->
                        <?php foreach ($day['auditorium_matrix'] as $audName => $sessions): ?>
                            <div class="pdf-aud-row">
                                <div class="pdf-aud-label" title="<?php echo htmlspecialchars($audName); ?>">
                                    🏛️ <?php echo htmlspecialchars($audName); ?>
                                </div>
                                <div class="pdf-aud-track">
                                    <?php foreach ($sessions as $sess): ?>
                                        <?php
                                        $offsetMins = ($sess['start'] - $dayStartSec) / 60;
                                        $leftPct = ($offsetMins / $dayTotalMins) * 100;
                                        $widthPct = ($sess['runtime'] / $dayTotalMins) * 100;
                                        if ($leftPct < 0) { $widthPct += $leftPct; $leftPct = 0; }
                                        if ($widthPct < 2) $widthPct = 2;
                                        if (($leftPct + $widthPct) > 100) { $widthPct = 100 - $leftPct; }
                                        $barColor = getMovieColorPdf($sess['movie']);
                                        ?>
                                        <div class="pdf-show-bar" 
                                             style="left: <?php echo $leftPct; ?>%; width: <?php echo $widthPct; ?>%; background: <?php echo $barColor; ?>;"
                                             title="<?php echo htmlspecialchars($sess['movie']); ?> (<?php echo $sess['time_range']; ?>)">
                                            <span><?php echo $sess['start_formatted']; ?> &bull; <?php echo htmlspecialchars($sess['movie']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- AUDITORIUM-FIRST DETAILED SCHEDULE VIEW -->
                    <div class="pdf-aud-grid view-aud-panel">
                        <?php foreach ($day['auditorium_matrix'] as $audName => $sessions): ?>
                            <div class="pdf-aud-card searchable-movie-item" data-title="<?php echo htmlspecialchars(strtolower($audName . ' ' . implode(' ', array_column($sessions, 'movie')))); ?>">
                                <div class="pdf-aud-card-header">
                                    <span>🏛️ <?php echo htmlspecialchars($audName); ?></span>
                                    <span style="font-size: 0.75rem; color: var(--pdf-text-muted); font-weight: 600;"><?php echo count($sessions); ?> shows</span>
                                </div>

                                <?php foreach ($sessions as $sess): ?>
                                    <div class="pdf-session-item">
                                        <div>
                                            <strong style="color: #ffffff; display: block; font-size: 0.88rem;"><?php echo htmlspecialchars($sess['movie']); ?></strong>
                                            <span style="font-size: 0.72rem; color: var(--pdf-text-muted);">
                                                ⏱️ <?php echo $sess['runtime']; ?> min &bull; 
                                                <span style="color: #60a5fa; font-weight: 600;"><?php echo htmlspecialchars($sess['experience']); ?></span>
                                            </span>
                                        </div>
                                        <div class="pdf-time-range">
                                            <?php echo $sess['time_range']; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- MOVIE-FIRST DETAILED SCHEDULE VIEW (Toggleable) -->
                    <div class="view-movie-panel" style="display: none; padding: 14px;">
                        <?php foreach ($day['movies'] as $movie): ?>
                            <div class="pdf-movie-card searchable-movie-item" data-title="<?php echo htmlspecialchars(strtolower($movie['title'])); ?>">
                                <div class="movie-card-header">
                                    <span class="movie-card-title">🎬 <?php echo htmlspecialchars($movie['title']); ?></span>
                                    <span class="movie-card-runtime">⏱️ <?php echo $movie['runtime']; ?> mins</span>
                                </div>

                                <?php foreach ($movie['formats'] as $format): ?>
                                    <div style="margin-bottom: 10px;">
                                        <div style="font-size: 0.8rem; font-weight: 700; color: #60a5fa; margin-bottom: 4px;">
                                            ✨ <?php echo htmlspecialchars($format['experience']); ?> (<?php echo htmlspecialchars($format['auditorium']); ?>)
                                        </div>
                                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                            <?php foreach ($format['times'] as $t): ?>
                                                <span style="background: rgba(255,255,255,0.06); border: 1px solid var(--pdf-border); padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.8rem; color: var(--pdf-accent-gold); font-family: monospace;">
                                                    <?php echo $t['time_range']; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <!-- Footer -->
        <div class="pdf-document-footer">
            <span>🎬 Cinepulse Executive Showtime Exporter & Analytics</span>
            <span>https://cinepluse.msharmarke.com/</span>
            <span>Confidential & Internal Use</span>
        </div>

    </div>

    <script>
        // Live Search Filter
        $('#pdfSearchInput').on('keyup input', function() {
            const query = $(this).val().toLowerCase().trim();
            $('.searchable-movie-item').each(function() {
                const title = $(this).data('title') || '';
                if (!query || title.indexOf(query) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // View Mode Switchers
        $('#btnToggleAudView').on('click', function() {
            $('.btn-action-secondary').removeClass('active');
            $(this).addClass('active');
            $('.view-aud-panel').show();
            $('.view-movie-panel').hide();
        });

        $('#btnToggleMovieView').on('click', function() {
            $('.btn-action-secondary').removeClass('active');
            $(this).addClass('active');
            $('.view-aud-panel').hide();
            $('.view-movie-panel').show();
        });

        $('#btnToggleTimeline').on('click', function() {
            $(this).toggleClass('active');
            $('.pdf-timeline-panel').slideToggle(200);
        });

        // Toggle Ink Saver vs Dark Mode
        $('#toggleInkSaverBtn').on('click', function() {
            $('body').toggleClass('ink-saver-mode');
        });

        // Programmatic PDF Download
        $('#downloadPdfBtn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();
            $btn.prop('disabled', true).html('⌛ Rendering PDF...');

            var element = document.querySelector('.pdf-document-wrapper');
            var filename = 'Cinepulse_Executive_Schedule_' + <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $theatreName)); ?> + '_' + <?php echo json_encode($startFridayStr); ?> + '.pdf';

            var opt = {
                margin:       [6, 6, 6, 6],
                filename:     filename,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
            };

            html2pdf().set(opt).from(element).save().then(function() {
                $btn.prop('disabled', false).html(originalText);
            }).catch(function(err) {
                console.error(err);
                alert('Failed to generate PDF file download.');
                $btn.prop('disabled', false).html(originalText);
            });
        });
    </script>
    <script src="/assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
</body>
</html>
