<?php
/**
 * Cinepulse — Consolidated Screen Timeline PDF & Print Exporter
 * Generates an executive full-page Consolidated Screen Timeline grid for each day of the theatrical week.
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

    // Sort auditorium matrix naturally
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
    <title>📄 Consolidated Screen Timeline PDF — <?php echo htmlspecialchars($theatreName); ?> (<?php echo date('M j', $startFridaySec); ?> - <?php echo date('M j, Y', strtotime('+6 days', $startFridaySec)); ?>)</title>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --pdf-bg: #090d16;
            --pdf-card-bg: #0f172a;
            --pdf-text: #f8fafc;
            --pdf-text-muted: #94a3b8;
            --pdf-border: #334155;
            --pdf-accent: #38bdf8;
            --pdf-accent-gold: #fbbf24;
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

        /* Clean, Non-Overlapping Header Controls Bar */
        .no-print-bar {
            background: rgba(15, 23, 42, 0.98);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding: 14px 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(12px);
        }

        .toolbar-flex-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            max-width: 1400px;
            margin: 0 auto;
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
            outline: none;
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
            white-space: nowrap;
        }
        .btn-action:hover {
            transform: translateY(-1px);
        }
        .btn-action-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        /* Main PDF Document Container */
        .pdf-document-wrapper {
            max-width: 1280px;
            margin: 30px auto;
            background: var(--pdf-card-bg);
            color: var(--pdf-text);
            padding: 36px 42px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--pdf-border);
        }

        /* PDF Day Sheet Container */
        .pdf-day-sheet {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--pdf-border);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 32px;
            backdrop-filter: blur(12px);
        }

        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--pdf-border);
            padding-bottom: 16px;
            margin-bottom: 20px;
            gap: 16px;
        }

        .sheet-title-group h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--pdf-text);
            font-family: 'Space Grotesk', sans-serif;
            letter-spacing: -0.01em;
        }
        .sheet-subtitle {
            font-size: 0.9rem;
            color: var(--pdf-accent);
            font-weight: 700;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sheet-meta-badges {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }
        .badge-pill {
            background: var(--pdf-accent);
            color: #ffffff;
            padding: 5px 14px;
            border-radius: 20px;
            font-weight: 800;
            font-size: 0.85rem;
        }

        /* CONSOLIDATED SCREEN TIMELINE GRID */
        .timeline-grid-card {
            border: 1px solid var(--pdf-border);
            border-radius: 12px;
            padding: 16px;
            background: rgba(0, 0, 0, 0.3);
            overflow-x: auto;
        }

        .timeline-ticks-header {
            display: flex;
            align-items: center;
            border-bottom: 2px solid var(--pdf-border);
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .aud-col-header {
            width: 140px;
            font-size: 0.72rem;
            font-weight: 800;
            color: var(--pdf-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .ticks-track-container {
            flex: 1;
            position: relative;
            height: 22px;
        }

        .tick-mark-item {
            position: absolute;
            font-size: 0.68rem;
            font-weight: 800;
            color: var(--pdf-text-muted);
            transform: translateX(-50%);
            font-family: 'Space Grotesk', monospace;
        }

        /* Auditorium Row */
        .timeline-screen-row {
            display: flex;
            align-items: center;
            height: 40px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            margin-bottom: 4px;
        }

        .aud-label-col {
            width: 140px;
            font-weight: 700;
            font-size: 0.83rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 12px;
            color: var(--pdf-text);
        }

        .aud-track-col {
            flex: 1;
            height: 32px;
            position: relative;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            overflow: hidden;
        }

        .timeline-pill-capsule {
            position: absolute;
            height: 100%;
            top: 0;
            border-radius: 6px;
            display: flex;
            align-items: center;
            padding: 0 8px;
            color: #ffffff;
            font-size: 0.7rem;
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(0,0,0,0.35);
            border: 1px solid rgba(255,255,255,0.25);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pill-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.7);
        }

        /* Detailed Session Cards below timeline */
        .pdf-session-breakdown-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px;
            margin-top: 20px;
        }

        .pdf-aud-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--pdf-border);
            border-radius: 10px;
            padding: 14px;
        }

        .pdf-aud-card-header {
            font-weight: 800;
            font-size: 0.92rem;
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
            font-size: 0.82rem;
        }
        .pdf-session-item:last-child { border-bottom: none; }

        .pdf-time-range {
            font-weight: 800;
            color: var(--pdf-accent-gold);
            font-family: 'Space Grotesk', monospace;
            font-size: 0.85rem;
            white-space: nowrap;
        }

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

        /* CSS Page Break for PDF Export */
        .pdf-page-break {
            page-break-before: always !important;
            break-before: page !important;
        }

        @media print {
            .no-print-bar { display: none !important; }
            body { background: #ffffff !important; color: #000000 !important; }
            .pdf-document-wrapper { box-shadow: none !important; margin: 0 !important; max-width: 100% !important; background: #fff !important; color: #000 !important; }
            .pdf-day-sheet { background: #fff !important; border-color: #000 !important; }
            .timeline-grid-card { background: #fff !important; border-color: #cbd5e1 !important; }
            .pdf-aud-card { background: #fff !important; border-color: #cbd5e1 !important; }
            .pdf-time-range { color: #d97706 !important; }
        }
    </style>
</head>
<body class="ink-saver-mode">

    <!-- Clean, Non-Overlapping Header Controls Bar -->
    <div class="no-print-bar">
        <div class="toolbar-flex-container">
            
            <!-- Brand & Venue Form -->
            <div style="display: flex; align-items: center; gap: 0.85rem; flex-wrap: wrap;">
                <h2 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #fff;">📄 Consolidated Screen Timeline Exporter</h2>
                
                <form method="GET" action="/export-pdf" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
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

            <!-- Search Input -->
            <div style="flex: 1; max-width: 240px; min-width: 160px;">
                <input type="text" id="pdfSearchInput" class="select-custom" placeholder="🔍 Search movie or screen..." style="width: 100%;">
            </div>

            <!-- Action Buttons Group -->
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <button id="downloadPdfBtn" class="btn-action" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);" type="button">
                    📥 Download PDF File (.pdf)
                </button>
                <button id="toggleInkSaverBtn" class="btn-action btn-action-secondary" type="button">🌓 Theme</button>
                <button onclick="window.print()" class="btn-action btn-action-secondary" type="button">🖨️ Print</button>
            </div>
        </div>
    </div>

    <!-- Main Printable PDF Container -->
    <div class="pdf-container pdf-document-wrapper">
        
        <!-- Document Cover Header -->
        <div class="pdf-cover-header">
            <div class="brand-section">
                <h1>🎬 CINEPULSE <span class="gold">CONSOLIDATED SCREEN TIMELINE</span></h1>
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

        <!-- Metrics Summary Dashboard Strip -->
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

        <!-- 7-DAY CONSOLIDATED SCREEN TIMELINE PAGES (1 PAGE PER DAY) -->
        <?php foreach ($weekDays as $index => $day): ?>
            
            <div class="pdf-day-sheet <?php echo $index > 0 ? 'pdf-page-break' : ''; ?>">
                
                <!-- Sheet Header -->
                <div class="sheet-header">
                    <div class="sheet-title-group">
                        <h2>📅 <?php echo strtoupper(htmlspecialchars($day['label'])); ?></h2>
                        <div class="sheet-subtitle">
                            <span>📊 Consolidated Screen Timeline</span> &bull; 
                            <span style="color: var(--pdf-text-muted);">10:00 AM – 4:00 AM Next Day</span>
                        </div>
                    </div>

                    <div class="sheet-meta-badges">
                        <span class="badge-pill">
                            🏛️ <?php echo count($day['auditorium_matrix']); ?> Screens Active
                        </span>
                        <div style="font-size: 0.78rem; color: var(--pdf-text-muted); margin-top: 2px;">
                            🎬 <?php echo count($day['all_sessions']); ?> Shows Scheduled
                        </div>
                    </div>
                </div>

                <!-- CONSOLIDATED SCREEN TIMELINE GRID -->
                <div class="timeline-grid-card">
                    <?php
                    $timelineStartSec = strtotime($day['date'] . ' 10:00:00');
                    $timelineTotalMins = 18 * 60; // 18 hours window (10:00 AM to 4:00 AM)
                    ?>

                    <!-- Time Ticks Header (10AM to 4AM) -->
                    <div class="timeline-ticks-header">
                        <div class="aud-col-header">Screen / Aud</div>
                        <div class="ticks-track-container">
                            <?php for ($h = 0; $h <= 18; $h += 1): 
                                $tickSec = $timelineStartSec + ($h * 3600);
                                $tickPct = ($h / 18) * 100;
                            ?>
                                <div class="tick-mark-item" style="left: <?php echo $tickPct; ?>%;">
                                    <?php echo date('gA', $tickSec); ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Screen Rows per Auditorium -->
                    <?php if (empty($day['auditorium_matrix'])): ?>
                        <div style="padding: 2rem; text-align: center; color: var(--pdf-text-muted); font-style: italic;">No pre-cached showtime data available for this date.</div>
                    <?php else: ?>
                        <?php foreach ($day['auditorium_matrix'] as $audName => $sessions): ?>
                            <div class="timeline-screen-row">
                                <div class="aud-label-col" title="<?php echo htmlspecialchars($audName); ?>">
                                    🏛️ <?php echo htmlspecialchars($audName); ?>
                                </div>
                                <div class="aud-track-col">
                                    <?php foreach ($sessions as $sess): ?>
                                        <?php
                                        $offsetMins = ($sess['start'] - $timelineStartSec) / 60;
                                        $leftPct = ($offsetMins / $timelineTotalMins) * 100;
                                        $widthPct = ($sess['runtime'] / $timelineTotalMins) * 100;
                                        if ($leftPct < 0) { $widthPct += $leftPct; $leftPct = 0; }
                                        if ($widthPct < 1.5) $widthPct = 1.5;
                                        if (($leftPct + $widthPct) > 100) { $widthPct = 100 - $leftPct; }
                                        $barColor = getMovieColorPdf($sess['movie']);
                                        ?>
                                        <div class="timeline-pill-capsule"
                                             style="left: <?php echo $leftPct; ?>%; width: <?php echo $widthPct; ?>%; background: <?php echo $barColor; ?>;"
                                             title="<?php echo htmlspecialchars($sess['movie']); ?> (<?php echo $sess['time_range']; ?>)">
                                            <span class="pill-text">
                                                <strong><?php echo $sess['start_formatted']; ?></strong> <?php echo htmlspecialchars($sess['movie']); ?> (<?php echo htmlspecialchars($sess['experience']); ?>)
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Detailed Session Cards below timeline -->
                <div class="pdf-session-breakdown-grid">
                    <?php foreach ($day['auditorium_matrix'] as $audName => $sessions): ?>
                        <div class="pdf-aud-card searchable-movie-item" data-title="<?php echo htmlspecialchars(strtolower($audName . ' ' . implode(' ', array_column($sessions, 'movie')))); ?>">
                            <div class="pdf-aud-card-header">
                                <span>🏛️ <?php echo htmlspecialchars($audName); ?></span>
                                <span style="font-size: 0.75rem; color: var(--pdf-text-muted); font-weight: 600;"><?php echo count($sessions); ?> shows</span>
                            </div>

                            <?php foreach ($sessions as $sess): ?>
                                <div class="pdf-session-item">
                                    <div>
                                        <strong style="color: var(--pdf-text); display: block; font-size: 0.85rem;"><?php echo htmlspecialchars($sess['movie']); ?></strong>
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

            </div>

        <?php endforeach; ?>

        <!-- Footer -->
        <div class="pdf-document-footer">
            <span>🎬 Cinepulse Consolidated Screen Timeline PDF Exporter</span>
            <span>https://cinepluse.msharmarke.com/</span>
            <span>Confidential & Internal Use</span>
        </div>

    </div>

    <script>
        // Search Filter
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

        // Toggle Ink Saver vs Dark Mode
        $('#toggleInkSaverBtn').on('click', function() {
            $('body').toggleClass('ink-saver-mode');
        });

        // Download PDF Handler
        $('#downloadPdfBtn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();
            $btn.prop('disabled', true).html('⌛ Rendering PDF...');

            var element = document.querySelector('.pdf-document-wrapper');
            var filename = 'Cinepulse_Consolidated_Timeline_' + <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $theatreName)); ?> + '_' + <?php echo json_encode($startFridayStr); ?> + '.pdf';

            var opt = {
                margin:       [6, 6, 6, 6],
                filename:     filename,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' },
                pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
            };

            html2pdf().set(opt).from(element).save().then(function() {
                $btn.prop('disabled', false).html(originalText);
            }).catch(function(err) {
                console.error(err);
                alert('Failed to generate PDF download.');
                $btn.prop('disabled', false).html(originalText);
            });
        });
    </script>
    <script src="/assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
</body>
</html>
