<?php
/**
 * Cinepulse — Real-Time Seat Velocity Engine ("Hypemeter")
 * Leaderboard ranking movies per approved theater from most full to least full.
 * Off toggle by default for real-time stream auto-polling.
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\Security;
use Cinepulse\VelocityService;
use Cinepulse\GoogleAuthService;
use Cinepulse\ShowtimeService;

Security::startSession();
$currentUser = GoogleAuthService::getCurrentUser();

// Get active approved locations only (3 approved cinemas so far)
$locations = ShowtimeService::getTrackerTheatres(true);
$selected_theatre_id = Security::sanitizeInput($_GET['theatre_id'] ?? null, 'int');

$showtimes = VelocityService::getTopVelocityShowtimes(20, $selected_theatre_id);

// Compute top KPIs
$totalVelocityRate = 0;
$topMovie = 'N/A';
$topOccupancy = 0;

if (!empty($showtimes)) {
    foreach ($showtimes as $s) {
        $totalVelocityRate += floatval($s['fill_rate_seats_per_hour'] ?? 0);
    }
    $topMovie = $showtimes[0]['movie_title'] ?? 'N/A';
    $topOccupancy = floatval($showtimes[0]['occupancy_pct'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔥 Real-Time Seat Velocity Engine — Cinepulse</title>
    <meta name="description" content="Live occupancy velocity leaderboard ranking movies per approved cinema from most full to least full.">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261003">
    <link rel="stylesheet" href="/public/assets/css/style.css?v=20261003">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Outfit', sans-serif; background: #0b0e14; color: #e5e7eb; margin: 0; padding: 0; }
        
        .app-container { display: flex; min-height: 100vh; }
        
        .sidebar {
            width: 260px;
            background: #111827;
            border-right: 1px solid #1f2937;
            display: flex;
            flex-direction: column;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            z-index: 50;
        }
        .sidebar-header { padding: 0 1.5rem 1.5rem 1.5rem; border-bottom: 1px solid #1f2937; }
        .sidebar-header h1 { margin: 0; font-size: 1.4rem; font-weight: 800; color: #FFF; }
        .sidebar-header p { margin: 0.2rem 0 0 0; font-size: 0.78rem; color: #9ca3af; }
        
        .sidebar-nav { display: flex; flex-direction: column; gap: 0.35rem; padding: 1rem 0.85rem; }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.65rem 0.85rem;
            color: #9ca3af;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
        }

        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 2rem;
            max-width: 1200px;
        }

        .top-status-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(31, 41, 55, 0.6);
            border: 1px solid rgba(156, 163, 175, 0.3);
            color: #9ca3af;
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 800;
            transition: all 0.3s ease;
        }
        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #ef4444;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        .pause-dot {
            width: 8px;
            height: 8px;
            background-color: #9ca3af;
            border-radius: 50%;
        }
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        /* Stream Toggle Switch Styling */
        .switch-container {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #374151;
            transition: .3s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #ef4444;
        }
        input:checked + .slider:before {
            transform: translateX(20px);
        }

        .sync-counter-badge {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #60a5fa;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.4rem 0.85rem;
            border-radius: 20px;
            display: none;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .kpi-card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .kpi-label { font-size: 0.8rem; color: #9ca3af; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .kpi-val { font-size: 1.6rem; font-weight: 800; color: #FFF; }
        .kpi-sub { font-size: 0.78rem; color: #6b7280; }

        .velocity-card {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .velocity-card:hover {
            border-color: #f59e0b;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.15);
        }
        .velocity-card.flash-update {
            animation: cardFlash 0.8s ease;
        }
        @keyframes cardFlash {
            0% { border-color: #f59e0b; box-shadow: 0 0 15px rgba(245, 158, 11, 0.5); }
            100% { border-color: #374151; box-shadow: none; }
        }

        .velocity-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        
        .badge-screen {
            background: rgba(147, 51, 234, 0.2);
            color: #c084fc;
            border: 1px solid rgba(147, 51, 234, 0.4);
            font-size: 0.72rem;
            font-weight: 800;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
        }

        .badge-status {
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-nearly_full { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b; }
        .status-selling_fast { background: rgba(96, 165, 250, 0.2); color: #60a5fa; border: 1px solid #60a5fa; }
        .status-sold_out { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #ef4444; }

        .progress-bar-bg {
            width: 100%;
            height: 9px;
            background: rgba(0, 0, 0, 0.4);
            border-radius: 5px;
            overflow: hidden;
            margin-top: 0.85rem;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #60a5fa 0%, #f59e0b 70%, #ef4444 100%);
            border-radius: 5px;
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .seats-avail-badge {
            font-size: 0.8rem;
            color: #9ca3af;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .btn-sync {
            background: rgba(245, 158, 11, 0.2);
            border: 1px solid #f59e0b;
            color: #f59e0b;
            font-weight: 800;
            font-size: 0.85rem;
            padding: 0.65rem 1.1rem;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-sync:hover {
            background: #f59e0b;
            color: #111827;
        }

        @media (max-width: 768px) {
            .app-container { flex-direction: column; }
            .sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; padding: 1rem; }
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
                <a href="/velocity" class="active">🔥 Velocity</a>
                <a href="/passport">🏅 Passport</a>
                <a href="/movies">🎬 Movies</a>
                <a href="/admin/dashboard">📊 Dashboard</a>
            </nav>
            <div style="padding: 1rem 1.5rem; margin-top: auto; display: flex; flex-direction: column; gap: 0.75rem;">
                <?php if ($currentUser): ?>
                    <a href="/passport" style="display: flex; align-items: center; gap: 0.6rem; background: rgba(255, 215, 0, 0.12); border: 1px solid rgba(255, 215, 0, 0.3); padding: 0.5rem 0.85rem; border-radius: 10px; color: #FFD700; text-decoration: none; font-weight: 700; font-size: 0.88rem;">
                        <img src="<?= htmlspecialchars($currentUser['avatar_url'] ?: 'https://lh3.googleusercontent.com/a/default-user') ?>" style="width:24px;height:24px;border-radius:50%;object-fit:cover;">
                        <span><?= htmlspecialchars($currentUser['display_name']) ?></span>
                    </a>
                <?php else: ?>
                    <a href="/api?action=login_google" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; background: #FFF; color: #1A202C; font-weight: 800; padding: 0.6rem; border-radius: 10px; text-decoration: none; font-size: 0.85rem; box-shadow: 0 4px 12px rgba(255,255,255,0.15);">
                        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                        Sign in with Google
                    </a>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">

            <div class="top-status-header">
                <div class="live-indicator" id="liveStatusBadge">
                    <span class="pause-dot"></span>
                    <span>STREAM PAUSED — MANUAL REFRESH MODE</span>
                </div>
                
                <div style="display:flex; align-items:center; gap:1rem;">
                    <!-- Off Toggle Switch by Default -->
                    <div class="switch-container">
                        <span style="font-size:0.82rem; font-weight:800; color:#9ca3af;">⚡ Live Stream Polling:</span>
                        <label class="switch">
                            <input type="checkbox" id="streamToggleSwitch" onchange="toggleAutoStream(this.checked)">
                            <span class="slider"></span>
                        </label>
                        <span id="streamStatusLabel" style="font-size:0.8rem; font-weight:800; color:#9ca3af;">OFF</span>
                    </div>

                    <div class="sync-counter-badge" id="syncCounter">
                        ⚡ Auto-Sync in: <span id="countdownSec">5</span>s
                    </div>
                </div>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(31, 41, 55, 0.8) 100%); padding: 1.75rem; border-radius: 16px; border: 1px solid rgba(245, 158, 11, 0.3);">
                <div>
                    <h1 style="margin: 0; font-size: 1.75rem; font-weight: 800; color:#FFF;">🔥 Real-Time Seat Velocity Leaderboard</h1>
                    <p style="margin: 0.3rem 0 0 0; color: #9ca3af; font-size: 0.95rem;">Ranking movies per approved cinema from most full to least full in real-time.</p>
                </div>
                
                <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                    <button class="btn-sync" onclick="pollVelocityData(true)">
                        ⚡ Refresh Leaderboard Now
                    </button>
                    <form method="GET" action="/velocity" id="filterForm">
                        <select name="theatre_id" id="theatreSelect" onchange="this.form.submit()" style="background: rgba(0, 0, 0, 0.5); border: 1px solid rgba(245, 158, 11, 0.5); color: #FFF; padding: 0.65rem 1.1rem; border-radius: 10px; font-family: inherit; font-size: 0.9rem; font-weight: 700; cursor: pointer;">
                            <option value="">📍 Approved Cinema Locations (<?= count($locations) ?>)</option>
                            <?php foreach ($locations as $tName => $tId): ?>
                                <option value="<?= $tId ?>" <?= $selected_theatre_id == $tId ? 'selected' : '' ?>>
                                    📍 <?= htmlspecialchars($tName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <!-- Real-Time System KPI Summary Grid -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <span class="kpi-label">⚡ System Velocity Rate</span>
                    <span class="kpi-val" style="color:#f59e0b;" id="kpiVelocity"><?= number_format($totalVelocityRate, 1) ?> seats/hr</span>
                    <span class="kpi-sub">Aggregate ticket sales velocity across approved venues</span>
                </div>
                <div class="kpi-card">
                    <span class="kpi-label">🔥 #1 Most Full Showtime</span>
                    <span class="kpi-val" style="font-size:1.15rem; color:#60a5fa;" id="kpiTopMovie"><?= htmlspecialchars($topMovie) ?></span>
                    <span class="kpi-sub" id="kpiTopOccupancy">Occupancy: <?= number_format($topOccupancy, 1) ?>%</span>
                </div>
                <div class="kpi-card">
                    <span class="kpi-label">🏛️ Active Scope</span>
                    <span class="kpi-val" style="font-size:1.1rem; color:#10b981;">🟢 <?= count($locations) ?> Approved Cinemas</span>
                    <span class="kpi-sub" id="kpiLastSync">Last update: <?= date('H:i:s') ?></span>
                </div>
            </div>

            <!-- Leaderboard Rankings -->
            <div class="velocity-list" id="velocityContainer">
                <?php if (empty($showtimes)): ?>
                    <div style="background: #1f2937; padding: 2rem; border-radius: 14px; text-align: center; color: #9ca3af;">
                        No showtimes currently tracked for this cinema location.
                    </div>
                <?php endif; ?>
                <?php foreach ($showtimes as $index => $item): ?>
                    <div class="velocity-card" id="card-<?= htmlspecialchars($item['showtime_id']) ?>">
                        <div class="velocity-card-header">
                            <div>
                                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom: 0.35rem;">
                                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color:#FFF;">#<?= $index + 1 ?> <?= htmlspecialchars($item['movie_title']) ?></h3>
                                    <?php if (!empty($item['screen_type'])): ?>
                                        <span class="badge-screen"><?= htmlspecialchars($item['screen_type']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="color: #9ca3af; font-size: 0.88rem; display: flex; gap: 0.85rem; align-items: center; flex-wrap: wrap;">
                                    <span>📍 <?= htmlspecialchars($item['theatre_name']) ?></span>
                                    <span>🕒 <?= date('h:i A', strtotime($item['showtime_start'])) ?></span>
                                    <span class="badge-status status-<?= htmlspecialchars($item['velocity_status']) ?>">
                                        <?= strtoupper(str_replace('_', ' ', $item['velocity_status'])) ?>
                                    </span>
                                    <span class="seats-avail-badge">
                                        🪑 <?= isset($item['available_seats']) ? (int)$item['available_seats'] : 0 ?> / <?= isset($item['total_seats']) ? (int)$item['total_seats'] : 0 ?> left
                                    </span>
                                </div>
                            </div>
                            <div style="text-align: right; min-width: 130px;">
                                <div style="font-size: 1.6rem; font-weight: 800; color: #f59e0b;" class="val-pct"><?= number_format($item['occupancy_pct'], 1) ?>%</div>
                                <div style="font-size: 0.8rem; color: #9ca3af;" class="val-rate">⚡ +<?= number_format($item['fill_rate_seats_per_hour'], 1) ?> seats/hr</div>
                            </div>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: <?= min(100, max(0, floatval($item['occupancy_pct']))) ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </main>
    </div>

    <!-- Real-Time Velocity JavaScript Controller -->
    <script>
        const selectedTheatreId = "<?= htmlspecialchars($selected_theatre_id ?: '') ?>";
        let isStreamActive = false; // OFF BY DEFAULT!
        let pollInterval = null;
        let countdownInterval = null;
        let countdownTimer = 5;

        document.addEventListener('DOMContentLoaded', () => {
            initStreamToggle();
        });

        function initStreamToggle() {
            const savedState = localStorage.getItem('cinepulse_velocity_stream');
            const checkbox = document.getElementById('streamToggleSwitch');
            
            // OFF by default unless user has explicitly saved 'on'
            if (savedState === 'on') {
                checkbox.checked = true;
                setStreamState(true);
            } else {
                checkbox.checked = false;
                setStreamState(false);
            }
        }

        function toggleAutoStream(enabled) {
            localStorage.setItem('cinepulse_velocity_stream', enabled ? 'on' : 'off');
            setStreamState(enabled);
        }

        function setStreamState(enabled) {
            isStreamActive = enabled;
            const badge = document.getElementById('liveStatusBadge');
            const syncCounter = document.getElementById('syncCounter');
            const statusLabel = document.getElementById('streamStatusLabel');

            if (enabled) {
                if (badge) {
                    badge.innerHTML = `<span class="pulse-dot"></span><span>LIVE REAL-TIME TELEMETRY — AUTO-POLLING (5s)</span>`;
                    badge.style.color = '#ef4444';
                    badge.style.borderColor = 'rgba(239, 68, 68, 0.4)';
                }
                if (statusLabel) {
                    statusLabel.innerText = 'ON';
                    statusLabel.style.color = '#ef4444';
                }
                if (syncCounter) syncCounter.style.display = 'inline-block';

                countdownTimer = 5;
                if (!pollInterval) pollInterval = setInterval(pollVelocityData, 5000);
                if (!countdownInterval) {
                    countdownInterval = setInterval(() => {
                        countdownTimer--;
                        if (countdownTimer < 0) countdownTimer = 5;
                        const el = document.getElementById('countdownSec');
                        if (el) el.innerText = countdownTimer;
                    }, 1000);
                }
            } else {
                if (badge) {
                    badge.innerHTML = `<span class="pause-dot"></span><span>STREAM PAUSED — MANUAL REFRESH MODE</span>`;
                    badge.style.color = '#9ca3af';
                    badge.style.borderColor = 'rgba(156, 163, 175, 0.3)';
                }
                if (statusLabel) {
                    statusLabel.innerText = 'OFF';
                    statusLabel.style.color = '#9ca3af';
                }
                if (syncCounter) syncCounter.style.display = 'none';

                if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
                if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
            }
        }

        async function pollVelocityData(manual = false) {
            try {
                if (manual) countdownTimer = 5;
                const url = `/api?action=fetch_velocity&limit=20` + (selectedTheatreId ? `&theatre_id=${selectedTheatreId}` : '');
                const response = await fetch(url);
                if (!response.ok) return;
                const data = await response.json();
                
                if (data.success && Array.isArray(data.velocity_showtimes)) {
                    renderVelocityCards(data.velocity_showtimes);
                    updateKPIs(data.velocity_showtimes);
                }
            } catch (err) {
                console.warn('Real-time velocity sync fallback:', err);
            }
        }

        function updateKPIs(showtimes) {
            if (!showtimes || showtimes.length === 0) return;

            let totalVelocity = 0;
            showtimes.forEach(s => totalVelocity += parseFloat(s.fill_rate_seats_per_hour || 0));

            const kpiVelocity = document.getElementById('kpiVelocity');
            const kpiTopMovie = document.getElementById('kpiTopMovie');
            const kpiTopOccupancy = document.getElementById('kpiTopOccupancy');
            const kpiLastSync = document.getElementById('kpiLastSync');

            if (kpiVelocity) kpiVelocity.innerText = totalVelocity.toFixed(1) + ' seats/hr';
            if (kpiTopMovie) kpiTopMovie.innerText = showtimes[0].movie_title || 'N/A';
            if (kpiTopOccupancy) kpiTopOccupancy.innerText = 'Occupancy: ' + parseFloat(showtimes[0].occupancy_pct || 0).toFixed(1) + '%';
            if (kpiLastSync) kpiLastSync.innerText = 'Last update: ' + new Date().toLocaleTimeString();
        }

        function renderVelocityCards(showtimes) {
            const container = document.getElementById('velocityContainer');
            if (!showtimes || showtimes.length === 0) {
                container.innerHTML = `<div style="background: #1f2937; padding: 2rem; border-radius: 14px; text-align: center; color: #9ca3af;">No showtimes currently tracked for this cinema location.</div>`;
                return;
            }

            let html = '';
            showtimes.forEach((item, index) => {
                const pct = parseFloat(item.occupancy_pct || 0).toFixed(1);
                const rate = parseFloat(item.fill_rate_seats_per_hour || 0).toFixed(1);
                const status = (item.velocity_status || 'normal').toLowerCase();
                const statusLabel = status.replace('_', ' ').toUpperCase();
                const startTime = new Date(item.showtime_start).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                const screenType = item.screen_type ? `<span class="badge-screen">${escapeHtml(item.screen_type)}</span>` : '';
                const availSeats = parseInt(item.available_seats || 0);
                const totalSeats = parseInt(item.total_seats || 0);

                html += `
                    <div class="velocity-card flash-update" id="card-${item.showtime_id}">
                        <div class="velocity-card-header">
                            <div>
                                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.35rem;">
                                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color:#FFF;">#${index + 1} ${escapeHtml(item.movie_title)}</h3>
                                    ${screenType}
                                </div>
                                <div style="color: #9ca3af; font-size: 0.88rem; display: flex; gap: 0.85rem; align-items: center; flex-wrap: wrap;">
                                    <span>📍 ${escapeHtml(item.theatre_name)}</span>
                                    <span>🕒 ${startTime}</span>
                                    <span class="badge-status status-${status}">${statusLabel}</span>
                                    <span class="seats-avail-badge">🪑 ${availSeats} / ${totalSeats} left</span>
                                </div>
                            </div>
                            <div style="text-align: right; min-width: 130px;">
                                <div style="font-size: 1.6rem; font-weight: 800; color: #f59e0b;" class="val-pct">${pct}%</div>
                                <div style="font-size: 0.8rem; color: #9ca3af;" class="val-rate">⚡ +${rate} seats/hr</div>
                            </div>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: ${Math.min(100, Math.max(0, pct))}%;"></div>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        function escapeHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    </script>
</body>
</html>
