<?php
// index.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

if (is_logged_in()) {
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'student') {
        header("Location: " . base_url('student/dashboard.php'));
        exit;
    } elseif ($role === 'professor') {
        header("Location: " . base_url('professor/dashboard.php'));
        exit;
    } elseif ($role === 'admin') {
        header("Location: " . base_url('admin/dashboard.php'));
        exit;
    }
}
header("Location: " . base_url('login.php'));
exit;
