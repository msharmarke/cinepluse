<?php
/**
 * Cinepulse — Per-Theater Weekly Schedule PDF Exporter
 * Generates a clean, publication-ready printable schedule for a specific theater across a theatrical week (Friday - Thursday).
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

Security::startSession();

// Fetch theater locations map
$locations = [];
$locFile = dirname(__DIR__) . '/config/locations.json';
if (file_exists($locFile)) {
    $locations = json_decode(file_get_contents($locFile), true) ?: [];
}

// Get requested theater ID (default: 7411 - Brampton / first available)
$theatreId = Security::sanitizeInput($_GET['locationId'] ?? $_GET['location_id'] ?? 7411, 'int');

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
        // Default to upcoming Friday for new week pre-cached schedules
        $startFridaySec = strtotime('next Friday', $todaySec);
    }
}

$startFridayStr = date('Y-m-d', $startFridaySec);
$endThursdayStr = date('Y-m-d', strtotime('+6 days', $startFridaySec));

// Fetch showtimes for all 7 days of the week
$api = new CineplexAPI();
$weekDays = [];
$totalShowtimeCount = 0;

for ($i = 0; $i < 7; $i++) {
    $currentSec = strtotime("+{$i} days", $startFridaySec);
    $currentDate = date('Y-m-d', $currentSec);
    $cineplexDate = date('m+d+Y', $currentSec);
    $dayLabel = date('l, F j, Y', $currentSec);
    $dayName = date('l', $currentSec);

    $showtimesData = [];
    try {
        $raw = $api->fetchShowtimes($theatreId, $cineplexDate);
        if (!isset($raw['error'])) {
            $showtimesData = $raw[0]['dates'][0]['movies'] ?? [];
        }
    } catch (Exception $e) {
        $showtimesData = [];
    }

    // Group showtimes for this day by Movie
    $moviesList = [];
    foreach ($showtimesData as $movie) {
        $movieTitle = $movie['name'] ?? $movie['title'] ?? 'Unknown Title';
        $runtime = (int)($movie['runtimeInMinutes'] ?? $movie['runtime'] ?? $movie['duration'] ?? 120);

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
                
                if (!empty($exp['sessions'])) {
                    foreach ($exp['sessions'] as $session) {
                        $aud = $session['auditorium'] ?? $session['auditoriumName'] ?? 'Auditorium';
                        $startTime = strtotime($session['showStartDateTime']);
                        $startFormatted = date('g:i A', $startTime);

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
                            'timestamp' => $startTime,
                            'session_id' => $session['vistaSessionId'] ?? ''
                        ];
                        $totalShowtimeCount++;
                    }
                }
            }
        }
    }

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
        'movies' => $moviesList
    ];
}

$prevWeekStart = date('Y-m-d', strtotime('-7 days', $startFridaySec));
$nextWeekStart = date('Y-m-d', strtotime('+7 days', $startFridaySec));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎬 Weekly Showtimes Schedule — <?php echo htmlspecialchars($theatreName); ?> (<?php echo date('M j', $startFridaySec); ?> - <?php echo date('M j, Y', strtotime('+6 days', $startFridaySec)); ?>)</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/design-options-modal.css">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            margin: 0;
            padding: 0;
            font-size: 14px;
            line-height: 1.4;
        }

        /* Top Interactive Controls (Hidden on Print) */
        .no-print-bar {
            background: #1e293b;
            border-bottom: 1px solid #334155;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .no-print-bar h2 {
            margin: 0;
            font-size: 1.1rem;
            color: #38bdf8;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-action {
            background: #e50914;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .btn-action:hover {
            background: #b91c1c;
        }
        .btn-action-secondary {
            background: #334155;
            color: #f8fafc;
        }
        .btn-action-secondary:hover {
            background: #475569;
        }
        .select-custom {
            background: #0f172a;
            color: #f8fafc;
            border: 1px solid #475569;
            padding: 7px 12px;
            border-radius: 6px;
            font-family: inherit;
            font-size: 0.88rem;
        }

        /* Printable Document Container */
        .pdf-container {
            max-width: 1050px;
            margin: 24px auto;
            background: #ffffff;
            color: #0f172a;
            padding: 36px 40px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }

        /* Document Header */
        .pdf-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid #0f172a;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .pdf-brand h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        .pdf-brand p {
            margin: 4px 0 0 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #475569;
        }
        .pdf-meta {
            text-align: right;
        }
        .pdf-week-badge {
            background: #0f172a;
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-block;
            margin-bottom: 6px;
        }
        .pdf-meta-sub {
            font-size: 0.82rem;
            color: #64748b;
        }

        /* Daily Schedule Cards */
        .day-block {
            margin-bottom: 28px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .day-header {
            background: #f1f5f9;
            border-left: 5px solid #e50914;
            padding: 8px 14px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
            border-radius: 0 6px 6px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .day-header-count {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
        }

        /* Movie Row Layout */
        .movie-card {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin-bottom: 10px;
            padding: 10px 14px;
            background: #ffffff;
        }
        .movie-header-line {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 8px;
        }
        .movie-title {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
        }
        .movie-runtime {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 500;
        }
        .format-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dashed #f1f5f9;
            flex-wrap: wrap;
        }
        .format-label {
            font-size: 0.78rem;
            font-weight: 700;
            background: #e2e8f0;
            color: #334155;
            padding: 3px 8px;
            border-radius: 4px;
            white-space: nowrap;
        }
        .showtimes-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .time-pill {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.85rem;
            font-family: monospace;
        }

        /* Footer */
        .pdf-footer {
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 0.78rem;
            color: #94a3b8;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .no-print-bar {
                display: none !important;
            }
            .pdf-container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            .day-block {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            @page {
                size: letter portrait;
                margin: 0.4in;
            }
        }
    </style>
</head>
<body>

    <!-- Web Navigation & Trigger Bar (Hidden on Print) -->
    <div class="no-print-bar">
        <h2>📄 Weekly Schedule PDF Exporter</h2>

        <form method="GET" action="export-pdf" style="display: flex; gap: 10px; align-items: center;">
            <select name="locationId" class="select-custom" onchange="this.form.submit()">
                <?php foreach ($locations as $name => $id): ?>
                    <option value="<?php echo $id; ?>" <?php echo ((int)$theatreId === (int)$id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="start_date" class="select-custom" onchange="this.form.submit()">
                <option value="<?php echo $startFridayStr; ?>" selected>Theatrical Week: <?php echo date('M j', $startFridaySec); ?> - <?php echo date('M j', strtotime('+6 days', $startFridaySec)); ?></option>
                <option value="<?php echo $prevWeekStart; ?>">Previous Week (<?php echo date('M j', strtotime($prevWeekStart)); ?>)</option>
                <option value="<?php echo $nextWeekStart; ?>">Next Week (<?php echo date('M j', strtotime($nextWeekStart)); ?>)</option>
            </select>
        </form>

        <div style="display: flex; gap: 10px;">
            <button onclick="window.print()" class="btn-action">🖨️ Print / Save as PDF</button>
            <a href="dashboard" class="btn-action btn-action-secondary">📊 Back to Dashboard</a>
        </div>
    </div>

    <!-- Main PDF Layout Document -->
    <div class="pdf-container">
        
        <!-- Header -->
        <div class="pdf-header">
            <div class="pdf-brand">
                <h1>🎬 CINEPULSE THEATER SCHEDULE</h1>
                <p>📍 <?php echo htmlspecialchars($theatreName); ?></p>
            </div>
            <div class="pdf-meta">
                <div class="pdf-week-badge">
                    🗓️ <?php echo date('F j', $startFridaySec); ?> – <?php echo date('F j, Y', strtotime('+6 days', $startFridaySec)); ?>
                </div>
                <div class="pdf-meta-sub">
                    Total Weekly Showtimes: <strong><?php echo number_format($totalShowtimeCount); ?></strong> | Generated: <?php echo date('Y-m-d H:i'); ?>
                </div>
            </div>
        </div>

        <!-- Days Schedule List -->
        <?php foreach ($weekDays as $day): ?>
            <div class="day-block">
                <div class="day-header">
                    <span>📅 <?php echo htmlspecialchars($day['label']); ?></span>
                    <span class="day-header-count">
                        <?php 
                        $dayTotal = 0;
                        foreach ($day['movies'] as $m) {
                            foreach ($m['formats'] as $f) {
                                $dayTotal += count($f['times']);
                            }
                        }
                        echo $dayTotal . " showtimes";
                        ?>
                    </span>
                </div>

                <?php if (empty($day['movies'])): ?>
                    <div style="padding: 10px 14px; font-style: italic; color: #94a3b8; border: 1px dashed #cbd5e1; border-radius: 6px;">
                        No showtimes scheduled or cached for this date.
                    </div>
                <?php else: ?>
                    <?php foreach ($day['movies'] as $movie): ?>
                        <div class="movie-card">
                            <div class="movie-header-line">
                                <span class="movie-title">🎬 <?php echo htmlspecialchars($movie['title']); ?></span>
                                <span class="movie-runtime">⏱️ <?php echo $movie['runtime']; ?> mins</span>
                            </div>

                            <?php foreach ($movie['formats'] as $format): ?>
                                <div class="format-row">
                                    <span class="format-label">✨ <?php echo htmlspecialchars($format['experience']); ?> (<?php echo htmlspecialchars($format['auditorium']); ?>)</span>
                                    <div class="showtimes-pills">
                                        <?php foreach ($format['times'] as $t): ?>
                                            <span class="time-pill"><?php echo $t['time']; ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <!-- Document Footer -->
        <div class="pdf-footer">
            <span>Cinepulse Modern Showtime Exporter & System Analytics</span>
            <span>https://cinepluse.msharmarke.com/</span>
            <span>Page 1 of 1</span>
        </div>

    </div>

    <script>
        // Trigger print dialog after document is fully loaded if ?print=1 is passed
        window.addEventListener('load', function() {
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('print') === '1') {
                setTimeout(function() {
                    window.print();
                }, 400);
            }
        });
    </script>
    <script src="assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
</body>
</html>
