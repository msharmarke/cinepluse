<?php
/**
 * Cinepulse — System Monitoring & Analytics Dashboard
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\DashboardService;
use Cinepulse\ArchiveService;

Security::startSession();

// Setup DB status
$dbConfigured = true;
$dbError = '';
$dashService = null;
$daemonStatus = [];
$metrics = [];
$analytics = [];
$logs = [];
$archivesList = [];

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
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.15);
            font-size: 0.85rem;
            font-family: inherit;
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
<body class="theme-dark">

    <div class="app-container">
        
        <!-- Standard App Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>🎬 Cinepulse</h1>
                <p>Command Center Analytics</p>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php">📅 Schedule</a>
                <a href="movies.php">🎬 Movies</a>
                <a href="double-feature.php">🍿 Planner</a>
                <a href="tracker.php">📈 Tracker</a>
                <a href="dashboard.php" class="active">📊 Dashboard</a>
                <a href="tracker_scan_logs.php">🔍 Scan Logs</a>
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
                    <form method="GET" action="dashboard.php" class="date-filter-form">
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($metrics['date_range']['from'] ?? date('Y-m-d', strtotime('-30 days'))); ?>" class="date-input-custom">
                        <span style="color: rgba(255,255,255,0.4); font-size: 0.85rem;">to</span>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($metrics['date_range']['to'] ?? date('Y-m-d')); ?>" class="date-input-custom">
                        <button type="submit" class="btn-dash btn-dash-secondary" style="padding: 0.4rem 0.85rem; font-size: 0.82rem;">Filter</button>
                    </form>
                    <button id="btnTriggerCollect" class="btn-dash btn-dash-secondary">🔄 Pre-cache Schedules</button>
                    <button id="btnTriggerTrack" class="btn-dash">▶ Run Daemon</button>
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
                        <span style="color: rgba(255,255,255,0.7); font-size: 0.88rem;">
                            Last Activity: <strong style="color: #fff;"><?php echo htmlspecialchars($daemonStatus['last_log_time'] ?? 'N/A'); ?></strong>
                        </span>
                    </div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.88rem;">
                        ⏱️ Next Scheduled Polling: <strong style="color: #fff;"><?php echo htmlspecialchars($daemonStatus['next_run_time'] ?? 'N/A'); ?></strong>
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

                <!-- Charts Section Grid -->
                <div class="charts-grid">
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>📈 Daily Occupancy Trends</h3>
                        </div>
                        <canvas id="dailyTrendChart" height="220"></canvas>
                    </div>

                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>🎬 Top Movies by Occupancy</h3>
                        </div>
                        <canvas id="topMoviesChart" height="220"></canvas>
                    </div>
                </div>

                <!-- Terminal & CSV Export Grid -->
                <div class="bottom-grid">
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>📥 CSV Export Center</h3>
                        </div>
                        <p style="color: rgba(255,255,255,0.6); font-size: 0.9rem; margin-bottom: 1.5rem; line-height: 1.5;">
                            Export raw historical seating occupancy snapshots or pre-cached showtimes catalog for analysis in Excel or Python.
                        </p>
                        
                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                            <a href="api.php?action=export_csv&type=occupancy&date_from=<?php echo urlencode($metrics['date_range']['from']); ?>&date_to=<?php echo urlencode($metrics['date_range']['to']); ?>" class="btn-dash btn-dash-secondary">
                                📊 Download Occupancy CSV
                            </a>
                            <a href="api.php?action=export_csv&type=showtimes&date_from=<?php echo urlencode($metrics['date_range']['from']); ?>&date_to=<?php echo urlencode($metrics['date_range']['to']); ?>" class="btn-dash btn-dash-secondary">
                                🎬 Download Showtimes CSV
                            </a>
                        </div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-box-header">
                            <h3>📜 Daemon Terminal Log</h3>
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
                <div class="chart-box" style="margin-bottom: 3rem;">
                    <div class="chart-box-header">
                        <h3>📦 Historical Archives (<?php echo count($archivesList); ?> Archived Periods)</h3>
                        <span style="font-size: 0.85rem; color: rgba(255,255,255,0.5);">Showtimes & seating occupancy history</span>
                    </div>

                    <?php if (empty($archivesList)): ?>
                        <p style="color: rgba(255,255,255,0.5); font-size: 0.9rem;">No historical archive packages found in `/archives`.</p>
                    <?php else: ?>
                        <div class="table-responsive-wrapper">
                            <table class="archive-table">
                                <thead>
                                    <tr>
                                        <th>Archive Name</th>
                                        <th>Date Saved</th>
                                        <th>Showtimes Count</th>
                                        <th>Occupancy Logs</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($archivesList as $arch): ?>
                                        <tr>
                                            <td style="font-weight: 700; color: #fff;"><?php echo htmlspecialchars($arch['name']); ?></td>
                                            <td style="color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars($arch['created']); ?></td>
                                            <td style="color: #3498db; font-weight: 600;"><?php echo number_format($arch['showtimes_count']); ?> records</td>
                                            <td style="color: #2ecc71; font-weight: 600;"><?php echo number_format($arch['occupancy_count']); ?> logs</td>
                                            <td>
                                                <button class="btn-dash btn-dash-secondary btn-import-archive" data-archive="<?php echo htmlspecialchars($arch['name']); ?>" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
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

            <?php endif; ?>

        </main>
    </div>

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
                    $btn.prop('disabled', false).text('▶ Run Daemon');
                    setTimeout(function() { location.reload(); }, 1500);
                }).fail(function(xhr) {
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to start daemon.'));
                    $btn.prop('disabled', false).text('▶ Run Daemon');
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
        });
    </script>
</body>
</html>
