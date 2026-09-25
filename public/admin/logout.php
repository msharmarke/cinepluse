<?php
/**
 * Cinepulse — Admin Logout Script
 */

require_once dirname(dirname(__DIR__)) . '/src/Autoloader.php';

use Cinepulse\Security;

Security::logoutAdmin();
header('Location: /admin/login');
exit;
