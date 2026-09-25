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
$locations = [];
$locFile = dirname(dirname(__DIR__)) . '/config/locations.json';
if (file_exists($locFile)) {
    $locations = json_decode(file_get_contents($locFile), true) ?: [];
}

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

    <script src="/assets/js/shared.js"></script>
    <script src="/assets/js/design-options-modal.js"></script>
    
    <script>
        $(document.body).ready(function() {
            var csrfToken = $('meta[name="csrf-token"]').attr('content');
            var currentModalArchive = '';

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

                    // Tab switching handler
                    $('.arch-tab-btn').on('click', function() {
                        $('.arch-tab-btn').css({ background: 'rgba(255,255,255,0.05)', border: '1px solid var(--glass-border)', color: 'rgba(255,255,255,0.7)' });
                        $(this).css({ background: 'var(--theme-primary, #e50914)', border: 'none', color: '#fff' });

                        var target = $(this).data('target');
                        $('.arch-tab-content').hide();
                        $(target).show();
                    });

                    // Movie Instant Search handler
                    $('#archMovieSearch').on('input', function() {
                        var query = $(this).val().toLowerCase().trim();
                        $('.arch-movie-card').each(function() {
                            var title = $(this).data('title');
                            if (!query || title.indexOf(query) !== -1) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    });


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
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to import archive.'));
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
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to trigger pre-caching.'));
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
                    alert('Error: ' + (xhr.responseJSON?.error || 'Failed to start daemon.'));
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
