<?php
require_once dirname(dirname(__DIR__)) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\TrackerService;

$db_configured = false;
$tables_missing = false;
$db_error = '';
$scan_logs = [];

try {
    $pdo = Cinepulse\Database::getInstance()->getConnection();
    $db_configured = true;
    
    // Instantiate first to trigger automatic table creation self-healing
    $trackerService = new TrackerService();
    
    // Check if logs table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'movie_tracker_scan_logs'");
    if ($stmt->rowCount() == 0) {
        $tables_missing = true;
    } else {
        $scan_logs = $trackerService->getScanLogs(150);
    }
} catch (Exception $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>🔍 Cinepulse — Scraper Execution Scan Logs</title>
    
    <!-- Open Graph & Twitter Social Share Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="🔍 Cinepulse — Scraper Execution Scan Logs">
    <meta property="og:description" content="Audit log history of API scraping triggers, date queries, and auto-monitored showtime registrations.">
    <meta property="og:image" content="/assets/images/share/terminal.jpg">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="🔍 Cinepulse — Scraper Execution Scan Logs">
    <meta name="twitter:description" content="Audit log history of API scraping triggers, date queries, and auto-monitored showtime registrations.">
    <meta name="twitter:image" content="/assets/images/share/terminal.jpg">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎬</text></svg>">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="/assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; }
        .main-wrapper { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .db-warning {
            background: linear-gradient(135deg, var(--color-error-light) 0%, var(--color-error) 100%);
            color: white; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px;
        }
        .db-warning h3 { margin: 0; color: white; }
        
        /* Table layout */
        .logs-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
        }
        .logs-table th {
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-weight: 600;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-light);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .logs-table td {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-light);
            font-size: 0.9rem;
            color: var(--text-primary);
            vertical-align: middle;
        }
        .logs-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .logs-table tr:last-child td {
            border-bottom: none;
        }
        
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 700;
            font-size: 0.75rem;
            padding: 4px 12px;
            border-radius: 30px;
            text-transform: uppercase;
        }
        .status-pill.success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .status-pill.error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        .metric-badge {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        .metric-badge.highlight {
            background: rgba(59, 130, 246, 0.15);
            color: var(--theme-primary);
            border-color: rgba(59, 130, 246, 0.2);
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
                <a href="/planner">🍿 Planner</a>
                <a href="/admin/tracker">📈 Tracker</a>
                <a href="/admin/dashboard">📊 Dashboard</a>
                <a href="/admin/scan-logs" class="active">🔍 Scan Logs</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Database Check Warnings -->
            <?php if (!$db_configured): ?>
                <div class="db-warning">
                    <h3>⚠ DB connection failed</h3>
                    <p>Log capability cannot be read. Config error: <?php echo htmlspecialchars($db_error); ?></p>
                </div>
                <?php exit; ?>
            <?php elseif ($tables_missing): ?>
                <div class="db-warning" style="background: linear-gradient(135deg, var(--color-warning-light) 0%, var(--color-warning) 100%);">
                    <h3>⚠ Setup Database Tables</h3>
                    <p>Please initialize schemas inside <code>cinepluse/schema.sql</code> on your MySQL server to continue.</p>
                </div>
                <?php exit; ?>
            <?php endif; ?>

            <!-- Header Section -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-light); padding-bottom: 12px; margin-bottom: 25px;">
                <h2 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span>🔍</span> Scraper Execution Scan Logs
                </h2>
                <?php if (!empty($scan_logs)): ?>
                    <button id="clear-scan-logs-btn" class="button-danger" style="font-size: 0.9rem; padding: 8px 18px; background: var(--color-error); border-color: var(--color-error); color: white; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <span>🧹</span> Sweep Logs History
                    </button>
                <?php endif; ?>
            </div>

            <!-- Page Explainer -->
            <p style="color: var(--text-secondary); margin-bottom: 30px; font-size: 0.95rem; line-height: 1.5;">
                This auditing panel displays the history of API pings initiated by active movie tracker rules (whether triggered via background cron jobs or manually). It tracks specific dates checked, raw showtimes matched on that date, and matches automatically promoted to the active monitor watchlist.
            </p>

            <!-- Logs Listing Card -->
            <div class="glass-card" style="padding: 0; overflow: hidden; border-radius: 12px; margin-bottom: 40px;">
                <?php if (empty($scan_logs)): ?>
                    <div style="text-align: center; color: var(--text-secondary); padding: 50px 20px;">
                        <span style="font-size: 3rem; display: block; margin-bottom: 15px;">🗂</span>
                        No scraper execution logs recorded yet. Once movie tracker rules execute a query check, logs will appear here.
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="logs-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Movie Rule Target</th>
                                    <th>Theatre Location</th>
                                    <th>Date Queried</th>
                                    <th>Matches Found</th>
                                    <th>Promoted to Watchlist</th>
                                    <th>Scan Executed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scan_logs as $log): 
                                    $is_success = $log['status'] === 'success';
                                    $formatted_time = date('M d, Y - g:i:s A', strtotime($log['scanned_at']));
                                    $formatted_query_date = date('M d, Y', strtotime($log['date_scanned']));
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($is_success): ?>
                                                <span class="status-pill success">🟢 OK</span>
                                            <?php else: ?>
                                                <span class="status-pill error" title="<?php echo htmlspecialchars($log['error_message'] ?? 'API Scraper Error'); ?>">🔴 ERROR</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($log['movie_name']); ?></strong>
                                        </td>
                                        <td>
                                            <span style="color: var(--text-secondary);"><?php echo htmlspecialchars($log['theatre_name']); ?></span>
                                        </td>
                                        <td>
                                            <strong style="color: var(--text-primary);"><?php echo $formatted_query_date; ?></strong>
                                        </td>
                                        <td>
                                            <span class="metric-badge"><?php echo $log['results_found']; ?> matched</span>
                                        </td>
                                        <td>
                                            <?php if ($log['new_registered'] > 0): ?>
                                                <span class="metric-badge highlight">✨ +<?php echo $log['new_registered']; ?> tracked</span>
                                            <?php else: ?>
                                                <span class="metric-badge" style="color: var(--text-muted);">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo $formatted_time; ?></span>
                                        </td>
                                    </tr>
                                    <?php if (!$is_success && !empty($log['error_message'])): ?>
                                        <tr style="background: rgba(239, 68, 68, 0.02);">
                                            <td colspan="7" style="padding: 8px 18px; border-bottom: 1px solid var(--border-light); font-size: 0.8rem; color: #ef4444; font-style: italic;">
                                                ⚠ Error Details: <?php echo htmlspecialchars($log['error_message']); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        $(function() {
            const csrfToken = $('meta[name="csrf-token"]').attr('content');
            
            // Sweep execution logs
            $('#clear-scan-logs-btn').click(function() {
                const btn = $(this);
                if (!confirm("Are you sure you want to clear all scraper execution scan logs? This action cannot be undone.")) {
                    return;
                }
                
                btn.prop('disabled', true).html('⏳ Sweeping...');
                
                const postData = {
                    action: 'clear_scan_logs',
                    csrf_token: csrfToken
                };
                
                $.post('/api', postData, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.error);
                        btn.prop('disabled', false).html('<span>🧹</span> Sweep Logs History');
                    }
                }, 'json').fail(function(xhr) {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Request failed.';
                    alert('Error: ' + err);
                    btn.prop('disabled', false).html('<span>🧹</span> Sweep Logs History');
                });
            });
        });
    </script>
    <script src="/assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
</body>
</html>
