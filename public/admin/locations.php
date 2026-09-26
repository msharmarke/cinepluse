<?php
/**
 * Cinepulse — Theater Location Management Center
 * Dedicated admin portal to view, enable, disable, and archive cinema locations.
 */

require_once dirname(dirname(__DIR__)) . '/src/Autoloader.php';

use Cinepulse\Security;
use Cinepulse\ShowtimeService;

Security::startSession();
Security::requireAdmin();

$detailedTheatres = ShowtimeService::getDetailedTheatres();
$activeCount = count(array_filter($detailedTheatres, fn($t) => $t['enabled']));
$disabledCount = count($detailedTheatres) - $activeCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>📍 Theater Locations & Archive — Cinepulse Admin</title>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="/assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --glass-bg: rgba(15, 23, 42, 0.75);
            --glass-border: rgba(255, 255, 255, 0.1);
            --card-radius: 14px;
        }

        .location-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .location-header h1 {
            font-size: 1.85rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .location-header p {
            color: var(--text-muted, rgba(255, 255, 255, 0.6));
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .badge-count {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .badge-active { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.4); }
        .badge-archived { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4); }

        .location-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .loc-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 1rem;
            backdrop-filter: blur(12px);
            transition: all 0.25s ease;
        }
        .loc-card.is-active {
            border-color: rgba(46, 204, 113, 0.4);
            background: rgba(46, 204, 113, 0.03);
        }
        .loc-card.is-active:hover {
            box-shadow: 0 8px 25px rgba(46, 204, 113, 0.15);
            transform: translateY(-2px);
        }
        .loc-card.is-archived {
            border-color: rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.25);
            opacity: 0.88;
        }
        .loc-card.is-archived:hover {
            opacity: 1;
            transform: translateY(-2px);
            border-color: rgba(245, 158, 11, 0.3);
        }

        .btn-dash {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.1rem;
            border-radius: 9px;
            background: var(--theme-primary, #e50914);
            color: #fff;
            font-weight: 700;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-dash:hover {
            transform: translateY(-1px);
            opacity: 0.95;
        }
        .btn-dash-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.15);
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
                <a href="/admin/locations" class="active">📍 Locations</a>
                <a href="/admin/scan-logs">🔍 Scan Logs</a>
                <a href="/admin/logout" style="color: #ef4444;">🔒 Logout</a>
            </nav>
            <div style="padding: 1rem 1.5rem; margin-top: auto;">
                <button id="openThemeModal" class="btn-dash btn-dash-secondary" style="width: 100%; justify-content: center;">🎨 Customize Theme</button>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            
            <div class="location-header">
                <div>
                    <h1>📍 Theater Locations & Telemetry Roster</h1>
                    <p>Enable or disable active cinema monitoring. Active theaters update automatically across schedules, seat maps, and pre-caching.</p>
                </div>
                
                <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                    <button id="btnPauseAll" class="btn-dash btn-dash-secondary" style="border-color: rgba(245, 158, 11, 0.4); color: #f59e0b;">
                        📦 Archive All Locations
                    </button>
                    <span class="badge-count badge-active">
                        🟢 <span id="cntActive"><?php echo $activeCount; ?></span> Active Monitored
                    </span>
                    <span class="badge-count badge-archived">
                        📦 <span id="cntDisabled"><?php echo $disabledCount; ?></span> In Location Archive
                    </span>
                </div>
            </div>

            <!-- Instant Search Bar & Filter Options -->
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem; align-items: center; background: rgba(15, 23, 42, 0.6); padding: 1rem; border-radius: 12px; border: 1px solid var(--glass-border);">
                <input type="text" id="locSearchInput" placeholder="🔍 Search theater name, city, or ID..." style="flex: 1; min-width: 240px; padding: 0.6rem 1rem; border-radius: 9px; background: #0f172a; color: #fff; border: 1px solid #334155; font-size: 0.9rem;">
                
                <select id="locStatusFilter" style="padding: 0.6rem 1rem; border-radius: 9px; background: #0f172a; color: #fff; border: 1px solid #334155; font-size: 0.9rem;">
                    <option value="all">⚡ All Statuses</option>
                    <option value="enabled">🟢 Enabled Only</option>
                    <option value="disabled">📦 Archived / Disabled Only</option>
                </select>

                <div style="display: flex; gap: 0.4rem; overflow-x: auto;">
                    <button class="prov-btn active" data-prov="all" style="padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: var(--theme-primary, #3b82f6); color: #fff;">All Provinces</button>
                    <button class="prov-btn" data-prov="ON" style="padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">Ontario (ON)</button>
                    <button class="prov-btn" data-prov="QC" style="padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">Quebec (QC)</button>
                    <button class="prov-btn" data-prov="BC" style="padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">British Columbia (BC)</button>
                    <button class="prov-btn" data-prov="AB" style="padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--text-secondary);">Alberta (AB)</button>
                </div>
            </div>

            <!-- Location Grid -->
            <div id="locationGrid" class="location-grid">
                <!-- Rendered dynamically -->
            </div>

        </main>
    </div>

    <script>
        const csrfToken = $('meta[name="csrf-token"]').attr('content');
        let theatresList = [];
        let currentProv = 'all';

        function fetchLocations() {
            $.getJSON('/api?action=list_theatres', function(res) {
                if (res && res.success) {
                    theatresList = res.theatres || [];
                    $('#cntActive').text(res.active_count || 0);
                    $('#cntDisabled').text(res.disabled_count || 0);
                    renderGrid();
                }
            });
        }

        function renderGrid() {
            const query = $('#locSearchInput').val().toLowerCase().trim();
            const status = $('#locStatusFilter').val();

            const filtered = theatresList.filter(t => {
                if (currentProv !== 'all' && t.province !== currentProv) return false;
                if (status === 'enabled' && !t.enabled) return false;
                if (status === 'disabled' && t.enabled) return false;

                if (query) {
                    const matchName = t.name.toLowerCase().includes(query);
                    const matchCity = t.city.toLowerCase().includes(query);
                    const matchProv = t.province.toLowerCase().includes(query);
                    const matchId = String(t.id).includes(query);
                    return matchName || matchCity || matchProv || matchId;
                }
                return true;
            });

            if (filtered.length === 0) {
                $('#locationGrid').html('<div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: var(--text-muted);">No theater locations match your filter.</div>');
                return;
            }

            let html = '';
            filtered.forEach(t => {
                const isEnabled = t.enabled;
                const cardClass = isEnabled ? 'loc-card is-active' : 'loc-card is-archived';
                const statusBadge = isEnabled ? 
                    '<span style="font-size: 0.78rem; background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.4); padding: 3px 8px; border-radius: 6px; font-weight: 700;">🟢 ACTIVE MONITORED</span>' : 
                    '<span style="font-size: 0.78rem; background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4); padding: 3px 8px; border-radius: 6px; font-weight: 700;">📦 ARCHIVED</span>';

                const screenBadges = (t.screens || []).map(s => 
                    `<span style="font-size: 0.68rem; background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); padding: 2px 6px; border-radius: 4px; font-weight: 600;">${s}</span>`
                ).join(' ');

                const actionBtn = isEnabled ? 
                    `<button class="btn-toggle btn-dash btn-dash-secondary" data-id="${t.id}" data-target="false" style="border-color: rgba(245, 158, 11, 0.4); color: #f59e0b;">📦 Move to Archive</button>` :
                    `<button class="btn-toggle btn-dash" data-id="${t.id}" data-target="true" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; font-weight: 800;">⚡ Enable Location</button>`;

                html += `
                <div class="${cardClass}">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <strong style="font-size: 1.05rem; color: #fff;">${t.name}</strong>
                            <span style="font-size: 0.75rem; background: rgba(255,255,255,0.08); color: var(--text-muted); padding: 2px 6px; border-radius: 4px; font-family: monospace;">ID #${t.id}</span>
                        </div>
                        
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.6rem;">
                            📍 ${t.city}, ${t.province} &bull; <span style="color: #94a3b8;">${t.region}</span>
                        </div>
                        
                        <div style="display: flex; gap: 0.3rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                            ${screenBadges}
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 0.75rem;">
                        ${statusBadge}
                        ${actionBtn}
                    </div>
                </div>`;
            });

            $('#locationGrid').html(html);
        }

        // Toggle Event
        $(document).on('click', '.btn-toggle', function() {
            const $btn = $(this);
            const theatreId = $btn.data('id');
            const targetState = $btn.data('target');

            $btn.prop('disabled', true).text('⌛ Updating...');

            $.post('/api', {
                action: 'toggle_theatre',
                theatre_id: theatreId,
                enabled: targetState,
                csrf_token: csrfToken
            }, function(res) {
                if (res && res.success) {
                    fetchLocations();
                } else {
                    alert('Error: ' + (res.error || 'Failed to update location.'));
                    fetchLocations();
                }
            }).fail(function() {
                alert('Failed to update location.');
                fetchLocations();
            });
        });

        // Archive All Event
        $('#btnPauseAll').on('click', function() {
            if (!confirm('Archive all locations into the catalog?')) return;
            const $btn = $(this);
            $btn.prop('disabled', true).text('⌛ Archiving All...');
            
            $.post('/api', { action: 'pause_all_theatres', csrf_token: csrfToken }, function(res) {
                alert(res.message || 'All locations archived.');
                fetchLocations();
                $btn.prop('disabled', false).text('📦 Archive All Locations');
            }).fail(function() {
                alert('Failed to archive locations.');
                $btn.prop('disabled', false).text('📦 Archive All Locations');
            });
        });

        // Filter events
        $(document).on('click', '.prov-btn', function() {
            $('.prov-btn').removeClass('active').css({ 'background': 'rgba(255,255,255,0.05)', 'color': 'var(--text-secondary)' });
            $(this).addClass('active').css({ 'background': 'var(--theme-primary, #3b82f6)', 'color': '#fff' });
            currentProv = $(this).data('prov');
            renderGrid();
        });

        $('#locSearchInput, #locStatusFilter').on('input change', function() {
            renderGrid();
        });

        $(document).ready(function() {
            fetchLocations();
        });
    </script>
    <script src="/assets/js/shared.js?v=<?php echo time(); ?>"></script>
    <script src="/assets/js/design-options-modal.js?v=<?php echo time(); ?>"></script>
</body>
</html>
