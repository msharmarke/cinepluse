<?php
/**
 * Cinepulse — User Profile & Digital Moviegoer Passport
 * Manages user identity, favorite theater preference, ticket check-in stamps, and sign-out.
 * Fully wired into Cinepulse Dark Design System with self-contained fallback styling.
 */

require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\Security;
use Cinepulse\PassportService;
use Cinepulse\GoogleAuthService;
use Cinepulse\ShowtimeService;

Security::startSession();

// Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    GoogleAuthService::logout();
    header("Location: /schedule");
    exit;
}

// Handle Form Submission for Adding Stamp
$stamp_msg = '';
$currentUser = GoogleAuthService::getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stamp']) && $currentUser) {
    $result = PassportService::addStamp($currentUser['id'], $_POST);
    if ($result) {
        $stamp_msg = "🎟️ Movie ticket stamp logged successfully to your passport!";
    } else {
        $stamp_msg = "❌ Failed to log stamp. Please enter a valid movie title.";
    }
}

$userId = $currentUser['id'] ?? 1;
$passport = PassportService::getUserPassport($userId);
$locations = ShowtimeService::getTrackerTheatres(true);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏅 User Profile & Passport — Cinepulse</title>
    <meta name="description" content="Your personal digital cinephile profile with movie check-in stamps, theater stats, and achievement badges.">
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
        
        .passport-header {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.12) 0%, rgba(31, 41, 55, 0.9) 100%);
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 18px;
            padding: 1.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        .profile-left {
            display: flex;
            gap: 1.25rem;
            align-items: center;
        }
        .avatar-lg {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid #fbbf24;
            object-fit: cover;
        }
        .user-meta h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.6rem;
            font-weight: 800;
            color: #FFF;
        }
        .user-email {
            color: #9ca3af;
            font-size: 0.88rem;
            margin-bottom: 0.4rem;
        }
        .user-bio {
            color: #9ca3af;
            margin: 0 0 0.75rem 0;
            font-size: 0.92rem;
        }
        .stats-pills {
            display: flex;
            gap: 0.85rem;
            flex-wrap: wrap;
        }
        .stat-pill {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid #374151;
            padding: 0.35rem 0.85rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #fbbf24;
        }
        .btn-logout {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.4);
            padding: 0.5rem 1.1rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.88rem;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        .badges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .badge-card {
            background: #1f2937;
            border: 1px solid #374151;
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
            color: #9ca3af;
        }
        .form-card {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #9ca3af;
            margin-bottom: 0.4rem;
        }
        .form-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid #374151;
            padding: 0.6rem;
            border-radius: 8px;
            color: #FFF;
            font-family: inherit;
        }
        .stamp-card {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
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
                <a href="/velocity">🔥 Velocity</a>
                <a href="/passport" class="active">🏅 Passport</a>
                <a href="/movies">🎬 Movies</a>
                <a href="/admin/dashboard">📊 Dashboard</a>
            </nav>
            <div style="padding: 1rem 1.5rem; margin-top: auto; display: flex; flex-direction: column; gap: 0.75rem;">
                <?php if ($currentUser): ?>
                    <a href="/passport?action=logout" class="btn-logout" style="justify-content: center;">🚪 Sign Out</a>
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

            <?php if ($stamp_msg): ?>
                <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid #10B981; padding: 1rem; border-radius: 12px; color: #10B981; margin-bottom: 1.5rem; font-weight: 700;">
                    <?= htmlspecialchars($stamp_msg) ?>
                </div>
            <?php endif; ?>

            <div class="passport-header">
                <div class="profile-left">
                    <img src="<?= htmlspecialchars($passport['avatar_url'] ?: 'https://lh3.googleusercontent.com/a/default-user') ?>" class="avatar-lg" alt="Avatar">
                    <div class="user-meta">
                        <h1><?= htmlspecialchars($passport['display_name']) ?></h1>
                        <?php if (!empty($passport['email'])): ?>
                            <div class="user-email">✉️ <?= htmlspecialchars($passport['email']) ?></div>
                        <?php endif; ?>
                        <p class="user-bio"><?= htmlspecialchars($passport['bio'] ?: 'Avid moviegoer & cinephile') ?></p>
                        <div class="stats-pills">
                            <div class="stat-pill">🎟️ <?= (int)$passport['movies_watched_count'] ?> Stamps Logged</div>
                            <div class="stat-pill">📍 Favorite: <?= htmlspecialchars($passport['favorite_theatre_name'] ?: 'Scotiabank Toronto') ?></div>
                        </div>
                    </div>
                </div>
                <div>
                    <?php if ($currentUser): ?>
                        <a href="/passport?action=logout" class="btn-logout">🚪 Sign Out</a>
                    <?php else: ?>
                        <a href="/api?action=login_google" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; background: #fbbf24; color: #000; font-weight: 800; padding: 0.6rem 1.1rem; border-radius: 10px; text-decoration: none; font-size: 0.88rem;">🔑 Sign In with Google</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add Ticket Stamp Section -->
            <div class="form-card">
                <h3 style="margin: 0 0 1rem 0; font-size: 1.15rem; font-weight: 800; color:#FFF;">➕ Log a Movie Ticket Check-In Stamp</h3>
                <form method="POST" action="/passport">
                    <input type="hidden" name="add_stamp" value="1">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Movie Title</label>
                            <input type="text" name="movie_title" class="form-input" placeholder="e.g. Interstellar" required>
                        </div>
                        <div class="form-group">
                            <label>Cinema / Theater Location</label>
                            <select name="theatre_name" class="form-input">
                                <?php foreach ($locations as $tName => $tId): ?>
                                    <option value="<?= htmlspecialchars($tName) ?>"><?= htmlspecialchars($tName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Screening Format</label>
                            <select name="format_type" class="form-input">
                                <option value="IMAX 70mm">IMAX 70mm</option>
                                <option value="IMAX Laser">IMAX Laser</option>
                                <option value="UltraAVX">UltraAVX</option>
                                <option value="VIP Cinema">VIP Cinema</option>
                                <option value="Standard">Standard</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Seat Label</label>
                            <input type="text" name="seat_label" class="form-input" placeholder="e.g. Row G, Seat 14">
                        </div>
                        <div class="form-group">
                            <label>Rating (1.0 - 5.0)</label>
                            <input type="number" step="0.1" min="1" max="5" name="rating" class="form-input" value="5.0">
                        </div>
                    </div>
                    <div style="margin-top: 1rem; text-align: right;">
                        <button type="submit" style="background: #fbbf24; color: #000; font-weight: 800; border: none; cursor: pointer; padding: 0.65rem 1.25rem; border-radius: 9px; font-family: inherit;">🎟️ Log Ticket Stamp</button>
                    </div>
                </form>
            </div>

            <h2 style="font-size: 1.25rem; font-weight: 800; margin: 2rem 0 1rem 0; color:#FFF;">🏆 Unlocked Badges</h2>
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

            <h2 style="font-size: 1.25rem; font-weight: 800; margin: 2rem 0 1rem 0; color:#FFF;">🎟️ Movie Check-In Stamps History</h2>
            <div class="stamps-list">
                <?php foreach ($passport['stamps'] as $stamp): ?>
                    <div class="stamp-card">
                        <div>
                            <div style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.3rem; color:#FFF;"><?= htmlspecialchars($stamp['movie_title']) ?></div>
                            <div style="color: #9ca3af; font-size: 0.88rem;">
                                📍 <?= htmlspecialchars($stamp['theatre_name']) ?> • 🕒 <?= htmlspecialchars($stamp['screening_date']) ?> • <?= htmlspecialchars($stamp['format_type']) ?> (<?= htmlspecialchars($stamp['seat_label']) ?>)
                            </div>
                        </div>
                        <div style="font-size: 1.2rem; font-weight: 800; color: #fbbf24;">★ <?= number_format($stamp['rating'], 1) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

        </main>
    </div>
</body>
</html>
