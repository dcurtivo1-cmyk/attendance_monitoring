<?php
// admin/get_attendance_stats.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

try {
    $pdo = get_db_connection();
    $attDate = trim($_GET['date'] ?? ($_POST['date'] ?? date('Y-m-d')));

    // Query active teachers and their attendance status for the target date
    $stmt = $pdo->prepare("
        SELECT t.id,
               t.teacher_id,
               t.full_name,
               t.subject_name,
               t.grade_level_name,
               COALESCE(ta.status, 'Present') AS day_status,
               ta.time_in,
               ta.time_out
        FROM teachers t
        LEFT JOIN teacher_attendance ta ON ta.teacher_id = t.id AND ta.date = ?
        WHERE t.status = 'Active'
        ORDER BY t.id ASC
    ");
    $stmt->execute([$attDate]);
    $rows = $stmt->fetchAll();

    $totalTeachers = count($rows);
    $presentToday  = 0;
    $absentToday   = 0;
    $lateToday     = 0;
    $records       = [];

    foreach ($rows as $r) {
        $st = $r['day_status'];
        if ($st === 'Present') {
            $presentToday++;
        } elseif ($st === 'Absent') {
            $absentToday++;
        } elseif ($st === 'Late') {
            $lateToday++;
        }

        $records[] = [
            'id'            => (int)$r['id'],
            'teacher_id'    => $r['teacher_id'],
            'full_name'     => $r['full_name'],
            'subject_name'  => $r['subject_name'],
            'grade_level'   => $r['grade_level_name'],
            'status'        => $st,
            'formatted_in'  => !empty($r['time_in']) ? date('h:i A', strtotime($r['time_in'])) : '07:50 AM',
            'formatted_out' => !empty($r['time_out']) ? date('h:i A', strtotime($r['time_out'])) : '03:45 PM'
        ];
    }

    // Attendance Rate: (Present Today / Total Teachers) * 100 rounded to one decimal place
    $attendanceRate = $totalTeachers > 0
        ? round(($presentToday / $totalTeachers) * 100, 1)
        : 0.0;

    $presentPct = $totalTeachers > 0 ? round(($presentToday / $totalTeachers) * 100, 1) : 0.0;
    $absentPct  = $totalTeachers > 0 ? round(($absentToday / $totalTeachers) * 100, 1) : 0.0;
    $latePct    = $totalTeachers > 0 ? round(($lateToday / $totalTeachers) * 100, 1) : 0.0;

    echo json_encode([
        'status'  => 'success',
        'date'    => $attDate,
        'summary' => [
            'total_teachers'  => $totalTeachers,
            'present_today'   => $presentToday,
            'absent_today'    => $absentToday,
            'late_today'      => $lateToday,
            'attendance_rate' => $attendanceRate,
            'present_pct'     => $presentPct,
            'absent_pct'      => $absentPct,
            'late_pct'        => $latePct
        ],
        'records' => $records
    ]);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

