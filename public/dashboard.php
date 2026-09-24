<?php
/**
 * Cinepulse — System Monitoring & Analytics Dashboard
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\DashboardService;

Security::startSession();

// Setup DB status
$dbConfigured = true;
$dbError = '';
$dashService = null;
$daemonStatus = [];
$metrics = [];
$logs = [];

try {
    $dashService = new DashboardService();
    $dateFrom = Security::sanitizeInput($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')), 'date');
    $dateTo = Security::sanitizeInput($_GET['date_to'] ?? date('Y-m-d'), 'date');
    
    $daemonStatus = $dashService->getDaemonStatus();
    $metrics = $dashService->getSystemMetrics($dateFrom, $dateTo);
    $analytics = $dashService->getAnalyticsData($dateFrom, $dateTo);
    $logs = $dashService->getLogs('track', 50);
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
    <title>📊 Dashboard — Cinepulse System Analytics</title>
    
    <!-- External Dependencies -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Cinepulse Layout Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            backdrop-filter: blur(10px);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--accent-primary, #e50914);
        }
        .stat-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 0.5rem;
        }
        .stat-value {
            font-size: 1.85rem;
            font-weight: 700;
            color: #ffffff;
        }
        .stat-sub {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 0.25rem;
        }
        .chart-container {
            position: relative;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .log-terminal {
            background: #0d1117;
            color: #39d353;
            font-family: 'Courier New', Courier, monospace;
            padding: 1rem;
            border-radius: 8px;
            max-height: 350px;
            overflow-y: auto;
            font-size: 0.85rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-active { background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid #2ecc71; }
        .status-idle { background: rgba(52, 152, 219, 0.2); color: #3498db; border: 1px solid #3498db; }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            background: var(--accent-primary, #e50914);
            color: #fff;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn-action:hover { opacity: 0.9; }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
    </style>
</head>
<body class="theme-dark">

    <!-- Primary Navigation Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-icon">🎬</span>
                <span class="logo-text">Cinepulse</span>
            </a>
            <nav class="nav-links">
                <a href="index.php" class="nav-item">🍿 Showtimes</a>
                <a href="tracker.php" class="nav-item">📈 Seat Monitors</a>
                <a href="double-feature.php" class="nav-item">⚡ Double Feature</a>
                <a href="movies.php" class="nav-item">🎥 Movies Directory</a>
                <a href="dashboard.php" class="nav-item active">📊 Dashboard</a>
                <a href="tracker_scan_logs.php" class="nav-item">📋 Scraper Logs</a>
            </nav>
            <div class="header-actions">
                <button id="openThemeModal" class="btn-icon" title="Customize Design & Theme">🎨</button>
            </div>
        </div>
    </header>

    <main class="container" style="padding-top: 2rem; padding-bottom: 4rem;">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 2rem; margin: 0; font-weight: 800;">📊 System Analytics & Monitoring</h1>
                <p style="color: rgba(255,255,255,0.6); margin-top: 0.25rem;">Real-time daemon statuses, seating metrics, and revenue estimates</p>
            </div>
            
            <div style="display: flex; gap: 0.75rem; align-items: center;">
                <form method="GET" action="dashboard.php" style="display: flex; gap: 0.5rem; align-items: center;">
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($metrics['date_range']['from'] ?? date('Y-m-d', strtotime('-30 days'))); ?>" class="form-control" style="padding: 0.5rem; border-radius: 6px; background: rgba(0,0,0,0.4); color: #fff; border: 1px solid rgba(255,255,255,0.2);">
                    <span style="color: rgba(255,255,255,0.5);">to</span>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($metrics['date_range']['to'] ?? date('Y-m-d')); ?>" class="form-control" style="padding: 0.5rem; border-radius: 6px; background: rgba(0,0,0,0.4); color: #fff; border: 1px solid rgba(255,255,255,0.2);">
                    <button type="submit" class="btn-action btn-secondary" style="padding: 0.5rem 1rem;">Filter</button>
                </form>
                <button id="btnTriggerCollect" class="btn-action btn-secondary">🔄 Pre-cache Schedules</button>
                <button id="btnTriggerTrack" class="btn-action">▶ Run Occupancy Daemon</button>
            </div>
        </div>

        <?php if (!$dbConfigured): ?>
            <div class="alert alert-danger" style="background: rgba(231, 76, 60, 0.15); border: 1px solid #e74c3c; padding: 1.5rem; border-radius: 10px; color: #ff6b6b; margin-bottom: 2rem;">
                <h3>⚠️ Database Uninitialized</h3>
                <p><?php echo htmlspecialchars($dbError); ?></p>
            </div>
        <?php else: ?>

            <!-- Daemon & Health Status Bar -->
            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1rem 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <span class="status-badge <?php echo ($daemonStatus['is_running'] ?? false) ? 'status-active' : 'status-idle'; ?>">
                        ● <?php echo ($daemonStatus['is_running'] ?? false) ? 'Daemon Active' : 'Daemon Standby'; ?>
                    </span>
                    <span style="color: rgba(255,255,255,0.7); font-size: 0.9rem;">
                        Last Activity: <strong><?php echo htmlspecialchars($daemonStatus['last_log_time'] ?? 'N/A'); ?></strong>
                    </span>
                </div>
                <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem;">
                    ⏱️ Next Scheduled Polling: <strong><?php echo htmlspecialchars($daemonStatus['next_run_time'] ?? 'N/A'); ?></strong>
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
                    <div class="stat-label">Showtimes Pre-Cached</div>
                    <div class="stat-value" style="color: #e74c3c;"><?php echo number_format($metrics['total_showtimes'] ?? 0); ?></div>
                    <div class="stat-sub"><?php echo number_format($metrics['theatres_count'] ?? 0); ?> theatres / <?php echo number_format($metrics['movies_count'] ?? 0); ?> movies</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Snapshots Logged</div>
                    <div class="stat-value" style="color: #9b59b6;"><?php echo number_format($metrics['total_snapshots'] ?? 0); ?></div>
                    <div class="stat-sub">Seating layouts archived</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                
                <!-- Daily Trend Chart -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 style="margin: 0; font-size: 1.1rem;">📈 Daily Occupancy Trends</h3>
                    </div>
                    <canvas id="dailyTrendChart" height="220"></canvas>
                </div>

                <!-- Top Movies Chart -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 style="margin: 0; font-size: 1.1rem;">🎬 Top Movies by Occupancy</h3>
                    </div>
                    <canvas id="topMoviesChart" height="220"></canvas>
                </div>

            </div>

            <!-- Export & Logs Section -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.5rem;">
                
                <!-- CSV Data Export Portal -->
                <div class="chart-container">
                    <h3 style="margin-top: 0; font-size: 1.1rem; margin-bottom: 1rem;">📥 CSV Export Center</h3>
                    <p style="color: rgba(255,255,255,0.6); font-size: 0.9rem; margin-bottom: 1.5rem;">
                        Export raw historical seating occupancy snapshots or pre-cached showtimes catalog for analysis in Excel or Python.
                    </p>
                    
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <a href="api.php?action=export_csv&type=occupancy&date_from=<?php echo urlencode($metrics['date_range']['from']); ?>&date_to=<?php echo urlencode($metrics['date_range']['to']); ?>" class="btn-action btn-secondary">
                            📊 Download Occupancy CSV
                        </a>
                        <a href="api.php?action=export_csv&type=showtimes&date_from=<?php echo urlencode($metrics['date_range']['from']); ?>&date_to=<?php echo urlencode($metrics['date_range']['to']); ?>" class="btn-action btn-secondary">
                            🎬 Download Showtimes CSV
                        </a>
                    </div>
                </div>

                <!-- Daemon Execution Logs -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 style="margin: 0; font-size: 1.1rem;">📜 Daemon Terminal Log</h3>
                        <span style="font-size: 0.8rem; color: rgba(255,255,255,0.5);">Latest 50 entries</span>
                    </div>
                    <div class="log-terminal">
                        <?php if (empty($logs)): ?>
                            <div style="color: rgba(255,255,255,0.4);">No execution log lines captured yet.</div>
                        <?php else: ?>
                            <?php foreach ($logs as $line): ?>
                                <div><?php echo htmlspecialchars($line); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Historical Archives Section -->
            <?php
            $archiveService = new Cinepulse\ArchiveService();
            $archivesList = $archiveService->listArchives();
            ?>
            <div class="chart-container" style="margin-top: 1.5rem;">
                <div class="chart-header">
                    <h3 style="margin: 0; font-size: 1.1rem;">📦 Historical Archives (<?php echo count($archivesList); ?> Archived Periods)</h3>
                    <span style="font-size: 0.85rem; color: rgba(255,255,255,0.5);">Historical showtimes & seating occupancy history</span>
                </div>

                <?php if (empty($archivesList)): ?>
                    <p style="color: rgba(255,255,255,0.5);">No historical archive packages found in `/archives`.</p>
                <?php else: ?>
                    <div style="max-height: 320px; overflow-y: auto; margin-top: 1rem;">
                        <table style="width: 100%; text-align: left; border-collapse: collapse; font-size: 0.9rem;">
                            <thead>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: rgba(255,255,255,0.6);">
                                    <th style="padding: 0.75rem;">Archive Name</th>
                                    <th style="padding: 0.75rem;">Date Saved</th>
                                    <th style="padding: 0.75rem;">Showtimes Count</th>
                                    <th style="padding: 0.75rem;">Occupancy Logs</th>
                                    <th style="padding: 0.75rem;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivesList as $arch): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 0.75rem; font-weight: 600; color: #fff;"><?php echo htmlspecialchars($arch['name']); ?></td>
                                        <td style="padding: 0.75rem; color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars($arch['created']); ?></td>
                                        <td style="padding: 0.75rem; color: #3498db;"><?php echo number_format($arch['showtimes_count']); ?> records</td>
                                        <td style="padding: 0.75rem; color: #2ecc71;"><?php echo number_format($arch['occupancy_count']); ?> logs</td>
                                        <td style="padding: 0.75rem;">
                                            <button class="btn-action btn-secondary btn-import-archive" data-archive="<?php echo htmlspecialchars($arch['name']); ?>" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                                                📥 Load into DB
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            </div>

        <?php endif; ?>

    </main>

    <!-- Theme & Modal Options -->
    <script src="assets/js/shared.js"></script>
    <script src="assets/js/design-options-modal.js"></script>
    
    <script>
        $(document.body).ready(function() {
            var csrfToken = $('meta[name="csrf-token"]').attr('content');

            // Trigger Pre-cache
            $('#btnTriggerCollect').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Pre-caching...');
                
                $.post('api.php', { action: 'trigger_cron_collect', csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Task started.');
                    $btn.prop('disabled', false).text('🔄 Pre-cache Schedules');
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to trigger pre-caching.'));
                    $btn.prop('disabled', false).text('🔄 Pre-cache Schedules');
                });
            });

            // Trigger Occupancy Daemon
            $('#btnTriggerTrack').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('⌛ Running...');
                
                $.post('api.php', { action: 'trigger_cron_track', csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Daemon started.');
                    $btn.prop('disabled', false).text('▶ Run Occupancy Daemon');
                    setTimeout(function() { location.reload(); }, 1500);
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to start daemon.'));
                    $btn.prop('disabled', false).text('▶ Run Occupancy Daemon');
                });
            });

            // Load Historical Archive into DB
            $(document).on('click', '.btn-import-archive', function() {
                var $btn = $(this);
                var archiveName = $btn.data('archive');
                
                if (!confirm('Load archive records from "' + archiveName + '" into the live database?')) {
                    return;
                }
                
                $btn.prop('disabled', true).text('⌛ Importing...');
                $.post('api.php', { action: 'import_archive', archive_name: archiveName, csrf_token: csrfToken }, function(res) {
                    alert(res.message || 'Archive imported successfully!');
                    $btn.prop('disabled', false).text('📥 Load into DB');
                    location.reload();
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to import archive.'));
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
                            tension: 0.3
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, max: 100, grid: { color: 'rgba(255,255,255,0.05)' } },
                            x: { grid: { color: 'rgba(255,255,255,0.05)' } }
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
                            backgroundColor: 'rgba(229, 9, 20, 0.7)',
                            borderColor: '#e50914',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, max: 100, grid: { color: 'rgba(255,255,255,0.05)' } },
                            x: { grid: { color: 'rgba(255,255,255,0.05)' } }
                        }
                    }
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>
