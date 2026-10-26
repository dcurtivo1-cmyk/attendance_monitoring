<?php
// api/index.php - Vercel Serverless Entry Point & Router

// Ensure session path is writable on Vercel/serverless environments
if (session_status() === PHP_SESSION_NONE) {
    $tmp = sys_get_temp_dir();
    if (is_dir($tmp) && is_writable($tmp)) {
        session_save_path($tmp);
    }
}

$rootDir = dirname(__DIR__);

// Get the requested URI path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = parse_url($requestUri, PHP_URL_PATH);
$path = trim(urldecode($uriPath), '/');

// 1. Root route -> index.php
if ($path === '' || $path === 'index.php') {
    chdir($rootDir);
    require $rootDir . '/index.php';
    exit;
}

// 2. Direct PHP file match (e.g. login.php, register.php, logout.php, admin/dashboard.php)
$target = $rootDir . '/' . $path;
if (is_file($target) && pathinfo($target, PATHINFO_EXTENSION) === 'php') {
    chdir(dirname($target));
    require $target;
    exit;
}

// 3. Without .php extension (e.g. /login -> /login.php)
if (is_file($target . '.php')) {
    chdir(dirname($target . '.php'));
    require $target . '.php';
    exit;
}

// 4. Directory default (e.g. /admin -> /admin/dashboard.php)
if (is_dir($target)) {
    if (is_file($target . '/dashboard.php')) {
        chdir($target);
        require $target . '/dashboard.php';
        exit;
    }
    if (is_file($target . '/index.php')) {
        chdir($target);
        require $target . '/index.php';
        exit;
    }
}

// 5. Static files fallback (if not handled directly by Vercel static routing)
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'css'   => 'text/css',
    'js'    => 'application/javascript',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'svg'   => 'image/svg+xml',
    'ico'   => 'image/x-icon',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
    'pdf'   => 'application/pdf',
];

if (is_file($target)) {
    if (isset($mimeMap[$ext])) {
        header('Content-Type: ' . $mimeMap[$ext]);
    }
    readfile($target);
    exit;
}

// 6. 404 Not Found fallback
http_response_code(404);
echo "404 Not Found: " . htmlspecialchars($uriPath);
