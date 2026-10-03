<?php
/**
 * Cinepulse — Real-Time Seat Velocity Engine ("Hypemeter")
 * Dynamic leaderboard tracking the fastest-filling showtimes across Canada (<1ms).
 * Features cinema location filter.
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\Security;
use Cinepulse\VelocityService;
use Cinepulse\GoogleAuthService;
use Cinepulse\ShowtimeService;

Security::startSession();
$currentUser = GoogleAuthService::getCurrentUser();

// Get theater filter
$locations = ShowtimeService::getTrackerTheatres(true);
$selected_theatre_id = Security::sanitizeInput($_GET['theatre_id'] ?? null, 'int');

$showtimes = VelocityService::getTopVelocityShowtimes(20, $selected_theatre_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔥 Real-Time Seat Velocity — Cinepulse Hypemeter</title>
    <meta name="description" content="Live occupancy velocity leaderboard ranking the fastest filling movie showtimes across Canadian cinemas in real-time.">
    <link rel="stylesheet" href="/assets/css/main.css?v=20261003">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #090B10;
            --card-bg: #131722;
            --accent-fire: #FF4500;
            --accent-gold: #FFD700;
            --text-light: #F0F4F8;
            --border-glow: rgba(255, 69, 0, 0.25);
        }
        body {
            background-color: var(--bg-dark);
            color: var(--text-light);
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
        }
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            background: rgba(19, 23, 34, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: #FFF;
            font-weight: 800;
            font-size: 1.25rem;
        }
        .nav-links {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }
        .nav-link {
            color: #A0AEC0;
            text-decoration: none;
            font-weight: 600;
            padding: 0.5rem 0.85rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .nav-link:hover, .nav-link.active {
            color: #FFF;
            background: rgba(255, 255, 255, 0.08);
        }
        .btn-google {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #FFF;
            color: #1A202C;
            font-weight: 700;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.15);
            transition: transform 0.2s;
        }
        .btn-google:hover { transform: translateY(-2px); }
        .container {
            max-width: 1100px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }
        .hero-banner {
            background: linear-gradient(135deg, rgba(255, 69, 0, 0.15) 0%, rgba(20, 20, 30, 0.8) 100%);
            border: 1px solid var(--border-glow);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        .hero-title {
            font-size: 2rem;
            font-weight: 800;
            margin: 0 0 0.5rem 0;
        }
        .hero-desc {
            color: #A0AEC0;
            margin: 0;
            font-size: 1rem;
        }
        .filter-box select {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 69, 0, 0.5);
            color: #FFF;
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
        }
        .velocity-grid {
            display: grid;
            gap: 1.25rem;
        }
        .velocity-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: border-color 0.2s, transform 0.2s;
        }
        .velocity-card:hover {
            border-color: rgba(255, 69, 0, 0.4);
            transform: translateX(4px);
        }
        .movie-info h3 {
            margin: 0 0 0.35rem 0;
            font-size: 1.15rem;
            font-weight: 700;
        }
        .movie-meta {
            color: #718096;
            font-size: 0.9rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .badge-status {
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-nearly_full { background: rgba(255, 69, 0, 0.2); color: #FF4500; border: 1px solid #FF4500; }
        .status-selling_fast { background: rgba(255, 215, 0, 0.2); color: #FFD700; border: 1px solid #FFD700; }
        .status-sold_out { background: rgba(239, 68, 68, 0.2); color: #EF4444; border: 1px solid #EF4444; }
        .fill-meter {
            text-align: right;
            min-width: 140px;
        }
        .fill-pct {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--accent-fire);
        }
        .fill-rate {
            font-size: 0.8rem;
            color: #A0AEC0;
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <a href="/schedule" class="nav-logo">
            🎬 Cinepulse
        </a>
        <div class="nav-links">
            <a href="/schedule" class="nav-link">📅 Schedule</a>
            <a href="/velocity" class="nav-link active">🔥 Velocity</a>
            <a href="/passport" class="nav-link">🏅 Profile & Passport</a>
            <?php if ($currentUser): ?>
                <a href="/passport" class="nav-link" style="color:#FFD700;">
                    <img src="<?= htmlspecialchars($currentUser['avatar_url'] ?: 'https://lh3.googleusercontent.com/a/default-user') ?>" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:6px;">
                    <?= htmlspecialchars($currentUser['display_name']) ?>
                </a>
            <?php else: ?>
                <a href="/api?action=login_google" class="btn-google">
                    <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                    Sign in with Google
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        <div class="hero-banner">
            <div>
                <h1 class="hero-title">🔥 Real-Time Seat Velocity Engine</h1>
                <p class="hero-desc">Live occupancy leaderboard ranking the fastest-selling showtimes in real-time.</p>
            </div>
            <form method="GET" action="/velocity" class="filter-box">
                <select name="theatre_id" onchange="this.form.submit()">
                    <option value="">📍 All Cinema Locations</option>
                    <?php foreach ($locations as $tName => $tId): ?>
                        <option value="<?= $tId ?>" <?= $selected_theatre_id == $tId ? 'selected' : '' ?>>
                            📍 <?= htmlspecialchars($tName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="velocity-grid">
            <?php if (empty($showtimes)): ?>
                <div style="background: var(--card-bg); padding: 2rem; border-radius: 14px; text-align: center; color: #A0AEC0;">
                    No showtimes currently tracked for this cinema location.
                </div>
            <?php endif; ?>
            <?php foreach ($showtimes as $index => $item): ?>
                <div class="velocity-card">
                    <div class="movie-info">
                        <h3>#<?= $index + 1 ?> <?= htmlspecialchars($item['movie_title']) ?></h3>
                        <div class="movie-meta">
                            <span>📍 <?= htmlspecialchars($item['theatre_name']) ?></span>
                            <span>🕒 <?= date('h:i A', strtotime($item['showtime_start'])) ?></span>
                            <span class="badge-status status-<?= htmlspecialchars($item['velocity_status']) ?>">
                                <?= strtoupper(str_replace('_', ' ', $item['velocity_status'])) ?>
                            </span>
                        </div>
                    </div>
                    <div class="fill-meter">
                        <div class="fill-pct"><?= number_format($item['occupancy_pct'], 1) ?>%</div>
                        <div class="fill-rate">⚡ +<?= number_format($item['fill_rate_seats_per_hour'], 1) ?> seats/hr</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</body>
</html>
