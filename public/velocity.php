<?php
/**
 * Cinepulse — Real-Time Seat Velocity Engine ("Hypemeter")
 * Dynamic leaderboard tracking the fastest-filling showtimes across Canada (<1ms).
 * Fully wired into Cinepulse Dark Design System with self-contained fallback styling.
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\Security;
use Cinepulse\VelocityService;
use Cinepulse\GoogleAuthService;
use Cinepulse\ShowtimeService;

Security::startSession();
$currentUser = GoogleAuthService::getCurrentUser();

// Get active locations for filter
$locations = ShowtimeService::getTrackerTheatres(true);
$selected_theatre_id = Security::sanitizeInput($_GET['theatre_id'] ?? null, 'int');

$showtimes = VelocityService::getTopVelocityShowtimes(20, $selected_theatre_id);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔥 Real-Time Seat Velocity — Cinepulse</title>
    <meta name="description" content="Live occupancy velocity leaderboard ranking the fastest filling movie showtimes across Canadian cinemas in real-time.">
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

        .velocity-card {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            transition: border-color 0.2s, transform 0.2s;
        }
        .velocity-card:hover {
            border-color: #f59e0b;
            transform: translateY(-2px);
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
            
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 2rem; background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(31, 41, 55, 0.8) 100%); padding: 1.75rem; border-radius: 16px; border: 1px solid rgba(245, 158, 11, 0.3);">
                <div>
                    <h1 style="margin: 0; font-size: 1.75rem; font-weight: 800; color:#FFF;">🔥 Real-Time Seat Velocity Engine</h1>
                    <p style="margin: 0.3rem 0 0 0; color: #9ca3af; font-size: 0.95rem;">Live occupancy leaderboard ranking the fastest-selling movie showtimes across Canadian cinemas.</p>
                </div>
                
                <form method="GET" action="/velocity">
                    <select name="theatre_id" onchange="this.form.submit()" style="background: rgba(0, 0, 0, 0.5); border: 1px solid rgba(245, 158, 11, 0.5); color: #FFF; padding: 0.65rem 1.1rem; border-radius: 10px; font-family: inherit; font-size: 0.9rem; font-weight: 700; cursor: pointer;">
                        <option value="">📍 All Cinema Locations</option>
                        <?php foreach ($locations as $tName => $tId): ?>
                            <option value="<?= $tId ?>" <?= $selected_theatre_id == $tId ? 'selected' : '' ?>>
                                📍 <?= htmlspecialchars($tName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="velocity-list">
                <?php if (empty($showtimes)): ?>
                    <div style="background: #1f2937; padding: 2rem; border-radius: 14px; text-align: center; color: #9ca3af;">
                        No showtimes currently tracked for this cinema location.
                    </div>
                <?php endif; ?>
                <?php foreach ($showtimes as $index => $item): ?>
                    <div class="velocity-card">
                        <div>
                            <h3 style="margin: 0 0 0.35rem 0; font-size: 1.15rem; font-weight: 700; color:#FFF;">#<?= $index + 1 ?> <?= htmlspecialchars($item['movie_title']) ?></h3>
                            <div style="color: #9ca3af; font-size: 0.88rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                                <span>📍 <?= htmlspecialchars($item['theatre_name']) ?></span>
                                <span>🕒 <?= date('h:i A', strtotime($item['showtime_start'])) ?></span>
                                <span class="badge-status status-<?= htmlspecialchars($item['velocity_status']) ?>">
                                    <?= strtoupper(str_replace('_', ' ', $item['velocity_status'])) ?>
                                </span>
                            </div>
                        </div>
                        <div style="text-align: right; min-width: 130px;">
                            <div style="font-size: 1.6rem; font-weight: 800; color: #f59e0b;"><?= number_format($item['occupancy_pct'], 1) ?>%</div>
                            <div style="font-size: 0.8rem; color: #9ca3af;">⚡ +<?= number_format($item['fill_rate_seats_per_hour'], 1) ?> seats/hr</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </main>
    </div>
</body>
</html>
