<?php
namespace Cinepulse;

/**
 * Security & Sanitization Helper Class
 */
class Security {
    
    /**
     * Start session safely before any output
     */
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    /**
     * Generate or return active CSRF token
     * 
     * @return string
     */
    public static function getCsrfToken() {
        self::startSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate matching CSRF token
     * 
     * @param string $token
     * @return bool
     */
    public static function validateCsrfToken($token) {
        self::startSession();
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate HTML input tag for CSRF
     * 
     * @return string
     */
    public static function csrfField() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::getCsrfToken()) . '">';
    }

    /**
     * Generate HTML meta header tag for CSRF
     * 
     * @return string
     */
    public static function csrfMeta() {
        return '<meta name="csrf-token" content="' . htmlspecialchars(self::getCsrfToken()) . '">';
    }

    /**
     * Verify incoming requests token and return JSON payload on failure
     */
    public static function verifyCsrfOrDie() {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$token || !self::validateCsrfToken($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Invalid or missing CSRF token. Please reload and try again.']));
        }
    }

    /**
     * Validate and sanitize input values
     * 
     * @param mixed $input
     * @param string $type ('int', 'string', 'date')
     * @param mixed $default
     * @return mixed
     */
    public static function sanitizeInput($input, $type = 'string', $default = null) {
        if ($input === null) return $default;
        
        switch ($type) {
            case 'int':
                $val = filter_var($input, FILTER_VALIDATE_INT);
                return $val !== false ? $val : $default;
                
            case 'date':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
                    $timestamp = strtotime($input);
                    if ($timestamp !== false) {
                        return date('Y-m-d', $timestamp);
                    }
                }
                return $default;
                
            case 'string':
            default:
                if (is_array($input)) return $default;
                $val = trim($input);
                $val = strip_tags($val);
                $val = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
                return !empty($val) ? $val : $default;
        }
    }

    /**
     * Check if current session has admin authentication
     * 
     * @return bool
     */
    public static function isAdminAuthenticated() {
        self::startSession();
        return !empty($_SESSION['admin_authenticated']);
    }

    /**
     * Enforce admin authentication, redirecting to /admin/login if unauthenticated
     */
    public static function requireAdmin() {
        self::startSession();
        if (!self::isAdminAuthenticated()) {
            $target = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard';
            header('Location: /admin/login?redirect=' . urlencode($target));
            exit;
        }
    }

    /**
     * Verify submitted admin password against config.ini setting or default
     * 
     * @param string $inputPassword
     * @return bool
     */
    public static function verifyAdminPassword($inputPassword) {
        if (empty($inputPassword)) return false;

        $adminPass = getenv('ADMIN_PASSWORD');
        if (!$adminPass) {
            $configFile = dirname(__DIR__) . '/config/config.ini';
            if (file_exists($configFile)) {
                $ini = parse_ini_file($configFile, true);
                if (isset($ini['admin']['password']) && !empty($ini['admin']['password'])) {
                    $adminPass = $ini['admin']['password'];
                }
            }
        }
        if (!$adminPass) {
            $adminPass = 'admin'; // Default password
        }

        return hash_equals($adminPass, $inputPassword) || password_verify($inputPassword, $adminPass);
    }

    /**
     * Grant admin authentication session
     */
    public static function loginAdmin() {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_login_time'] = time();
    }

    /**
     * Revoke admin authentication session
     */
    public static function logoutAdmin() {
        self::startSession();
        unset($_SESSION['admin_authenticated']);
        unset($_SESSION['admin_login_time']);
        session_destroy();
    }
}
