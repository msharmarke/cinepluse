<?php
/**
 * Cinepulse — System Monitoring & Analytics Dashboard
 */

require_once dirname(dirname(__DIR__)) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\DashboardService;
use Cinepulse\ArchiveService;

Security::startSession();
Security::requireAdmin();

// Setup DB status
$dbConfigured = true;
$dbError = '';
$dashService = null;
$daemonStatus = [];
$metrics = [];
$analytics = [];
$detailedTheatres = \Cinepulse\ShowtimeService::getDetailedTheatres();
$locations = \Cinepulse\ShowtimeService::getTrackerTheatres(false);
$activeTheatresCount = count(array_filter($detailedTheatres, fn($t) => $t['enabled']));
$disabledTheatresCount = count($detailedTheatres) - $activeTheatresCount;

try {
    $dashService = new DashboardService();
    $dateFrom = Security::sanitizeInput($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')), 'date');
    $dateTo = Security::sanitizeInput($_GET['date_to'] ?? date('Y-m-d'), 'date');
    
    $daemonStatus = $dashService->getDaemonStatus();
    $metrics = $dashService->getSystemMetrics($dateFrom, $dateTo);
    $analytics = $dashService->getAnalyticsData($dateFrom, $dateTo);
    $logs = $dashService->getLogs('track', 50);

    $archiveService = new ArchiveService();
    $archivesList = $archiveService->listArchives();

    // Calculate current theatrical week days (Friday -> Thursday)
    $todaySec = strtotime('today');
    $dayOfWeek = (int)date('N', $todaySec); // 1 = Mon, 5 = Fri, 7 = Sun
    if ($dayOfWeek === 5) {
        $startFridaySec = $todaySec;
    } else if ($dayOfWeek < 5) {
        $startFridaySec = strtotime('last Friday', $todaySec);
    } else {
        $startFridaySec = strtotime('last Friday', $todaySec);
    }

    $weekDays = [];
    for ($i = 0; $i < 7; $i++) {
        $ts = strtotime("+{$i} days", $startFridaySec);
        $weekDays[] = [
            'date' => date('Y-m-d', $ts),
            'day_name' => date('D', $ts),
            'short_date' => date('M j', $ts),
            'is_today' => date('Y-m-d', $ts) === date('Y-m-d')
        ];
    }
} catch (Exception $e) {
    $dbConfigured = false;
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>📊 Admin Dashboard — Cinepulse System Analytics</title>
    
    <!-- Open Graph & Twitter Social Share Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="📊 Cinepulse — Admin Analytics & Monitoring">
    <meta property="og:description" content="Real-time system telemetry, seating occupancy analytics, daemon status, and database archives for Cinepulse.">
    <meta property="og:image" content="/assets/images/share/command-center.jpg">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="📊 Cinepulse — Admin Analytics & Monitoring">
    <meta name="twitter:description" content="Real-time system telemetry, seating occupancy analytics, daemon status, and database archives for Cinepulse.">
    <meta name="twitter:image" content="/assets/images/share/command-center.jpg">
    
    <!-- External Dependencies -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Cinepulse Layout Stylesheets -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="/assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-hover-border: var(--theme-primary, #e50914);
            --card-radius: 14px;
        }

        /* Top Header Action Bar */
        .dashboard-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .header-title-group h1 {
            font-size: 1.85rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .header-title-group p {
            color: var(--text-muted, rgba(255, 255, 255, 0.6));
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }
        .controls-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .date-filter-form {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.4rem 0.75rem;
            border-radius: 10px;
            border: 1px solid var(--glass-border);
        }
        .date-input-custom {
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            background: #14171d !important;
            color: #ffffff !important;
            border: 1px solid var(--glass-border, rgba(255, 255, 255, 0.15));
            font-size: 0.85rem;
            font-family: inherit;
            outline: none;
            cursor: pointer;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .date-input-custom:focus {
            border-color: var(--theme-primary, #e50914);
            box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.25);
        }
        select.date-input-custom option,
        .date-input-custom option,
        select option {
            background-color: #14171d !important;
            color: #ffffff !important;
            padding: 10px 14px !important;
        }

        /* Status Banner */
        .status-banner {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            backdrop-filter: blur(12px);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .status-active { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.4); }
        .status-idle { background: rgba(52, 152, 219, 0.15); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.4); }

        /* Metric Grid Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            padding: 1.35rem;
            backdrop-filter: blur(12px);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            border-color: var(--glass-hover-border);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        .stat-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.1;
        }
        .stat-sub {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 0.4rem;
        }

        /* Charts Layout Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .chart-box {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            padding: 1.5rem;
            backdrop-filter: blur(12px);
            position: relative;
        }
        .chart-wrapper {
            position: relative;
            height: 260px;
            width: 100%;
            overflow: hidden;
        }
        .chart-box-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .chart-box-header h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
        }

        /* Terminal & Exports */
        .bottom-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .log-terminal {
            background: #090c10;
            color: #39d353;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            padding: 1.1rem;
            border-radius: 10px;
            height: 300px;
            overflow-y: auto;
            font-size: 0.83rem;
            line-height: 1.6;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .log-terminal::-webkit-scrollbar {
            width: 6px;
        }
        .log-terminal::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
        }

        /* Buttons */
        .btn-dash {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            border-radius: 9px;
            background: var(--theme-primary, #e50914);
            color: #fff;
            font-weight: 700;
            font-size: 0.88rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .btn-dash:hover {
            transform: translateY(-1px);
            opacity: 0.95;
            box-shadow: 0 4px 15px rgba(229, 9, 20, 0.4);
        }
        .btn-dash-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .btn-dash-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.1);
        }

        /* Archive Table Responsive Styling */
        .table-responsive-wrapper {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--glass-border);
        }
        .archive-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.88rem;
        }
        .archive-table th {
            background: rgba(0, 0, 0, 0.4);
            padding: 0.85rem 1rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.7);
            border-bottom: 1px solid var(--glass-border);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .archive-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }
        .archive-table tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        /* Mobile Responsive Adjustments */
        @media (max-width: 768px) {
            .dashboard-header-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            .controls-group {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
            }
            .date-filter-form {
                width: 100%;
                justify-content: space-between;
            }
            .date-input-custom {
                flex: 1;
                min-width: 0;
            }
            .btn-dash {
                width: 100%;
                justify-content: center;
            }
            .charts-grid, .bottom-grid {
                grid-template-columns: 1fr;
            }
            .stat-value {
                font-size: 1.7rem;
            }
        }
    </style>
</head>
<body>

    <div class="app-container">
        
        <!-- Standard App Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>🎬 Cinepulse</h1>
                <p>Command Center Analytics</p>
            </div>
            <nav class="sidebar-nav">
                <a href="/schedule">📅 Schedule</a>
                <a href="/movies">🎬 Movies</a>
                <a href="/planner">🍿 Planner</a>
                <a href="/admin/tracker">📈 Tracker</a>
                <a href="/admin/dashboard" class="active">📊 Dashboard</a>
                <a href="/admin/scan-logs">🔍 Scan Logs</a>
                <a href="/admin/logout" style="color: #ef4444;">🔒 Logout</a>
            </nav>
            <div style="padding: 1rem 1.5rem; margin-top: auto;">
                <button id="openThemeModal" class="btn-dash btn-dash-secondary" style="width: 100%; justify-content: center;">🎨 Customize Theme</button>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            
            <!-- Header Action & Filter Bar -->
            <div class="dashboard-header-bar">
                <div class="header-title-group">
                    <h1>📊 System Analytics & Monitoring</h1>
                    <p>Real-time background daemon statuses, seating metrics, and revenue estimates</p>
                </div>

                <div class="controls-group">
                    <form method="GET" action="/admin/dashboard" class="date-filter-form">
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($metrics['date_range']['from'] ?? date('Y-m-d', strtotime('-30 days'))); ?>" class="date-input-custom">
                        <span style="color: var(--text-muted); font-size: 0.85rem;">to</span>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($metrics['date_range']['to'] ?? date('Y-m-d')); ?>" class="date-input-custom">
                        <button type="submit" class="btn-dash btn-dash-secondary" style="padding: 0.4rem 0.85rem; font-size: 0.82rem;">Filter</button>
                    </form>
                    <button id="btnCleanAllTrackers" class="btn-dash" style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); font-weight: 800;">🧹 Full Clean: Wipe Active Trackers</button>
                    <button id="btnSetupTomorrow" class="btn-dash" style="background: linear-gradient(135deg, #10b981 0%, #047857 100%); font-weight: 800;">🌅 Setup for Tomorrow</button>
                    <button id="btnScrapeWeek" class="btn-dash" style="background: linear-gradient(135deg, #e50914 0%, #b20710 100%);">🗓️ Scrape Week (Fri–Thu)</button>
                    <button id="btnTriggerCollect" class="btn-dash btn-dash-secondary">🔄 Pre-cache Schedules</button>
                    <button id="btnTriggerTrack" class="btn-dash btn-dash-secondary">▶ Run Daemon</button>
                </div>
            </div>

            <?php if (!$dbConfigured): ?>
                <div style="background: rgba(231, 76, 60, 0.15); border: 1px solid #e74c3c; padding: 1.5rem; border-radius: var(--card-radius); color: #ff6b6b; margin-bottom: 2rem;">
                    <h3 style="margin: 0 0 0.5rem 0;">⚠️ Database Connection Error</h3>
                    <p style="margin: 0;"><?php echo htmlspecialchars($dbError); ?></p>
                </div>
            <?php else: ?>

                <!-- Daemon Status & Health Banner -->
                <div class="status-banner">
                    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <span class="status-badge <?php echo ($daemonStatus['is_running'] ?? false) ? 'status-active' : 'status-idle'; ?>">
                            ● <?php echo ($daemonStatus['is_running'] ?? false) ? 'Daemon Active' : 'Daemon Standby'; ?>
                        </span>
                        <span style="color: var(--text-secondary); font-size: 0.88rem;">
                            Last Activity: <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($daemonStatus['last_log_time'] ?? 'N/A'); ?></strong>
                        </span>
                    </div>
                    <div style="color: var(--text-secondary); font-size: 0.88rem;">
                        ⏱️ Next Scheduled Polling: <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($daemonStatus['next_run_time'] ?? 'N/A'); ?></strong>
                    </div>
                </div>

                <!-- Top Metric Cards Grid -->
                <div class="dashboard-grid">
                    <div class="stat-card">
                        <div class="stat-label">Active Monitors</div>
                        <div class="stat-value" style="color: #2ecc71;"><?php echo number_format($metrics['active_trackers'] ?? 0); ?></div>
                        <div class="stat-sub"><?php echo number_format($metrics['completed_trackers'] ?? 0); ?> sessions completed</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">Average Occupancy</div>
                        <div class="stat-value" style="color: #3498db;"><?php echo ($metrics['avg_occupancy'] ?? 0); ?>%</div>
                        <div class="stat-sub">Peak: <?php echo ($metrics['max_occupancy'] ?? 0); ?>% occupancy</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">Estimated Revenue</div>
                        <div class="stat-value" style="color: #f1c40f;">$<?php echo number_format($metrics['estimated_revenue'] ?? 0, 2); ?></div>
                        <div class="stat-sub"><?php echo number_format($metrics['tickets_sold'] ?? 0); ?> seats tracked</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">Pre-cached Showtimes</div>
                        <div class="stat-value" style="color: #e74c3c;"><?php echo number_format($metrics['total_showtimes'] ?? 0); ?></div>
                        <div class="stat-sub"><?php echo number_format($metrics['theatres_count'] ?? 0); ?> theatres / <?php echo number_format($metrics['movies_count'] ?? 0); ?> movies</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">Snapshots Logged</div>
                        <div class="stat-value" style="color: #9b59b6;"><?php echo number_format($metrics['total_snapshots'] ?? 0); ?></div>
                        <div class="stat-sub">Seating layouts archived</div>
                    </div>
                </div>

                <!-- 🏛️ Theater Telemetry Scope & Trimming Controls Section -->
                <div class="chart-box" style="margin-bottom: 2rem; border: 1px solid rgba(59, 130, 246, 0.3); background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(12px);">
                    <div class="chart-box-header" style="flex-wrap: wrap; gap: 1rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 1rem; margin-bottom: 1.25rem;">
                        <div>
                            <h3 style="display: flex; align-items: center; gap: 0.6rem; font-size: 1.3rem; margin: 0;">
                                <span>🏛️</span> Theater Telemetry Scope & Trimming Controls
                            </h3>
                            <span style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem; display: block;">
                                Manage active theaters for 15-minute seating occupancy polling and weekly schedule pre-caching. Trimming inactive locations keeps Cinepulse well within API rate limits.
                            </span>
                        </div>
                        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                            <button id="btnOpenAddTheatreModal" class="btn-dash" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); font-size: 0.85rem; padding: 0.45rem 0.9rem;">
                                ➕ Add New Location
                            </button>
                            <span style="background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.4); padding: 0.4rem 0.85rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem;">
                                🟢 <span id="cntActiveTheatres"><?php echo $activeTheatresCount; ?></span> Active Monitored
                            </span>
                            <span style="background: rgba(149, 165, 166, 0.15); color: #bdc3c7; border: 1px solid rgba(149, 165, 166, 0.4); padding: 0.4rem 0.85rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem;">
                                ⚪ <span id="cntDisabledTheatres"><?php echo $disabledTheatresCount; ?></span> Trimming Paused
                            </span>
                        </div>
                    </div>

                    <!-- Theater Controls Search & Filter Bar -->
                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; align-items: center;">
                        <div style="display: flex; gap: 0.4rem; overflow-x: auto; padding-bottom: 0.25rem;">
                            <button class="prov-filter-btn active" data-prov="all" style="padding: 0.4rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: var(--theme-primary, #3b82f6); color: #fff;">All Provinces (41)</button>
                            <button class="prov-filter-btn" data-prov="ON" style="padding: 0.4rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">Ontario (ON)</button>
                            <button class="prov-filter-btn" data-prov="QC" style="padding: 0.4rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">Quebec (QC)</button>
                            <button class="prov-filter-btn" data-prov="BC" style="padding: 0.4rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">British Columbia (BC)</button>
                            <button class="prov-filter-btn" data-prov="AB" style="padding: 0.4rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">Alberta (AB)</button>
                        </div>

                        <input type="text" id="theatreSearchInput" placeholder="🔍 Search theater name, city, or ID..." class="date-input-custom" style="flex: 1; min-width: 200px;">
                        
                        <select id="theatreStatusFilter" class="date-input-custom" style="min-width: 160px;">
                            <option value="all">⚡ All Telemetry Statuses</option>
                            <option value="enabled">🟢 Active Only</option>
                            <option value="disabled">⚪ Paused Only</option>
                        </select>
                    </div>

                    <!-- Theater Grid -->
                    <div id="theatreControlGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; max-height: 480px; overflow-y: auto; padding-right: 0.4rem;">
                        <!-- Rendered dynamically via JS -->
                    </div>
                </div>

                <!-- Weekly Scraped Schedule & Live Occupancy Explorer Section -->
                <div class="chart-box" style="margin-bottom: 2rem;">
                    <div class="chart-box-header" style="flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <h3 style="display: flex; align-items: center; gap: 0.5rem; font-size: 1.25rem;">🗓️ Weekly Scraped Schedule & Live Occupancy Explorer</h3>
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Browse pre-cached showtimes for Friday through Thursday and toggle live seating occupancy monitors.</span>
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <button id="btnRefreshSchedule" class="btn-dash btn-dash-secondary" style="padding: 0.4rem 0.85rem; font-size: 0.82rem;">🔄 Refresh Schedule</button>
                        </div>
                    </div>

                    <!-- Theatrical Week Day Selector Pills -->
                    <div style="display: flex; gap: 0.5rem; overflow-x: auto; padding-bottom: 0.75rem; margin-bottom: 1.25rem; border-bottom: 1px solid var(--glass-border);">
                        <?php foreach ($weekDays as $idx => $wd): ?>
                            <button class="week-day-pill <?php echo ($wd['is_today'] || ($idx === 0 && !array_filter($weekDays, fn($w) => $w['is_today']))) ? 'active' : ''; ?>" data-date="<?php echo $wd['date']; ?>" style="padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.85rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: <?php echo ($wd['is_today'] || ($idx === 0 && !array_filter($weekDays, fn($w) => $w['is_today']))) ? 'var(--theme-primary, #e50914)' : 'rgba(255,255,255,0.04)'; ?>; color: #ffffff; transition: all 0.2s ease; white-space: nowrap;">
                                <span style="opacity: 0.8; font-size: 0.72rem; text-transform: uppercase; display: block;"><?php echo $wd['day_name']; ?></span>
                                <span><?php echo $wd['short_date']; ?></span>
                                <?php if ($wd['is_today']): ?><span style="font-size: 0.62rem; background: #2ecc71; color: #000; padding: 1px 5px; border-radius: 4px; margin-left: 4px; font-weight: 800;">TODAY</span><?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Live Filter Controls -->
                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; align-items: center;">
                        <select id="schedLocationSelect" class="date-input-custom" style="min-width: 200px;">
                            <option value="">🏛️ All Theater Locations</option>
                            <?php foreach ($locations as $name => $id): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" id="schedSearchInput" placeholder="🔍 Search movie title or auditorium..." class="date-input-custom" style="flex: 1; min-width: 220px;">

                        <select id="schedFilterStatus" class="date-input-custom" style="min-width: 170px;">
                            <option value="all">⚡ All Showtimes</option>
                            <option value="tracked">● Only Monitored</option>
                            <option value="untracked">➕ Untracked Showtimes</option>
                        </select>
                    </div>

                    <!-- Weekly Schedule Grid Container -->
                    <div id="weeklyScheduleContainer" style="min-height: 250px;">
                        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">⌛ Loading weekly showtimes schedule...</div>
                    </div>
                </div>

                <!-- Charts Section Grid -->
                <div class="charts-grid">
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>📈 Daily Occupancy Trends</h3>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="dailyTrendChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>🎬 Top Movies by Occupancy</h3>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="topMoviesChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Terminal & Export Grid -->
                <div class="bottom-grid">
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>📥 Data & Schedule Export Center</h3>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.25rem; line-height: 1.5;">
                            Export raw historical seating occupancy snapshots CSV, or generate a publication-ready per-theater weekly schedule PDF.
                        </p>
                        
                        <form method="GET" action="/export-pdf" target="_blank" style="margin-bottom: 1.25rem; background: var(--bg-tertiary); padding: 12px; border-radius: 8px; border: 1px solid var(--glass-border);">
                            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                <select name="locationId" class="date-input-custom" style="flex: 1; min-width: 180px;">
                                    <?php foreach ($locations as $name => $id): ?>
                                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="print" value="1">
                                <button type="submit" class="btn-dash" style="padding: 0.45rem 1rem; font-size: 0.85rem;">
                                    📄 Export Theater Weekly PDF
                                </button>
                            </div>
                        </form>

                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                            <a href="/api?action=export_csv&type=occupancy&date_from=<?php echo urlencode($metrics['date_range']['from']); ?>&date_to=<?php echo urlencode($metrics['date_range']['to']); ?>" class="btn-dash btn-dash-secondary">
                                📊 Download Occupancy CSV
                            </a>
                            <a href="/api?action=export_csv&type=showtimes&date_from=<?php echo urlencode($metrics['date_range']['from']); ?>&date_to=<?php echo urlencode($metrics['date_range']['to']); ?>" class="btn-dash btn-dash-secondary">
                                🎬 Download Showtimes CSV
                            </a>
                        </div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>📜 Daemon Terminal Log</h3>
                            <span style="font-size: 0.8rem; color: var(--text-muted);">Latest 50 entries</span>
                        </div>
                        <div class="log-terminal">
                            <?php if (empty($logs)): ?>
                                <div style="color: var(--text-muted);">No execution log lines captured yet.</div>
                            <?php else: ?>
                                <?php foreach ($logs as $line): ?>
                                    <div><?php echo htmlspecialchars($line); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Historical Archives Section -->
                <div class="chart-box" style="margin-bottom: 3rem;">
                    <div class="chart-box-header" style="flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <h3 style="display: flex; align-items: center; gap: 0.5rem;">📦 Historical Archives (<?php echo count($archivesList); ?> Packages)</h3>
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Timestamped database dumps, showtimes, & seating occupancy history</span>
                        </div>
                        <div>
                            <button id="btnCreateArchive" class="btn-dash" style="background: #27ae60; font-size: 0.85rem; padding: 0.5rem 1rem;">
                                📦 Create New Archive Package
                            </button>
                        </div>
                    </div>

                    <?php if (empty($archivesList)): ?>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">No historical archive packages found in `/archives`.</p>
                    <?php else: ?>
                        <div class="table-responsive-wrapper">
                            <table class="archive-table">
                                <thead>
                                    <tr>
                                        <th>Archive Name</th>
                                        <th>Date Saved</th>
                                        <th>Showtimes Count</th>
                                        <th>Occupancy Logs</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($archivesList as $arch): ?>
                                        <tr>
                                            <td style="font-weight: 700; color: var(--text-primary);"><?php echo htmlspecialchars($arch['name']); ?></td>
                                            <td style="color: var(--text-secondary);"><?php echo htmlspecialchars($arch['created']); ?></td>
                                            <td style="color: #3498db; font-weight: 600;"><?php echo number_format($arch['showtimes_count']); ?> records</td>
                                            <td style="color: #2ecc71; font-weight: 600;"><?php echo number_format($arch['occupancy_count']); ?> logs</td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem;">
                                                    <button class="btn-dash btn-dash-secondary btn-view-archive" data-archive="<?php echo htmlspecialchars($arch['name']); ?>" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; background: rgba(54, 162, 235, 0.15); border-color: rgba(54, 162, 235, 0.4); color: #38bdf8;">
                                                        👁️ View Details
                                                    </button>
                                                    <button class="btn-dash btn-dash-secondary btn-import-archive" data-archive="<?php echo htmlspecialchars($arch['name']); ?>" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                                                        📥 Load into DB
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <!-- Archive Content Viewer Modal -->
    <div id="archiveViewModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(10px); z-index: 99999; justify-content: center; align-items: center; padding: 1.5rem; overflow-y: auto;">
        <div style="background: var(--bg-secondary, #14171d); border: 1px solid var(--glass-border); border-radius: 16px; max-width: 900px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7);">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02);">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.5rem;">📦</span>
                    <div>
                        <h2 id="archModalTitle" style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #ffffff;">Archive Details</h2>
                        <span id="archModalSubtitle" style="font-size: 0.82rem; color: var(--text-muted);">Package Metadata & Content Preview</span>
                    </div>
                </div>
                <button id="closeArchiveModal" style="background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; padding: 0.25rem 0.5rem;">&times;</button>
            </div>
            
            <div id="archModalBody" style="padding: 1.5rem; overflow-y: auto; display: flex; flex-direction: column; gap: 1.25rem;">
                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading archive metadata...</div>
            </div>

            <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.2);">
                <button id="btnModalImportDb" class="btn-dash" style="display: none; background: #27ae60;">📥 Restore Package into Database</button>
                <button id="btnModalCloseBottom" class="btn-dash btn-dash-secondary" style="margin-left: auto;">Close Viewer</button>
            </div>
        </div>
    </div>

    <!-- Add New Theater Location Modal -->
    <div id="addTheatreModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(10px); z-index: 99999; justify-content: center; align-items: center; padding: 1.5rem; overflow-y: auto;">
        <div style="background: var(--bg-secondary, #14171d); border: 1px solid var(--glass-border); border-radius: 16px; max-width: 540px; width: 100%; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7);">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02);">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.5rem;">🏛️</span>
                    <div>
                        <h2 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #ffffff;">Add Cineplex Location</h2>
                        <span style="font-size: 0.82rem; color: var(--text-muted);">Configure a new theater venue for occupancy tracking</span>
                    </div>
                </div>
                <button id="closeAddTheatreModal" style="background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; padding: 0.25rem 0.5rem;">&times;</button>
            </div>
            
            <form id="formAddTheatre" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem;">THEATER ID (NUMERIC CINEPLEX ID)</label>
                    <input type="number" id="addTheatreId" required placeholder="e.g. 7402" class="date-input-custom" style="width: 100%;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem;">THEATER FULL NAME</label>
                    <input type="text" id="addTheatreName" required placeholder="e.g. Scotiabank Theatre Toronto" class="date-input-custom" style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem;">CITY</label>
                        <input type="text" id="addTheatreCity" required placeholder="e.g. Toronto" class="date-input-custom" style="width: 100%;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem;">PROVINCE</label>
                        <select id="addTheatreProvince" class="date-input-custom" style="width: 100%;">
                            <option value="ON">Ontario (ON)</option>
                            <option value="QC">Quebec (QC)</option>
                            <option value="BC">British Columbia (BC)</option>
                            <option value="AB">Alberta (AB)</option>
                            <option value="MB">Manitoba (MB)</option>
                            <option value="NS">Nova Scotia (NS)</option>
                            <option value="SK">Saskatchewan (SK)</option>
                            <option value="NB">New Brunswick (NB)</option>
                            <option value="NL">Newfoundland (NL)</option>
                            <option value="PE">Prince Edward Island (PE)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem;">REGION / METRO AREA</label>
                    <input type="text" id="addTheatreRegion" placeholder="e.g. Greater Toronto Area" class="date-input-custom" style="width: 100%;">
                </div>

                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem;">SPECIAL AUDITORIUM FORMATS (COMMA SEPARATED)</label>
                    <input type="text" id="addTheatreScreens" placeholder="e.g. 70mm IMAX GT, UltraAVX, D-BOX" class="date-input-custom" style="width: 100%;">
                </div>

                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                    <input type="checkbox" id="addTheatreEnabled" checked style="width: 18px; height: 18px; accent-color: #3b82f6; cursor: pointer;">
                    <label for="addTheatreEnabled" style="font-size: 0.9rem; font-weight: 600; color: #ffffff; cursor: pointer;">Enable active telemetry monitoring immediately</label>
                </div>

                <div style="padding-top: 1rem; border-top: 1px solid var(--glass-border); display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem;">
                    <button type="button" id="cancelAddTheatre" class="btn-dash btn-dash-secondary">Cancel</button>
                    <button type="submit" id="btnSubmitAddTheatre" class="btn-dash" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">➕ Add Location</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/assets/js/shared.js"></script>
    <script src="/assets/js/design-options-modal.js"></script>
    
    <script>
        $(document.body).ready(function() {
            var csrfToken = $('meta[name="csrf-token"]').attr('content');
            var currentModalArchive = '';

            // --- Weekly Schedule & Live Occupancy Explorer ---
            var selectedScheduleDate = $('.week-day-pill.active').data('date') || '<?php echo date('Y-m-d'); ?>';

            function loadWeeklySchedule() {
                var $container = $('#weeklyScheduleContainer');
                $container.html('<div style="text-align: center; padding: 3rem; color: var(--text-muted);">⌛ Fetching pre-cached showtimes for ' + selectedScheduleDate + '...</div>');

                var theatreId = $('#schedLocationSelect').val();
                var search = $('#schedSearchInput').val();
                var filter = $('#schedFilterStatus').val();

                $.get('/api', {
                    action: 'get_weekly_schedule',
                    date: selectedScheduleDate,
                    theatre_id: theatreId,
                    search: search,
                    filter: filter
                }, function(res) {
                    if (!res.success) {
                        $container.html('<div style="color: #e74c3c; text-align: center; padding: 2rem;">Failed to load schedule data.</div>');
                        return;
                    }

                    if (!res.movies || res.movies.length === 0) {
                        var emptyHtml = '<div style="background: rgba(255,255,255,0.02); border: 1px dashed var(--glass-border); padding: 3rem 1.5rem; text-align: center; border-radius: 14px;">';
                        emptyHtml += '  <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📅</div>';
                        emptyHtml += '  <h4 style="margin: 0 0 0.5rem 0; font-weight: 700;">No showtimes pre-cached for ' + selectedScheduleDate + '</h4>';
                        emptyHtml += '  <p style="color: var(--text-muted); font-size: 0.9rem; max-width: 500px; margin: 0 auto 1.5rem auto;">No showtime records found matching your filters. Trigger the theatrical week scraper to pull the latest schedules from Cineplex.</p>';
                        emptyHtml += '  <button class="btn-dash btn-trigger-scrape-now" style="background: linear-gradient(135deg, #e50914 0%, #b20710 100%);">🗓️ Run Weekly Scraper Now</button>';
                        emptyHtml += '</div>';
                        $container.html(emptyHtml);
                        return;
                    }

                    var html = '';
                    html += '<div style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">';
                    html += '  <span>Showing <strong>' + res.total_showtimes + '</strong> showtimes across <strong>' + res.movies_count + '</strong> movies for ' + selectedScheduleDate + '</span>';
                    html += '</div>';

                    res.movies.forEach(function(m) {
                        html += '<div style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); border-radius: 14px; padding: 1.25rem; margin-bottom: 1rem; backdrop-filter: blur(8px);">';
                        html += '  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.75rem;">';
                        html += '    <div style="display: flex; align-items: center; gap: 0.75rem;">';
                        html += '      <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(229,9,20,0.15); border: 1px solid rgba(229,9,20,0.3); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;">🎬</div>';
                        html += '      <div>';
                        html += '        <h4 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: #ffffff;">' + $('<div>').text(m.movie_name).html() + '</h4>';
                        html += '        <span style="font-size: 0.8rem; color: var(--text-muted);">Runtime: ' + m.runtime + ' mins • ' + m.showtimes.length + ' sessions on ' + selectedScheduleDate + '</span>';
                        html += '      </div>';
                        html += '    </div>';

                        if (m.experience_types && m.experience_types.length > 0) {
                            html += '    <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">';
                            m.experience_types.forEach(function(exp) {
                                html += '      <span style="background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.3); color: #38bdf8; font-size: 0.72rem; padding: 0.2rem 0.55rem; border-radius: 6px; font-weight: 700;">' + $('<div>').text(exp).html() + '</span>';
                            });
                            html += '    </div>';
                        }
                        html += '  </div>';

                        // Cross-Theater Matrix Grouping
                        var byTheatre = {};
                        m.showtimes.forEach(function(s) {
                            var tName = s.theatre_name;
                            if (!byTheatre[tName]) {
                                byTheatre[tName] = {
                                    theatre_id: s.theatre_id,
                                    theatre_name: tName,
                                    sessions: []
                                };
                            }
                            byTheatre[tName].sessions.push(s);
                        });

                        html += '<div style="display: flex; flex-direction: column; gap: 0.85rem;">';

                        Object.keys(byTheatre).forEach(function(tName) {
                            var tData = byTheatre[tName];
                            var trackedInTheatre = tData.sessions.filter(function(x) { return x.tracker_id !== null; }).length;
                            var totalInTheatre = tData.sessions.length;

                            html += '<div style="background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 0.95rem;">';
                            
                            // Theater Subheader
                            html += '  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">';
                            html += '    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">';
                            html += '      <span style="font-weight: 800; font-size: 0.96rem; color: #60a5fa;">🏛️ ' + $('<div>').text(tName).html() + '</span>';
                            html += '      <span style="font-size: 0.72rem; background: rgba(255,255,255,0.08); color: var(--text-muted); padding: 1px 7px; border-radius: 10px; font-weight: 600;">' + totalInTheatre + ' sessions</span>';
                            if (trackedInTheatre > 0) {
                                html += '      <span style="font-size: 0.72rem; background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); padding: 1px 7px; border-radius: 10px; font-weight: 700;">🟢 ' + trackedInTheatre + '/' + totalInTheatre + ' Monitored</span>';
                            }
                            html += '    </div>';
                            html += '  </div>';

                            // Time Slot Chips Row
                            html += '  <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">';
                            tData.sessions.forEach(function(s) {
                                var isTracked = s.tracker_id !== null;
                                var badgeBg = isTracked ? 'rgba(46, 204, 113, 0.12)' : 'rgba(255, 255, 255, 0.04)';
                                var badgeBorder = isTracked ? 'rgba(46, 204, 113, 0.4)' : 'rgba(255, 255, 255, 0.12)';
                                var timeColor = isTracked ? '#ffffff' : '#e2e8f0';

                                html += '    <div style="background: ' + badgeBg + '; border: 1px solid ' + badgeBorder + '; border-radius: 10px; padding: 0.65rem 0.85rem; display: flex; align-items: center; gap: 0.65rem; min-width: 175px;">';
                                
                                html += '      <div style="flex: 1;">';
                                html += '        <div style="font-weight: 800; font-size: 0.95rem; color: ' + timeColor + '; line-height: 1.1;">' + s.show_start_formatted + '</div>';
                                html += '        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.15rem;">' + $('<div>').text(s.screen_name).html() + ' &bull; $' + s.ticket_price.toFixed(2) + '</div>';
                                
                                if (isTracked) {
                                    var occVal = s.latest_occupancy !== null ? (s.latest_occupancy + '% (' + s.latest_occupied_seats + '/' + s.latest_total_seats + ')') : 'Active';
                                    html += '        <div style="font-size: 0.7rem; color: #2ecc71; font-weight: 700; margin-top: 0.2rem;">🟢 ' + occVal + '</div>';
                                }
                                html += '      </div>';

                                // Action Buttons
                                html += '      <div style="margin-left: auto; display: flex; flex-direction: column; gap: 0.25rem;">';
                                if (isTracked) {
                                    html += '        <button class="btn-trigger-single-snap" data-tracker="' + s.tracker_id + '" title="Take Manual Snapshot" style="background: rgba(52, 152, 219, 0.25); color: #38bdf8; border: 1px solid rgba(52, 152, 219, 0.4); border-radius: 6px; padding: 3px 7px; font-size: 0.72rem; cursor: pointer;">📷</button>';
                                    html += '        <button class="btn-stop-single-track" data-tracker="' + s.tracker_id + '" title="Stop Telemetry" style="background: rgba(231, 76, 60, 0.25); color: #f87171; border: 1px solid rgba(231, 76, 60, 0.4); border-radius: 6px; padding: 3px 7px; font-size: 0.72rem; cursor: pointer;">❌</button>';
                                } else {
                                    html += '        <button class="btn-start-single-track" data-theatre-id="' + s.theatre_id + '" data-theatre-name="' + $('<div>').text(s.theatre_name).html() + '" data-showtime-id="' + s.showtime_id + '" data-movie-name="' + $('<div>').text(m.movie_name).html() + '" data-start-time="' + s.show_start_time + '" title="Track Occupancy" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); border-radius: 6px; padding: 4px 8px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">+ Track</button>';
                                }
                                html += '      </div>';

                                html += '    </div>';
                            });
                            html += '  </div>';

                            html += '</div>';
                        });

                        html += '</div>';
                        html += '</div>';
                    });

                    $container.html(html);
                }).fail(function() {
                    $container.html('<div style="color: #e74c3c; text-align: center; padding: 2rem;">Failed to fetch weekly schedule data.</div>');
                });
            }

            // Day Pill Click Handler
            $(document).on('click', '.week-day-pill', function() {
                $('.week-day-pill').removeClass('active').css({ background: 'rgba(255,255,255,0.04)', color: '#ffffff' });
                $(this).addClass('active').css({ background: 'var(--theme-primary, #e50914)', color: '#ffffff' });
                selectedScheduleDate = $(this).data('date');
                loadWeeklySchedule();
            });

            // Filters & Search handlers
            $('#schedLocationSelect, #schedFilterStatus').on('change', function() {
                loadWeeklySchedule();
            });

            var searchTimer;
            $('#schedSearchInput').on('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(loadWeeklySchedule, 300);
            });

            $('#btnRefreshSchedule').on('click', function() {
                loadWeeklySchedule();
            });

            $(document).on('click', '.btn-trigger-scrape-now', function() {
                $('#btnScrapeWeek').trigger('click');
            });

            // Quick Track Single Showtime from Dashboard Card
            $(document).on('click', '.btn-start-single-track', function() {
                var $btn = $(this);
                var theatreId = $btn.data('theatre-id');
                var theatreName = $btn.data('theatre-name');
                var showtimeId = $btn.data('showtime-id');
                var movieName = $btn.data('movie-name');
                var startTime = $btn.data('start-time');

                $btn.prop('disabled', true).text('⌛ Registering...');

                $.post('/api', {
                    action: 'add_tracker',
                    theatre_id: theatreId,
                    theatre_name: theatreName,
                    showtime_id: showtimeId,
                    movie_name: movieName,
                    show_start_time: startTime,
                    csrf_token: csrfToken
                }, function(res) {
                    if (res.success) {
                        loadWeeklySchedule();
                    } else {
                        alert('Error: ' + (res.error || 'Failed to start tracking.'));
                        $btn.prop('disabled', false).text('➕ Track Occupancy');
                    }
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to start tracking.'));
                    $btn.prop('disabled', false).text('➕ Track Occupancy');
                });
            });

            // Quick Log Single Snapshot from Dashboard Card
            $(document).on('click', '.btn-trigger-single-snap', function() {
                var $btn = $(this);
                var trackerId = $btn.data('tracker');

                $btn.prop('disabled', true).text('⌛ Logging...');

                $.post('/api', {
                    action: 'trigger_snapshot',
                    tracker_id: trackerId,
                    csrf_token: csrfToken
                }, function(res) {
                    if (res.success && res.snapshot_info) {
                        alert('Snapshot Logged! Latest Occupancy: ' + res.snapshot_info.occupancy_percentage + '% (' + res.snapshot_info.seats_occupied + '/' + res.snapshot_info.seats_total_layout + ' seats)');
                    }
                    loadWeeklySchedule();
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to take snapshot.'));
                    $btn.prop('disabled', false).text('📷 Take Snapshot');
                });
            });

            // Quick Stop Single Tracker from Dashboard Card
            $(document).on('click', '.btn-stop-single-track', function() {
                var $btn = $(this);
                var trackerId = $btn.data('tracker');

                if (!confirm('Stop monitoring seating occupancy for this showtime?')) return;

                $btn.prop('disabled', true).text('⌛ Stopping...');

                $.post('/api', {
                    action: 'delete_tracker',
                    tracker_id: trackerId,
                    csrf_token: csrfToken
                }, function(res) {
                    loadWeeklySchedule();
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to stop tracker.'));
                    $btn.prop('disabled', false).text('❌ Stop');
                });
            });

            // Initial load of weekly schedule
            loadWeeklySchedule();

            // Scrape Full Theatrical Week (Friday to Thursday)
            $('#btnScrapeWeek').on('click', function() {
                var $btn = $(this);
                if (!confirm('Scrape full theatrical week showtimes (Friday through Thursday) for all configured locations? This will update cached schedules and auto-register matching showtimes.')) {
                    return;
                }

                $btn.prop('disabled', true).text('⌛ Scraping Week (Fri–Thu)...');
                
                $.post('/api', { action: 'scrape_theatrical_week', csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Weekly theatrical schedule scraping complete!');
                    $btn.prop('disabled', false).text('🗓️ Scrape Week (Fri–Thu)');
                    location.reload();
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to scrape theatrical week schedules.'));
                    $btn.prop('disabled', false).text('🗓️ Scrape Week (Fri–Thu)');
                });
            });

            // Create New Archive Package
            $('#btnCreateArchive').on('click', function() {
                var label = prompt('Enter an optional label or note for this archive package (e.g. "Week 38 Release"):');
                if (label === null) return; // cancelled

                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Archiving...');

                $.post('/api', { action: 'create_archive', label: label, csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Archive package created successfully!');
                    $btn.prop('disabled', false).text('📦 Create New Archive Package');
                    location.reload();
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to create archive package.'));
                    $btn.prop('disabled', false).text('📦 Create New Archive Package');
                });
            });

            // Tab switching handler via event delegation
            $(document).on('click', '.arch-tab-btn', function() {
                $('.arch-tab-btn').css({ background: 'rgba(255,255,255,0.05)', border: '1px solid var(--glass-border)', color: 'rgba(255,255,255,0.7)' });
                $(this).css({ background: 'var(--theme-primary, #e50914)', border: 'none', color: '#fff' });

                var target = $(this).data('target');
                $('.arch-tab-content').hide();
                $(target).show();
            });

            // Movie Instant Search handler via event delegation
            $(document).on('input', '#archMovieSearch', function() {
                var query = $(this).val().toLowerCase().trim();
                $('.arch-movie-card').each(function() {
                    var title = String($(this).data('title') || '');
                    if (!query || title.indexOf(query) !== -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // View Archive Details Modal
            $(document).on('click', '.btn-view-archive', function() {
                var archiveName = $(this).data('archive');
                currentModalArchive = archiveName;

                $('#archModalTitle').text(archiveName);
                $('#archModalSubtitle').text('Loading package metadata...');
                $('#archModalBody').html('<div style="text-align: center; padding: 2rem; color: var(--text-muted);">⌛ Inspecting archive files and extracting showtimes data...</div>');
                $('#btnModalImportDb').hide();
                $('#archiveViewModal').css('display', 'flex');

                $.get('/api', { action: 'get_archive_details', archive_name: archiveName }, function(res) {
                    if (!res.success || !res.details) {
                        $('#archModalBody').html('<div style="color: #e74c3c;">Failed to inspect archive details.</div>');
                        return;
                    }

                    var d = res.details;
                    $('#archModalSubtitle').text('Created: ' + d.created + (d.min_date ? (' • Dates Covered: ' + d.min_date + ' to ' + d.max_date) : ''));
                    $('#btnModalImportDb').show().data('archive', archiveName);

                    var movies = d.movies || [];
                    var theatres = d.theatres || [];
                    var samples = d.sample_showtimes || [];
                    var files = d.files || [];

                    var html = '';

                    // 1. Top Stat Grid
                    html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">';
                    html += '  <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 0.85rem; border-radius: 12px; text-align: center;">';
                    html += '    <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Total Showtimes</div>';
                    html += '    <div style="font-size: 1.5rem; font-weight: 800; color: #38bdf8; margin-top: 0.2rem;">' + (d.table_counts.showtimes || 0).toLocaleString() + '</div>';
                    html += '  </div>';
                    html += '  <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 0.85rem; border-radius: 12px; text-align: center;">';
                    html += '    <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Occupancy Snapshots</div>';
                    html += '    <div style="font-size: 1.5rem; font-weight: 800; color: #4ade80; margin-top: 0.2rem;">' + (d.table_counts.showtime_occupancy_log || 0).toLocaleString() + '</div>';
                    html += '  </div>';
                    html += '  <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 0.85rem; border-radius: 12px; text-align: center;">';
                    html += '    <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Movies Catalogue</div>';
                    html += '    <div style="font-size: 1.5rem; font-weight: 800; color: #facc15; margin-top: 0.2rem;">' + movies.length + '</div>';
                    html += '  </div>';
                    html += '  <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 0.85rem; border-radius: 12px; text-align: center;">';
                    html += '    <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Theatres Tracked</div>';
                    html += '    <div style="font-size: 1.5rem; font-weight: 800; color: #f87171; margin-top: 0.2rem;">' + theatres.length + '</div>';
                    html += '  </div>';
                    html += '</div>';

                    // 2. Tabbed Navigation Bar
                    html += '<div style="display: flex; gap: 0.5rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.75rem; margin-bottom: 1.25rem; overflow-x: auto;">';
                    html += '  <button class="arch-tab-btn active" data-target="#tabMovies" style="background: var(--theme-primary, #e50914); border: none; color: #fff; padding: 0.45rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 700; cursor: pointer;">🎬 Movies Catalog (' + movies.length + ')</button>';
                    html += '  <button class="arch-tab-btn" data-target="#tabTheatres" style="background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: rgba(255,255,255,0.7); padding: 0.45rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">🏛️ Theatres (' + theatres.length + ')</button>';
                    html += '  <button class="arch-tab-btn" data-target="#tabShowtimes" style="background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: rgba(255,255,255,0.7); padding: 0.45rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">📋 Showtimes Sample</button>';
                    html += '  <button class="arch-tab-btn" data-target="#tabFiles" style="background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: rgba(255,255,255,0.7); padding: 0.45rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">📂 Files & Manifest</button>';
                    html += '</div>';

                    // TAB 1: Movies Catalogue Grid with Instant Search
                    html += '<div id="tabMovies" class="arch-tab-content">';
                    html += '  <div style="margin-bottom: 1rem;">';
                    html += '    <input type="text" id="archMovieSearch" placeholder="🔍 Search archived movies by title..." style="width: 100%; padding: 0.6rem 1rem; border-radius: 10px; background: rgba(0,0,0,0.4); border: 1px solid var(--glass-border); color: #fff; font-size: 0.88rem; font-family: inherit;">';
                    html += '  </div>';
                    html += '  <div id="archMovieGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 0.75rem; max-height: 380px; overflow-y: auto; padding-right: 4px;">';
                    if (movies.length === 0) {
                        html += '    <div style="color: var(--text-muted); font-size: 0.9rem;">No movie titles extracted from archive.</div>';
                    } else {
                        movies.forEach(function(m) {
                            var safeName = $('<div>').text(m).html();
                            html += '    <div class="arch-movie-card" data-title="' + safeName.toLowerCase() + '" style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 0.85rem 1rem; border-radius: 10px; display: flex; align-items: center; gap: 0.75rem; backdrop-filter: blur(8px); transition: all 0.2s ease;">';
                            html += '      <div style="width: 34px; height: 34px; border-radius: 8px; background: rgba(229, 9, 20, 0.15); border: 1px solid rgba(229, 9, 20, 0.3); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0;">🎬</div>';
                            html += '      <div style="font-weight: 600; font-size: 0.85rem; color: #ffffff; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + safeName + '</div>';
                            html += '    </div>';
                        });
                    }
                    html += '  </div>';
                    html += '</div>';

                    // TAB 2: Theatres Breakdown
                    html += '<div id="tabTheatres" class="arch-tab-content" style="display: none;">';
                    html += '  <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem; max-height: 380px; overflow-y: auto;">';
                    if (theatres.length === 0) {
                        html += '    <div style="color: var(--text-muted); font-size: 0.9rem;">No theatre locations extracted from archive.</div>';
                    } else {
                        theatres.forEach(function(t) {
                            var safeT = $('<div>').text(t).html();
                            html += '    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 0.85rem 1rem; border-radius: 10px; display: flex; align-items: center; gap: 0.75rem;">';
                            html += '      <div style="width: 34px; height: 34px; border-radius: 8px; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.3); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0;">🏛️</div>';
                            html += '      <div style="font-weight: 600; font-size: 0.88rem; color: #ffffff;">' + safeT + '</div>';
                            html += '    </div>';
                        });
                    }
                    html += '  </div>';
                    html += '</div>';

                    // TAB 3: Sample Showtimes Table
                    html += '<div id="tabShowtimes" class="arch-tab-content" style="display: none;">';
                    if (samples.length === 0) {
                        html += '  <div style="color: var(--text-muted); font-size: 0.9rem;">No sample showtime records available for preview.</div>';
                    } else {
                        html += '  <div class="table-responsive-wrapper" style="max-height: 380px; overflow-y: auto;">';
                        html += '    <table class="archive-table" style="font-size: 0.83rem;">';
                        html += '      <thead><tr><th>Movie Title</th><th>Theatre Location</th><th>Auditorium</th><th>Show Start Time</th><th>Ticket Price</th></tr></thead>';
                        html += '      <tbody>';
                        samples.forEach(function(s) {
                            html += '      <tr>';
                            html += '        <td style="font-weight: 700; color: #ffffff;">' + $('<div>').text(s.movie_name).html() + '</td>';
                            html += '        <td style="color: var(--text-secondary);">' + $('<div>').text(s.theatre_name).html() + '</td>';
                            html += '        <td style="color: var(--text-muted);">' + $('<div>').text(s.screen_name).html() + '</td>';
                            html += '        <td style="color: #38bdf8; font-weight: 600;">' + $('<div>').text(s.show_start_time).html() + '</td>';
                            html += '        <td style="color: #4ade80; font-weight: 600;">$' + $('<div>').text(s.ticket_price).html() + '</td>';
                            html += '      </tr>';
                        });
                        html += '      </tbody>';
                        html += '    </table>';
                        html += '  </div>';
                    }
                    html += '</div>';

                    // TAB 4: Files Manifest & Readme
                    html += '<div id="tabFiles" class="arch-tab-content" style="display: none;">';
                    html += '  <div style="display: flex; flex-direction: column; gap: 1rem;">';
                    html += '    <div>';
                    html += '      <h4 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; font-weight: 700;">📂 Archive Package Files</h4>';
                    html += '      <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--glass-border); padding: 0.75rem 1rem; border-radius: 10px; font-family: monospace; font-size: 0.83rem;">';
                    files.forEach(function(f) {
                        html += '        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; border-bottom: 1px solid rgba(255,255,255,0.03);">';
                        html += '          <span>📄 ' + $('<div>').text(f.name).html() + '</span>';
                        html += '          <span style="color: var(--text-muted);">' + f.size_formatted + '</span>';
                        html += '        </div>';
                    });
                    html += '      </div>';
                    html += '    </div>';
                    if (d.readme) {
                        html += '    <div>';
                        html += '      <h4 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; font-weight: 700;">📜 README.md Metadata</h4>';
                        html += '      <pre style="background: rgba(0,0,0,0.4); border: 1px solid var(--glass-border); padding: 0.85rem; border-radius: 10px; font-size: 0.82rem; color: #a7f3d0; white-space: pre-wrap; font-family: monospace; margin: 0;">' + $('<div>').text(d.readme).html() + '</pre>';
                        html += '    </div>';
                    }
                    html += '  </div>';
                    html += '</div>';

                    $('#archModalBody').html(html);
                }).fail(function() {
                    $('#archModalBody').html('<div style="color: #e74c3c;">Failed to load archive details from server.</div>');
                });
            });

            // Close Archive Viewer Modal
            $('#closeArchiveModal, #btnModalCloseBottom').on('click', function() {
                $('#archiveViewModal').css('display', 'none');
            });

            // Restore DB from Modal
            $('#btnModalImportDb').on('click', function() {
                var archiveName = $(this).data('archive') || currentModalArchive;
                if (!archiveName) return;
                
                if (!confirm('Restore package "' + archiveName + '" into the live database?')) {
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Restoring...');

                $.post('/api', { action: 'import_archive', archive_name: archiveName, csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Archive restored successfully!');
                    $btn.prop('disabled', false).text('📥 Restore Package into Database');
                    location.reload();
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to import archive.';
                    alert('Error: ' + errMsg);
                    $btn.prop('disabled', false).text('📥 Restore Package into Database');
                });
            });

            // Trigger Pre-cache
            $('#btnTriggerCollect').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Pre-caching...');
                
                $.post('/api', { action: 'trigger_cron_collect', csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Task started.');
                    $btn.prop('disabled', false).text('🔄 Pre-cache Schedules');
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to trigger pre-caching.';
                    alert('Error: ' + errMsg);
                    $btn.prop('disabled', false).text('🔄 Pre-cache Schedules');
                });
            });

            // Trigger Occupancy Daemon
            $('#btnTriggerTrack').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Running...');
                
                $.post('/api', { action: 'trigger_cron_track', csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Daemon started.');
                    $btn.prop('disabled', false).text('▶ Run Daemon');
                    setTimeout(function() { location.reload(); }, 1500);
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to start daemon.';
                    alert('Error: ' + errMsg);
                    $btn.prop('disabled', false).text('▶ Run Daemon');
                });
            });

            // Load Historical Archive into DB directly from table row
            $(document).on('click', '.btn-import-archive', function() {
                var $btn = $(this);
                var archiveName = $btn.data('archive');
                
                if (!confirm('Load archive records from "' + archiveName + '" into the live database?')) {
                    return;
                }
                
                $btn.prop('disabled', true).text('⌛ Importing...');
                $.post('/api', { action: 'import_archive', archive_name: archiveName, csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Archive imported successfully!');
                    $btn.prop('disabled', false).text('📥 Load into DB');
                    location.reload();
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to import archive.';
                    alert('Error: ' + errMsg);
                    $btn.prop('disabled', false).text('📥 Load into DB');
                });
            });

            // Render Chart.js Analytics
            <?php if (!empty($analytics)): ?>
                var dailyData = <?php echo json_encode($analytics['daily_trend'] ?? []); ?>;
                var movieData = <?php echo json_encode($analytics['top_movies'] ?? []); ?>;

                // 1. Daily Trend Chart
                var dailyLabels = dailyData.map(function(d) { return d.date; });
                var dailyValues = dailyData.map(function(d) { return parseFloat(d.avg_occ); });

                new Chart(document.getElementById('dailyTrendChart'), {
                    type: 'line',
                    data: {
                        labels: dailyLabels.length > 0 ? dailyLabels : ['No Data'],
                        datasets: [{
                            label: 'Avg Occupancy (%)',
                            data: dailyValues.length > 0 ? dailyValues : [0],
                            borderColor: '#3498db',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, max: 100, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: 'rgba(255,255,255,0.6)' } },
                            x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: 'rgba(255,255,255,0.6)' } }
                        }
                    }
                });

                // 2. Top Movies Chart
                var movieLabels = movieData.map(function(m) { return m.movie_name; });
                var movieValues = movieData.map(function(m) { return parseFloat(m.avg_occ); });

                new Chart(document.getElementById('topMoviesChart'), {
                    type: 'bar',
                    data: {
                        labels: movieLabels.length > 0 ? movieLabels : ['No Data'],
                        datasets: [{
                            label: 'Avg Occupancy (%)',
                            data: movieValues.length > 0 ? movieValues : [0],
                            backgroundColor: 'rgba(229, 9, 20, 0.75)',
                            borderColor: '#e50914',
                            borderWidth: 1,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, max: 100, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: 'rgba(255,255,255,0.6)' } },
                            x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: 'rgba(255,255,255,0.6)' } }
                        }
                    }
                });
            <?php endif; ?>

            // --- THEATER CONTROL CENTER JS LOGIC ---
            var allTheatresList = [];
            var currentProvFilter = 'all';

            function loadTheatresGrid() {
                $.getJSON('/api?action=list_theatres', function(res) {
                    if (res && res.success) {
                        allTheatresList = res.theatres || [];
                        $('#cntActiveTheatres').text(res.active_count || 0);
                        $('#cntDisabledTheatres').text(res.disabled_count || 0);
                        renderTheatresGrid();
                    }
                });
            }

            function renderTheatresGrid() {
                var search = $('#theatreSearchInput').val().toLowerCase().trim();
                var statusFilter = $('#theatreStatusFilter').val();

                var filtered = allTheatresList.filter(function(t) {
                    if (currentProvFilter !== 'all' && t.province !== currentProvFilter) {
                        return false;
                    }
                    if (statusFilter === 'enabled' && !t.enabled) return false;
                    if (statusFilter === 'disabled' && t.enabled) return false;

                    if (search.length > 0) {
                        var matchName = t.name.toLowerCase().indexOf(search) !== -1;
                        var matchCity = t.city.toLowerCase().indexOf(search) !== -1;
                        var matchRegion = t.region.toLowerCase().indexOf(search) !== -1;
                        var matchId = String(t.id).indexOf(search) !== -1;
                        return matchName || matchCity || matchRegion || matchId;
                    }
                    return true;
                });

                if (filtered.length === 0) {
                    $('#theatreControlGrid').html('<div style="grid-column: 1 / -1; text-align: center; padding: 2.5rem; color: var(--text-muted);">No theaters match your filter criteria.</div>');
                    return;
                }

                var html = '';
                filtered.forEach(function(t) {
                    var isEnabled = t.enabled;
                    var borderCol = isEnabled ? 'rgba(46, 204, 113, 0.4)' : 'rgba(255, 255, 255, 0.1)';
                    var bgCol = isEnabled ? 'rgba(46, 204, 113, 0.04)' : 'rgba(0, 0, 0, 0.2)';
                    
                    var screenBadges = (t.screens || []).map(function(s) {
                        return '<span style="font-size: 0.68rem; background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); padding: 1px 6px; border-radius: 4px; font-weight: 600;">' + s + '</span>';
                    }).join(' ');

                    html += '<div style="background: ' + bgCol + '; border: 1px solid ' + borderCol + '; border-radius: 12px; padding: 1rem; display: flex; flex-direction: column; justify-content: space-between; gap: 0.75rem; transition: all 0.2s ease;">';
                    
                    html += '  <div>';
                    html += '    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.4rem;">';
                    html += '      <strong style="font-size: 0.98rem; color: #ffffff; letter-spacing: -0.01em;">' + t.name + '</strong>';
                    html += '      <span style="font-size: 0.72rem; background: rgba(255,255,255,0.08); color: var(--text-muted); padding: 2px 6px; border-radius: 4px; font-family: monospace;">ID #' + t.id + '</span>';
                    html += '    </div>';
                    
                    html += '    <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.3rem;">';
                    html += '      📍 ' + t.city + ', ' + t.province + ' &bull; <span style="color: #94a3b8;">' + t.region + '</span>';
                    html += '    </div>';
                    
                    if (screenBadges) {
                        html += '    <div style="display: flex; gap: 0.3rem; flex-wrap: wrap; margin-bottom: 0.5rem;">' + screenBadges + '</div>';
                    }
                    html += '  </div>';

                    html += '  <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 0.75rem; margin-top: 0.25rem;">';
                    if (isEnabled) {
                        html += '    <span style="font-size: 0.78rem; color: #2ecc71; font-weight: 700; display: flex; align-items: center; gap: 0.3rem;">🟢 Active Monitored</span>';
                        html += '    <div style="display: flex; gap: 0.35rem;">';
                        html += '      <button class="btn-toggle-theatre btn-dash btn-dash-secondary" data-id="' + t.id + '" data-name="' + t.name + '" data-target="false" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; border-color: rgba(245, 158, 11, 0.4); color: #f59e0b;">⏸️ Pause</button>';
                        html += '      <button class="btn-delete-theatre btn-dash btn-dash-secondary" data-id="' + t.id + '" data-name="' + t.name + '" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; border-color: rgba(239, 68, 68, 0.4); color: #f87171;">🗑️ Delete</button>';
                        html += '    </div>';
                    } else {
                        html += '    <span style="font-size: 0.78rem; color: #94a3b8; font-weight: 600; display: flex; align-items: center; gap: 0.3rem;">⚪ Trimming Paused</span>';
                        html += '    <div style="display: flex; gap: 0.35rem;">';
                        html += '      <button class="btn-toggle-theatre btn-dash" data-id="' + t.id + '" data-name="' + t.name + '" data-target="true" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; background: var(--theme-primary, #3b82f6); color: #fff;">▶️ Enable</button>';
                        html += '      <button class="btn-delete-theatre btn-dash btn-dash-secondary" data-id="' + t.id + '" data-name="' + t.name + '" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; border-color: rgba(239, 68, 68, 0.4); color: #f87171;">🗑️ Delete</button>';
                        html += '    </div>';
                    }
                    html += '  </div>';

                    html += '</div>';
                });

                $('#theatreControlGrid').html(html);
            }

            // Province filter buttons click handler
            $(document).on('click', '.prov-filter-btn', function() {
                $('.prov-filter-btn').removeClass('active').css({ 'background': 'rgba(255,255,255,0.05)', 'color': 'var(--text-secondary)' });
                $(this).addClass('active').css({ 'background': 'var(--theme-primary, #3b82f6)', 'color': '#fff' });
                currentProvFilter = $(this).data('prov');
                renderTheatresGrid();
            });

            $('#theatreSearchInput, #theatreStatusFilter').on('input change', function() {
                renderTheatresGrid();
            });

            // Toggle Theatre active state AJAX call
            $(document).on('click', '.btn-toggle-theatre', function() {
                var $btn = $(this);
                var theatreId = $btn.data('id');
                var theatreName = $btn.data('name');
                var targetState = $btn.data('target');

                $btn.prop('disabled', true).text('⌛ Updating...');

                $.post('/api', {
                    action: 'toggle_theatre',
                    theatre_id: theatreId,
                    enabled: targetState,
                    csrf_token: csrfToken
                }, function(res) {
                    if (res && res.success) {
                        loadTheatresGrid();
                        loadWeeklySchedule();
                    } else {
                        alert('Error: ' + (res.error || 'Failed to toggle theater status.'));
                        loadTheatresGrid();
                    }
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to update theater status.';
                    alert('Error: ' + errMsg);
                    loadTheatresGrid();
                });
            });

            // Add Theater Modal Handlers
            $('#btnOpenAddTheatreModal').on('click', function() {
                $('#addTheatreModal').css('display', 'flex');
            });

            $('#closeAddTheatreModal, #cancelAddTheatre').on('click', function() {
                $('#addTheatreModal').hide();
            });

            $('#formAddTheatre').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#btnSubmitAddTheatre');
                $btn.prop('disabled', true).text('⌛ Saving Location...');

                $.post('/api', {
                    action: 'add_theatre',
                    theatre_id: $('#addTheatreId').val(),
                    name: $('#addTheatreName').val(),
                    city: $('#addTheatreCity').val(),
                    province: $('#addTheatreProvince').val(),
                    region: $('#addTheatreRegion').val() || 'Canada',
                    screens: $('#addTheatreScreens').val() || 'Standard',
                    enabled: $('#addTheatreEnabled').is(':checked'),
                    csrf_token: csrfToken
                }, function(res) {
                    if (res && res.success) {
                        $('#addTheatreModal').hide();
                        $('#formAddTheatre')[0].reset();
                        alert(res.message || 'Theater added successfully!');
                        loadTheatresGrid();
                        loadWeeklySchedule();
                    } else {
                        alert('Error: ' + (res.error || 'Failed to add location.'));
                    }
                    $btn.prop('disabled', false).text('➕ Add Location');
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to add location.';
                    alert('Error: ' + errMsg);
                    $btn.prop('disabled', false).text('➕ Add Location');
                });
            });

            // Delete Theater Handler
            $(document).on('click', '.btn-delete-theatre', function() {
                var $btn = $(this);
                var theatreId = $btn.attr('data-id') || $btn.data('id');
                var theatreName = $btn.attr('data-name') || $btn.data('name');

                if (!confirm('Are you sure you want to delete "' + theatreName + '" (ID #' + theatreId + ') from the location list?')) {
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Deleting...');

                $.post('/api', {
                    action: 'delete_theatre',
                    theatre_id: theatreId,
                    name: theatreName,
                    csrf_token: csrfToken
                }, function(res) {
                    if (res && res.success) {
                        alert(res.message || 'Theater removed from locations roster.');
                        loadTheatresGrid();
                        loadWeeklySchedule();
                    } else {
                        alert('Error: ' + (res.error || 'Failed to delete theater.'));
                        loadTheatresGrid();
                    }
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to delete theater.';
                    alert('Error: ' + errMsg);
                    loadTheatresGrid();
                });
            });

            // Full Clean / Wipe All Active Trackers Handler
            $('#btnCleanAllTrackers').on('click', function() {
                if (!confirm('Wipe ALL active showtime monitors and occupancy telemetry logs? This will reset active tracking to 0 and give you a clean slate for tomorrow.')) {
                    return;
                }
                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Wiping Active Trackers...');
                $.post('/api', { action: 'clean_all_trackers', csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'System cleaned successfully!');
                    location.reload();
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to wipe trackers.';
                    alert('Error: ' + errMsg);
                    $btn.prop('disabled', false).text('🧹 Full Clean: Wipe Active Trackers');
                });
            });

            // Setup for Tomorrow Handler
            $('#btnSetupTomorrow').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Pre-caching Tomorrow...');
                $.post('/api', { action: 'setup_tomorrow', csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Tomorrow pre-caching complete!');
                    $btn.prop('disabled', false).text('🌅 Setup for Tomorrow');
                    location.reload();
                }).fail(function(xhr) {
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed to setup tomorrow.';
                    alert('Error: ' + errMsg);
                    $btn.prop('disabled', false).text('🌅 Setup for Tomorrow');
                });
            });

            // Load initial theaters list
            loadTheatresGrid();
        });
    </script>
</body>
</html>
