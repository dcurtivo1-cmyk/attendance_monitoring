<?php
// config/database.php

// Session settings (ensure writable path for serverless cloud environments like Vercel)
if (session_status() === PHP_SESSION_NONE) {
    $tmp = sys_get_temp_dir();
    if (is_dir($tmp) && is_writable($tmp)) {
        session_save_path($tmp);
    }
    session_start();
}

// Helper to get environment variable across serverless environments
function get_env_setting($key, $default = '') {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    return $default;
}

// Database Credentials (support environment variables for cloud deployment such as Vercel/Railway, fallback to local XAMPP)
define('DB_HOST', get_env_setting('DB_HOST', '127.0.0.1'));
define('DB_USER', get_env_setting('DB_USER', 'root'));
define('DB_PASS', get_env_setting('DB_PASS', ''));
define('DB_NAME', get_env_setting('DB_NAME', 'attendance_db'));
define('DB_PORT', intval(get_env_setting('DB_PORT', 3306)));

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

        // Enable SSL for remote cloud databases (e.g. TiDB Cloud, Aiven, Clever Cloud)
        if (DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost') {
            $caFile = __DIR__ . '/ca.pem';
            if (file_exists($caFile) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            ensure_system_schema($pdo);
        } catch (PDOException $e) {
            $is_cloud = (DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost');
            if (!$is_cloud) {
                die("<div style='font-family:sans-serif;max-width:600px;margin:50px auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;color:#1e293b;line-height:1.6;'>"
                    . "<h2 style='color:#ef4444;margin-top:0;'>⚠️ Walang Nahanap na Database Server</h2>"
                    . "<p>Sinusubukang mag-connect ng website sa <code>" . htmlspecialchars(DB_HOST) . ":" . DB_PORT . "</code>, ngunit walang MySQL server doon.</p>"
                    . "<hr style='border:none;border-top:1px solid #cbd5e1;margin:16px 0;'>"
                    . "<p><strong>Dahil naka-deploy ito sa Vercel:</strong></p>"
                    . "<ol style='padding-left:20px;'>"
                    . "<li>Kailangan mo ng libreng Cloud MySQL database (tulad ng TiDB Cloud o Aiven).</li>"
                    . "<li>I-save ang mga credentials sa Vercel: <strong>Settings &gt; Environment Variables</strong> (<code>DB_HOST</code>, <code>DB_USER</code>, <code>DB_PASS</code>, <code>DB_NAME</code>, <code>DB_PORT</code>).</li>"
                    . "<li><strong>Importante:</strong> Mag-<strong>Redeploy</strong> sa Vercel pagkatapos i-save ang Environment Variables para mabasa ang mga ito!</li>"
                    . "</ol>"
                    . "</div>");
            } else {
                die("<div style='font-family:sans-serif;max-width:600px;margin:50px auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;color:#1e293b;line-height:1.6;'>"
                    . "<h2 style='color:#ef4444;margin-top:0;'>⚠️ Cloud Database Connection Error</h2>"
                    . "<p>Sinubukang mag-connect sa <code>" . htmlspecialchars(DB_HOST) . ":" . DB_PORT . "</code> gamit ang user <code>" . htmlspecialchars(DB_USER) . "</code> ngunit nabigo.</p>"
                    . "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>"
                    . "<p>Pakitingnan kung tama ang password, user, at database name sa Vercel Environment Variables.</p>"
                    . "</div>");
            }
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
