<?php
// config/database.php
require_once __DIR__ . '/school_time.php';

// Session settings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Credentials (use 127.0.0.1 to avoid Windows IPv6 DNS lookup timeout)
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'attendance_db');
define('DB_PORT', 3306);

// Establish PDO Connection
require_once __DIR__ . '/schema_setup.php';
require_once __DIR__ . '/automatic_attendance.php';

function get_db_connection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";port=" . DB_PORT . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Numeric offset works even when MySQL timezone tables are not installed.
            $pdo->exec("SET time_zone = '+08:00'");
            ensure_system_schema($pdo);
            ensure_automatic_attendance_schema($pdo);
            mark_finished_classes_absent($pdo);
            resume_automatic_attendance_scheduler($pdo);
        } catch (PDOException $e) {
            die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

// Base URL helper
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    
    if (isset($_SERVER['SCRIPT_NAME']) && php_sapi_name() !== 'cli') {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $parts = explode('/', trim($scriptDir, '/'));
        $validParts = [];
        foreach ($parts as $part) {
            if (in_array($part, ['student', 'professor', 'admin', 'api', 'config', 'tests', 'storage'])) {
                break;
            }
            if ($part !== '') {
                $validParts[] = $part;
            }
        }
        $root = !empty($validParts) ? '/' . implode('/', $validParts) : '';
    } else {
        $root = '/attendance_monitoring';
    }
    
    return $protocol . $host . $root . '/' . ltrim($path, '/');
}
