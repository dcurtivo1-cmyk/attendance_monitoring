<?php
// config/database.php

// Session settings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Credentials (support environment variables for cloud deployment such as Vercel/Railway, fallback to local XAMPP)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'attendance_db');
define('DB_PORT', getenv('DB_PORT') ? intval(getenv('DB_PORT')) : 3306);

// Establish PDO Connection
require_once __DIR__ . '/schema_setup.php';

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
            ensure_system_schema($pdo);
        } catch (PDOException $e) {
            die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

// Base URL helper
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    
    // Find project root directory relative to current script
    $root = rtrim($scriptDir, '/');
    if (strpos($root, '/student') !== false) {
        $root = dirname($root);
    } elseif (strpos($root, '/professor') !== false) {
        $root = dirname($root);
    } elseif (strpos($root, '/admin') !== false) {
        $root = dirname($root);
    } elseif (strpos($root, '/api') !== false) {
        $root = dirname($root);
    } elseif (strpos($root, '/config') !== false) {
        $root = dirname($root);
    }
    $root = rtrim($root, '/');
    
    return $protocol . $host . $root . '/' . ltrim($path, '/');
}
