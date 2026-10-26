<?php
// config/auth_check.php
require_once __DIR__ . '/database.php';

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function current_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'             => $_SESSION['user_id'] ?? null,
        'identifier'     => $_SESSION['user_identifier'] ?? null,
        'name'           => $_SESSION['user_name'] ?? null,
        'email'          => $_SESSION['user_email'] ?? null,
        'role'           => $_SESSION['user_role'] ?? null,
        'course_section' => $_SESSION['course_section'] ?? null,
        'qr_token'       => $_SESSION['qr_token'] ?? null
    ];
}

function require_auth($allowed_roles = []) {
    if (!is_logged_in()) {
        header("Location: " . base_url('login.php'));
        exit;
    }

    if (!empty($allowed_roles)) {
        $current_role = $_SESSION['user_role'] ?? '';
        if (!in_array($current_role, (array)$allowed_roles)) {
            // Redirect based on actual role
            if ($current_role === 'student') {
                header("Location: " . base_url('student/dashboard.php'));
            } elseif ($current_role === 'professor') {
                header("Location: " . base_url('professor/dashboard.php'));
            } elseif ($current_role === 'admin') {
                header("Location: " . base_url('admin/dashboard.php'));
            } else {
                header("Location: " . base_url('login.php'));
            }
            exit;
        }
    }
}

// Generate unique secure QR token for student
function generate_qr_token($student_id) {
    return 'STU_QR_' . hash('sha256', $student_id . microtime(true) . bin2hex(random_bytes(8)));
}

/**
 * Auto-generate next Student ID with format STUD-YYYY-000-001
 */
function get_next_student_id(?PDO $pdo = null): string {
    if (!$pdo) {
        $pdo = get_db_connection();
    }
    $year = date('Y');
    $prefix = "STUD-{$year}-000-";

    $stmt = $pdo->prepare("SELECT identifier FROM users WHERE role = 'student' AND identifier LIKE ?");
    $stmt->execute([$prefix . '%']);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxNum = 0;
    foreach ($ids as $ident) {
        if (preg_match('/^STUD-\d{4}-000-(\d+)$/', $ident, $m)) {
            $num = intval($m[1]);
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }

    if ($maxNum === 0) {
        // Also check if any other format exists for student count
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
        $next = $cnt + 1;
    } else {
        $next = $maxNum + 1;
    }

    return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
}

/**
 * Auto-generate next Teacher ID with format TCH-YYYY-000-001
 */
function get_next_teacher_id(?PDO $pdo = null): string {
    if (!$pdo) {
        $pdo = get_db_connection();
    }
    $year = date('Y');
    $prefix = "TCH-{$year}-000-";

    $stmt = $pdo->prepare("SELECT teacher_id FROM teachers WHERE teacher_id LIKE ?");
    $stmt->execute([$prefix . '%']);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxNum = 0;
    foreach ($ids as $tId) {
        if (preg_match('/^TCH-\d{4}-000-(\d+)$/', $tId, $m)) {
            $num = intval($m[1]);
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }

    if ($maxNum === 0) {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
        $next = $cnt + 1;
    } else {
        $next = $maxNum + 1;
    }

    return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
}
