<?php
/**
 * Cinepulse — Digital Moviegoer Passport & Badges
 * Shows movie check-in stamps, 70mm IMAX badges, and theater stats.
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\Security;
use Cinepulse\PassportService;
use Cinepulse\GoogleAuthService;

Security::startSession();
$currentUser = GoogleAuthService::getCurrentUser();
$userId = $currentUser['id'] ?? 1;
$passport = PassportService::getUserPassport($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏅 Moviegoer Passport — Cinepulse</title>
    <meta name="description" content="Your personal digital cinephile passport with movie check-in stamps, theater stats, and achievement badges.">
    <link rel="stylesheet" href="/assets/css/main.css?v=20261003">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #090B10;
            --card-bg: #131722;
            --accent-gold: #FFD700;
            --text-light: #F0F4F8;
            --border-glow: rgba(255, 215, 0, 0.25);
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
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }
        .passport-header {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.1) 0%, rgba(20, 24, 38, 0.9) 100%);
            border: 1px solid var(--border-glow);
            border-radius: 20px;
            padding: 2rem;
            display: flex;
            gap: 1.5rem;
            align-items: center;
            margin-bottom: 2rem;
        }
        .avatar-lg {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 3px solid var(--accent-gold);
            object-fit: cover;
        }
        .user-meta h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.75rem;
            font-weight: 800;
        }
        .user-bio {
            color: #A0AEC0;
            margin: 0 0 0.75rem 0;
            font-size: 0.95rem;
        }
        .stats-pills {
            display: flex;
            gap: 1rem;
        }
        .stat-pill {
            background: rgba(255, 255, 255, 0.06);
            padding: 0.35rem 0.85rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--accent-gold);
        }
        .section-title {
            font-size: 1.25rem;
            font-weight: 800;
            margin: 2rem 0 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .badges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
        }
        .badge-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1rem;
            display: flex;
            gap: 0.85rem;
            align-items: center;
        }
        .badge-icon {
            font-size: 2rem;
        }
        .badge-info h4 {
            margin: 0 0 0.2rem 0;
            font-size: 0.95rem;
            color: #FFF;
        }
        .badge-info p {
            margin: 0;
            font-size: 0.78rem;
            color: #718096;
        }
        .stamps-list {
            display: grid;
            gap: 1rem;
        }
        .stamp-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stamp-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0 0 0.3rem 0;
        }
        .stamp-sub {
            color: #A0AEC0;
            font-size: 0.88rem;
        }
        .stamp-rating {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--accent-gold);
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
            <a href="/velocity" class="nav-link">🔥 Velocity</a>
            <a href="/passport" class="nav-link active">🏅 Passport</a>
            <?php if ($currentUser): ?>
                <a href="/passport" class="nav-link" style="color:#FFD700;">
                    <img src="<?= htmlspecialchars($currentUser['avatar_url'] ?: 'https://lh3.googleusercontent.com/a/default-user') ?>" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:6px;">
                    <?= htmlspecialchars($currentUser['display_name']) ?>
                </a>
            <?php else: ?>
                <a href="/api?action=get_google_auth_url" class="btn-google">
                    <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                    Sign in with Google
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        <div class="passport-header">
            <img src="<?= htmlspecialchars($passport['avatar_url'] ?: 'https://lh3.googleusercontent.com/a/default-user') ?>" class="avatar-lg" alt="Avatar">
            <div class="user-meta">
                <h1><?= htmlspecialchars($passport['display_name']) ?></h1>
                <p class="user-bio"><?= htmlspecialchars($passport['bio'] ?: 'Movie enthusiast') ?></p>
                <div class="stats-pills">
                    <div class="stat-pill">🎟️ <?= (int)$passport['movies_watched_count'] ?> Movies Watched</div>
                    <div class="stat-pill">📍 <?= htmlspecialchars($passport['favorite_theatre_name'] ?: 'Scotiabank Toronto') ?></div>
                </div>
            </div>
        </div>

        <h2 class="section-title">🏆 Unlocked Badges</h2>
        <div class="badges-grid">
            <?php foreach ($passport['badges'] as $badge): ?>
                <div class="badge-card">
                    <div class="badge-icon"><?= $badge['icon'] ?></div>
                    <div class="badge-info">
                        <h4><?= htmlspecialchars($badge['title']) ?></h4>
                        <p><?= htmlspecialchars($badge['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 class="section-title">🎟️ Movie Check-In Stamps</h2>
        <div class="stamps-list">
            <?php foreach ($passport['stamps'] as $stamp): ?>
                <div class="stamp-card">
                    <div>
                        <div class="stamp-title"><?= htmlspecialchars($stamp['movie_title']) ?></div>
                        <div class="stamp-sub">
                            📍 <?= htmlspecialchars($stamp['theatre_name']) ?> • 🕒 <?= htmlspecialchars($stamp['screening_date']) ?> • <?= htmlspecialchars($stamp['format_type']) ?> (<?= htmlspecialchars($stamp['seat_label']) ?>)
                        </div>
                    </div>
                    <div class="stamp-rating">★ <?= number_format($stamp['rating'], 1) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</body>
</html>
