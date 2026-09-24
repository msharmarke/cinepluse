<?php
namespace Cinepulse;

use PDO;
use Exception;

/**
 * Singleton Database Manager
 * Instantiates and maintains the PDO connection.
 */
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $config_file = dirname(__DIR__) . '/config/config.ini';
        $db_host = getenv('DB_HOST') ?: 'localhost';
        $db_name = getenv('DB_NAME');
        $db_user = getenv('DB_USER');
        $db_pass = getenv('DB_PASS');

        // Fallback to local config.ini settings
        if (file_exists($config_file)) {
            $config = parse_ini_file($config_file, true);
            if (isset($config['database'])) {
                $db_host = $config['database']['host'] ?? $db_host;
                $db_name = $config['database']['name'] ?? $db_name;
                $db_user = $config['database']['user'] ?? $db_user;
                $db_pass = $config['database']['pass'] ?? $db_pass;
            }
        }

        if (!$db_name || !$db_user || !$db_pass) {
            throw new Exception("Database configurations (host, name, user, pass) are missing. Please complete config.ini setup.");
        }

        $this->pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Match MySQL session time offset with America/Toronto
        $this->pdo->exec("SET time_zone = '-05:00'");
    }

    /**
     * Get Database instance
     * 
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get PDO Database Connection
     * 
     * @return PDO
     */
    public function getConnection() {
        return $this->pdo;
    }
}
