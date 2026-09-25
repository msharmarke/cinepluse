<?php
/**
 * Cinepulse — Admin Login Portal
 */

require_once dirname(dirname(__DIR__)) . '/src/Autoloader.php';

use Cinepulse\Security;

Security::startSession();

// If already authenticated, redirect to admin dashboard
if (Security::isAdminAuthenticated()) {
    $redirect = $_GET['redirect'] ?? '/admin/dashboard';
    header('Location: ' . $redirect);
    exit;
}

$error = '';
$redirect = Security::sanitizeInput($_GET['redirect'] ?? '/admin/dashboard', 'string');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrfOrDie();
    $password = $_POST['password'] ?? '';

    if (Security::verifyAdminPassword($password)) {
        Security::loginAdmin();
        header('Location: ' . ($redirect ?: '/admin/dashboard'));
        exit;
    } else {
        $error = 'Invalid admin password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo Security::csrfMeta(); ?>
    <title>🔐 Admin Authentication — Cinepulse Command Center</title>
    
    <!-- Open Graph & Social Meta -->
    <meta property="og:title" content="🔐 Admin Authentication — Cinepulse Command Center">
    <meta property="og:description" content="Secure admin login portal for Cinepulse analytics, seating telemetry, and system controls.">
    <meta property="og:image" content="/assets/images/share/command-center.jpg">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="/assets/css/design-options-modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Outfit', sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary, #0b0f19);
            color: var(--text-primary, #ffffff);
            position: relative;
            overflow: hidden;
        }

        /* Ambient Glow Background Accents */
        body::before {
            content: '';
            position: absolute;
            top: -20%;
            left: -10%;
            width: 50%;
            height: 50%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(0,0,0,0) 70%);
            z-index: 0;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            bottom: -20%;
            right: -10%;
            width: 50%;
            height: 50%;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.15) 0%, rgba(0,0,0,0) 70%);
            z-index: 0;
            pointer-events: none;
        }

        .login-card-container {
            width: 100%;
            max-width: 420px;
            padding: 2.5rem 2rem;
            margin: 1rem;
            background: var(--bg-card, rgba(17, 24, 39, 0.85));
            border: 1px solid var(--border-medium, rgba(255, 255, 255, 0.12));
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 1;
            position: relative;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header h1 {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0 0 0.5rem 0;
            letter-spacing: -0.02em;
            background: var(--gradient-primary, linear-gradient(135deg, #3b82f6, #8b5cf6));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .login-header p {
            color: var(--text-muted, rgba(255, 255, 255, 0.6));
            font-size: 0.9rem;
            margin: 0;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            color: var(--text-secondary, #d1d5db);
        }

        .input-text {
            width: 100%;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-light, rgba(255, 255, 255, 0.15));
            color: #ffffff;
            font-size: 1rem;
            font-family: inherit;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }

        .input-text:focus {
            outline: none;
            border-color: var(--theme-primary, #3b82f6);
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.3);
        }

        .btn-login {
            width: 100%;
            padding: 0.9rem;
            border-radius: 10px;
            border: none;
            background: var(--theme-primary, #3b82f6);
            color: white;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }

        .btn-login:hover {
            opacity: 0.95;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.6);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #ef4444;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.88rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .login-footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted, rgba(255, 255, 255, 0.5));
        }

        .login-footer a {
            color: var(--theme-primary, #3b82f6);
            text-decoration: none;
            font-weight: 600;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="login-card-container">
        <div class="login-header">
            <h1>🎬 Cinepulse</h1>
            <p>Admin Command Center Login</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error">
                <span>⚠</span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="/admin/login?redirect=<?php echo urlencode($redirect); ?>">
            <?php echo Security::csrfField(); ?>
            
            <div class="form-group">
                <label for="password" class="form-label">Admin Passcode</label>
                <input type="password" id="password" name="password" class="input-text" placeholder="Enter admin password..." required autofocus>
            </div>

            <button type="submit" class="btn-login">
                <span>🔐</span>
                <span>Authenticate & Access</span>
            </button>
        </form>

        <div class="login-footer">
            <p>Default Passcode: <code>admin</code> (Configurable in config.ini)</p>
            <p style="margin-top: 1rem;"><a href="/schedule">← Return to Public Schedule</a></p>
        </div>
    </div>

    <script src="/assets/js/shared.js"></script>
</body>
</html>
