<?php
/**
 * Cinepulse — Publication-Ready Executive Weekly PDF & Print Exporter
 * Generates an executive, per-theater weekly schedule report formatted for A4/Letter PDF saving with page-break protection.
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
        // Default to upcoming Friday for new week pre-cached schedules
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

// Fetch showtimes for all 7 days of the week
$api = new CineplexAPI();
$weekDays = [];
$totalShowtimeCount = 0;
$totalMoviesCount = 0;
$uniqueExperiences = [];
$allMovieTitlesMap = [];

for ($i = 0; $i < 7; $i++) {
    $currentSec = strtotime("+{$i} days", $startFridaySec);
    $currentDate = date('Y-m-d', $currentSec);
    $cineplexDate = date('m+d+Y', $currentSec);
    $dayLabel = date('l, F j, Y', $currentSec);
    $dayName = date('l', $currentSec);

    $moviesList = [];

    if (!empty($cachedDbShowtimes[$currentDate])) {
        // Build from database cache
        foreach ($cachedDbShowtimes[$currentDate] as $row) {
            $movieTitle = $row['movie_name'] ?? 'Unknown Title';
            $allMovieTitlesMap[$movieTitle] = true;
            $expName = $row['experience_type'] ?? 'Standard';
            $aud = $row['auditorium_name'] ?? 'Auditorium';
            $uniqueExperiences[$expName] = true;

            $startTime = strtotime($currentDate . ' ' . ($row['show_start_time'] ?? '12:00:00'));
            $startFormatted = date('g:i A', $startTime);

            if (!isset($moviesList[$movieTitle])) {
                $moviesList[$movieTitle] = [
                    'title' => $movieTitle,
                    'runtime' => (int)($row['runtime_minutes'] ?? 120),
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
    
    <!-- Open Graph & Social Meta -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="📄 Cinepulse — Executive PDF Report Exporter">
    <meta property="og:description" content="Generate and download publication-ready weekly cinema showtime schedules and PDF reports.">
    <meta property="og:image" content="/assets/images/share/executive.jpg">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="📄 Cinepulse — Executive PDF Report Exporter">
    <meta name="twitter:description" content="Generate and download publication-ready weekly cinema showtime schedules and PDF reports.">
    <meta name="twitter:image" content="/assets/images/share/executive.jpg">
    
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
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* Top Interactive Controls (Hidden on Print) */
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
            -webkit-backdrop-filter: blur(12px);
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
            padding: 9px 18px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(229, 9, 20, 0.45);
        }

        .btn-action-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: none;
        }

        .btn-action-secondary:hover {
            background: rgba(255, 255, 255, 0.18);
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

        /* Main Document Wrapper */
        .pdf-document-wrapper {
            max-width: 1080px;
            margin: 30px auto;
            background: var(--pdf-card-bg);
            color: var(--pdf-text);
            padding: 40px 48px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--pdf-border);
            position: relative;
        }

        /* Cover & Header */
        .pdf-cover-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--pdf-border);
            padding-bottom: 24px;
            margin-bottom: 30px;
            gap: 20px;
        }

        .brand-section h1 {
            font-size: 2rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.02em;
            color: var(--pdf-text);
            font-family: 'Space Grotesk', sans-serif;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-section h1 span.gold {
            color: var(--pdf-accent-gold);
        }

        .brand-section p {
            margin: 6px 0 0 0;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--pdf-accent);
        }

        .metadata-badge-group {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        .date-badge {
            background: var(--pdf-accent);
            color: #ffffff;
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 800;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .sub-meta-text {
            font-size: 0.83rem;
            color: var(--pdf-text-muted);
            font-weight: 500;
        }

        /* Executive Metrics Strip */
        .metrics-summary-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 32px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--pdf-border);
            padding: 16px;
            border-radius: 12px;
        }

        .summary-stat-item {
            display: flex;
            flex-direction: column;
        }

        .summary-stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--pdf-text-muted);
            font-weight: 700;
        }

        .summary-stat-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--pdf-accent-gold);
            margin-top: 2px;
        }

        /* Day Block Section Page-Break Protection */
        .pdf-day-section {
            margin-bottom: 36px;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .pdf-day-section.force-page-break {
            page-break-before: always;
            break-before: page;
        }

        .day-header-banner {
            background: rgba(255, 255, 255, 0.05);
            border-left: 6px solid var(--pdf-accent-gold);
            padding: 10px 18px;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--pdf-text);
            margin-bottom: 16px;
            border-radius: 0 8px 8px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--pdf-border);
            border-right: 1px solid var(--pdf-border);
            border-bottom: 1px solid var(--pdf-border);
        }

        .day-showtime-counter {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--pdf-text-muted);
            background: var(--pdf-bg);
            padding: 4px 10px;
            border-radius: 14px;
        }

        /* Movie Card Page-Break Protection */
        .pdf-movie-card {
            background: var(--pdf-card-bg);
            border: 1px solid var(--pdf-border);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 14px;
            page-break-inside: avoid;
            break-inside: avoid;
            transition: border-color 0.2s;
        }

        .pdf-movie-card:hover {
            border-color: var(--pdf-accent);
        }

        .movie-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            border-bottom: 1px dashed var(--pdf-border);
            padding-bottom: 8px;
        }

        .movie-card-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--pdf-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .movie-card-runtime {
            font-size: 0.83rem;
            font-weight: 700;
            color: var(--pdf-text-muted);
            background: rgba(255, 255, 255, 0.06);
            padding: 3px 8px;
            border-radius: 6px;
        }

        .experience-format-block {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 8px;
            padding-top: 4px;
            flex-wrap: wrap;
        }

        .experience-badge {
            font-size: 0.78rem;
            font-weight: 800;
            background: var(--pdf-accent);
            color: #ffffff;
            padding: 4px 10px;
            border-radius: 6px;
            white-space: nowrap;
            letter-spacing: 0.02em;
        }

        .showtime-pills-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .pdf-time-pill {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--pdf-border);
            color: var(--pdf-text);
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 800;
            font-size: 0.88rem;
            font-family: 'Space Grotesk', monospace;
            white-space: nowrap;
        }

        /* Document Footer */
        .pdf-document-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid var(--pdf-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: var(--pdf-text-muted);
        }

        /* ============================================
           EXPLICIT PRINT & PDF SAVING STYLES (@media print)
           ============================================ */
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 15mm 15mm 15mm;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 12pt !important;
            }

            .no-print-bar {
                display: none !important;
            }

            .pdf-document-wrapper {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                border: none !important;
                background: #ffffff !important;
                color: #000000 !important;
            }

            .pdf-cover-header {
                border-bottom: 2pt solid #000000 !important;
            }

            .brand-section h1 {
                color: #000000 !important;
            }

            .brand-section p {
                color: #000000 !important;
            }

            .date-badge {
                background: #000000 !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .metrics-summary-strip {
                background: #f8fafc !important;
                border: 1pt solid #cbd5e1 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-stat-value {
                color: #000000 !important;
            }

            /* Explicit Page Break Protection Rules */
            .pdf-day-section {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                margin-bottom: 18pt !important;
            }

            .pdf-movie-card {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                border: 1pt solid #94a3b8 !important;
                background: #ffffff !important;
                margin-bottom: 10pt !important;
            }

            .movie-card-title {
                color: #000000 !important;
            }

            .experience-badge {
                background: #000000 !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .pdf-time-pill {
                border: 1pt solid #000000 !important;
                background: #f1f5f9 !important;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .day-header-banner {
                background: #f1f5f9 !important;
                border-left: 6pt solid #000000 !important;
                border-top: 1pt solid #cbd5e1 !important;
                border-right: 1pt solid #cbd5e1 !important;
                border-bottom: 1pt solid #cbd5e1 !important;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .pdf-document-footer {
                border-top: 1pt solid #000000 !important;
                color: #475569 !important;
            }
        }
    </style>
</head>
<body class="ink-saver-mode">

    <!-- Web Control Deck (Hidden when printing or saving PDF) -->
    <div class="no-print-bar">
        <h2>📄 Executive Weekly PDF Exporter</h2>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="text" id="pdfSearchInput" class="select-custom" placeholder="🔍 Filter by movie title..." style="width: 220px;">

            <form method="GET" action="/export-pdf" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
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
                
                <input type="hidden" name="one_per_page" value="<?php echo $oneDayPerPage ? '0' : '1'; ?>">
                <button type="submit" class="btn-action btn-action-secondary" style="font-size: 0.82rem;">
                    📄 <?php echo $oneDayPerPage ? 'Continuous Flow' : '1 Day Per Page'; ?>
                </button>
            </form>
        </div>

        <div style="display: flex; gap: 10px;">
            <button id="downloadPdfBtn" class="btn-action" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);" type="button">
                📥 Download PDF File
            </button>
            <button id="toggleInkSaverBtn" class="btn-action btn-action-secondary" type="button">
                🌓 Toggle Theme
            </button>
            <button onclick="window.print()" class="btn-action btn-action-secondary" type="button">
                🖨️ Print
            </button>
            <a href="/admin/dashboard" class="btn-action btn-action-secondary">
                📊 Dashboard
            </a>
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
                <div class="sub-meta-text">
                    Generated: <strong><?php echo date('Y-m-d H:i:s'); ?> EST</strong> | Location ID: <code><?php echo $theatreId; ?></code>
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
                <div class="summary-stat-label">Movies Playing</div>
                <div class="summary-stat-value"><?php echo number_format($totalMoviesCount); ?></div>
            </div>
            <div class="summary-stat-item">
                <div class="summary-stat-label">Formats Offered</div>
                <div class="summary-stat-value"><?php echo count($uniqueExperiences); ?></div>
            </div>
            <div class="summary-stat-item">
                <div class="summary-stat-label">Theatrical Week</div>
                <div class="summary-stat-value" style="font-size: 1.05rem;"><?php echo date('M j', $startFridaySec); ?> - <?php echo date('M j', strtotime('+6 days', $startFridaySec)); ?></div>
            </div>
        </div>

        <!-- 7-Day Schedule Listings -->
        <?php foreach ($weekDays as $index => $day): ?>
            <div class="pdf-day-section <?php echo ($oneDayPerPage && $index > 0) ? 'force-page-break' : ''; ?>">
                <div class="day-header-banner">
                    <span>📅 <?php echo htmlspecialchars($day['label']); ?></span>
                    <span class="day-showtime-counter">
                        <?php 
                        $dayTotal = 0;
                        foreach ($day['movies'] as $m) {
                            foreach ($m['formats'] as $f) {
                                $dayTotal += count($f['times']);
                            }
                        }
                        echo $dayTotal . " showtimes scheduled";
                        ?>
                    </span>
                </div>

                <?php if (empty($day['movies'])): ?>
                    <div style="padding: 14px 18px; font-style: italic; color: var(--pdf-text-muted); border: 1px dashed var(--pdf-border); border-radius: 8px;">
                        No cached showtimes available for this date.
                    </div>
                <?php else: ?>
                    <?php foreach ($day['movies'] as $movie): ?>
                        <div class="pdf-movie-card searchable-movie-item" data-title="<?php echo htmlspecialchars(strtolower($movie['title'])); ?>">
                            <div class="movie-card-header">
                                <span class="movie-card-title">🎬 <?php echo htmlspecialchars($movie['title']); ?></span>
                                <span class="movie-card-runtime">⏱️ <?php echo $movie['runtime']; ?> mins</span>
                            </div>

                            <?php foreach ($movie['formats'] as $format): ?>
                                <div class="experience-format-block">
                                    <span class="experience-badge">✨ <?php echo htmlspecialchars($format['experience']); ?> (<?php echo htmlspecialchars($format['auditorium']); ?>)</span>
                                    <div class="showtime-pills-row">
                                        <?php foreach ($format['times'] as $t): ?>
                                            <span class="pdf-time-pill"><?php echo $t['time']; ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <!-- Publication Footer -->
        <div class="pdf-document-footer">
            <span>🎬 Cinepulse Executive Showtime Exporter & Analytics</span>
            <span>https://cinepluse.msharmarke.com/</span>
            <span>Confidential & Internal Use</span>
        </div>

    </div>

    <script>
        // Live Movie Search Filter
        $('#pdfSearchInput').on('keyup input', function() {
            const query = $(this).val().toLowerCase().trim();
            $('.searchable-movie-item').each(function() {
                const title = $(this).data('title');
                if (!query || title.indexOf(query) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Toggle Ink Saver vs Executive Dark Mode
        $('#toggleInkSaverBtn').on('click', function() {
            $('body').toggleClass('ink-saver-mode');
        });

        // Download PDF Programmatically using html2pdf.js
        $('#downloadPdfBtn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();
            $btn.prop('disabled', true).html('⌛ Rendering PDF...');

            var element = document.querySelector('.pdf-document-wrapper');
            var filename = 'Cinepulse_Weekly_Schedule_' + <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $theatreName)); ?> + '_' + <?php echo json_encode($startFridayStr); ?> + '.pdf';

            var opt = {
                margin:       [8, 8, 8, 8],
                filename:     filename,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, logging: false, backgroundColor: $('body').hasClass('ink-saver-mode') ? '#ffffff' : '#111827' },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
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

        // Trigger PDF download automatically if ?download=1 or ?print=1 is passed
        window.addEventListener('load', function() {
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('download') === '1') {
                setTimeout(function() {
                    $('#downloadPdfBtn').trigger('click');
                }, 500);
            }
        });
    </script>
    <script src="/assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
</body>
</html>
