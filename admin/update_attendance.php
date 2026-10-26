<?php
// admin/update_attendance.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = get_db_connection();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $teacherId = intval($input['teacher_id'] ?? 0);
    $newStatus = trim($input['status'] ?? '');
    $attDate   = trim($input['date'] ?? date('Y-m-d'));
    $timeIn    = isset($input['time_in']) && trim($input['time_in']) !== '' ? trim($input['time_in']) : null;
    $timeOut   = isset($input['time_out']) && trim($input['time_out']) !== '' ? trim($input['time_out']) : null;

    if ($teacherId <= 0 || !in_array($newStatus, ['Present', 'Absent', 'Late'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid teacher ID or attendance status.']);
        exit;
    }

    // Fetch teacher record
    $tStmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ? LIMIT 1");
    $tStmt->execute([$teacherId]);
    $teacher = $tStmt->fetch();

    if (!$teacher) {
        echo json_encode(['status' => 'error', 'message' => 'Teacher not found.']);
        exit;
    }

    // Check existing attendance record for this date
    $existingStmt = $pdo->prepare("SELECT * FROM teacher_attendance WHERE teacher_id = ? AND date = ? LIMIT 1");
    $existingStmt->execute([$teacherId, $attDate]);
    $existing = $existingStmt->fetch();

    // Determine appropriate Time In / Time Out timestamps
    if ($newStatus === 'Absent') {
        $finalTimeIn  = $timeIn ?: ($existing['time_in'] ?? '08:10:00');
        $finalTimeOut = $timeOut ?: ($existing['time_out'] ?? '15:20:00');
    } elseif ($newStatus === 'Late') {
        $finalTimeIn  = $timeIn ?: ($existing['time_in'] ?? date('H:i:s'));
        if ($finalTimeIn < '08:00:00') {
            $finalTimeIn = '08:15:00';
        }
        $finalTimeOut = $timeOut ?: ($existing['time_out'] ?? '16:15:00');
    } else {
        // Present
        $finalTimeIn  = $timeIn ?: ($existing['time_in'] ?? '07:50:00');
        $finalTimeOut = $timeOut ?: ($existing['time_out'] ?? '15:45:00');
    }

    $upsertStmt = $pdo->prepare("
        INSERT INTO teacher_attendance (teacher_id, subject_name, grade_level_name, date, time_in, time_out, status, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            time_in = VALUES(time_in),
            time_out = VALUES(time_out),
            subject_name = VALUES(subject_name),
            grade_level_name = VALUES(grade_level_name),
            recorded_by = VALUES(recorded_by)
    ");
    $upsertStmt->execute([
        $teacherId,
        $teacher['subject_name'],
        $teacher['grade_level_name'],
        $attDate,
        $finalTimeIn,
        $finalTimeOut,
        $newStatus,
        $_SESSION['user_id'] ?? null
    ]);

    // Recalculate summary statistics for all active teachers on the given date
    $allStmt = $pdo->prepare("
        SELECT t.id, COALESCE(ta.status, 'Present') AS day_status
        FROM teachers t
        LEFT JOIN teacher_attendance ta ON ta.teacher_id = t.id AND ta.date = ?
        WHERE t.status = 'Active'
    ");
    $allStmt->execute([$attDate]);
    $rows = $allStmt->fetchAll();

    $totalTeachers = count($rows);
    $presentCount  = 0;
    $absentCount   = 0;
    $lateCount     = 0;

    foreach ($rows as $r) {
        if ($r['day_status'] === 'Present') {
            $presentCount++;
        } elseif ($r['day_status'] === 'Absent') {
            $absentCount++;
        } elseif ($r['day_status'] === 'Late') {
            $lateCount++;
        }
    }

    // Attendance Rate: (Present Today / Total Teachers) * 100 rounded to 1 decimal place
    $attendanceRate = $totalTeachers > 0
        ? round(($presentCount / $totalTeachers) * 100, 1)
        : 0.0;
    $presentPct = $totalTeachers > 0 ? round(($presentCount / $totalTeachers) * 100, 1) : 0.0;
    $absentPct  = $totalTeachers > 0 ? round(($absentCount / $totalTeachers) * 100, 1) : 0.0;
    $latePct    = $totalTeachers > 0 ? round(($lateCount / $totalTeachers) * 100, 1) : 0.0;

    echo json_encode([
        'status'  => 'success',
        'message' => "Updated {$teacher['full_name']}'s attendance to {$newStatus}.",
        'record'  => [
            'teacher_id'    => $teacherId,
            'teacher_name'  => $teacher['full_name'],
            'status'        => $newStatus,
            'time_in'       => substr($finalTimeIn, 0, 5),
            'time_out'      => substr($finalTimeOut, 0, 5),
            'formatted_in'  => $finalTimeIn ? date('h:i A', strtotime($finalTimeIn)) : '—',
            'formatted_out' => $finalTimeOut ? date('h:i A', strtotime($finalTimeOut)) : '—'
        ],
        'summary' => [
            'total_teachers'  => $totalTeachers,
            'present_today'   => $presentCount,
            'absent_today'    => $absentCount,
            'late_today'      => $lateCount,
            'attendance_rate' => $attendanceRate,
            'present_pct'     => $presentPct,
            'absent_pct'      => $absentPct,
            'late_pct'        => $latePct
        ]
    ]);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

