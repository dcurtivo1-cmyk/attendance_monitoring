<?php
// api/manual_attendance.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

// Ensure user is authenticated as professor or admin
if (!is_logged_in() || !in_array($_SESSION['user_role'] ?? '', ['professor', 'admin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$action     = trim($input['action'] ?? 'set_status');
$subject_id = intval($input['subject_id'] ?? 0);
$student_id = intval($input['student_id'] ?? 0);
$date       = trim($input['date'] ?? date('Y-m-d'));
$status     = trim($input['status'] ?? 'Present');
$time_in    = trim($input['time_in'] ?? date('H:i:s'));
$prof_id    = $_SESSION['user_id'];

$allowed_statuses = ['Present', 'Late', 'Absent', 'Excused'];

try {
    $pdo = get_db_connection();

    if ($subject_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Subject.']);
        exit;
    }

    // Verify subject
    $subStmt = $pdo->prepare("SELECT id FROM subjects WHERE id = ?");
    $subStmt->execute([$subject_id]);
    if (!$subStmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Subject not found.']);
        exit;
    }

    if ($action === 'set_status') {
        if ($student_id <= 0 || !in_array($status, $allowed_statuses)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student or status parameter.']);
            exit;
        }

        // Auto enroll if not in student_subjects
        $enrollStmt = $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
        $enrollStmt->execute([$student_id, $subject_id]);

        // Insert or update attendance
        $stmt = $pdo->prepare("
            INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by)
            VALUES (:student_id, :subject_id, :date, :time_in, :status, :scanned_by)
            ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = VALUES(time_in), scanned_by = VALUES(scanned_by)
        ");
        $stmt->execute([
            ':student_id' => $student_id,
            ':subject_id' => $subject_id,
            ':date'       => $date,
            ':time_in'    => $time_in,
            ':status'     => $status,
            ':scanned_by' => $prof_id
        ]);

        echo json_encode([
            'status'  => 'success',
            'message' => "Marked as $status successfully!",
            'data'    => [
                'student_id' => $student_id,
                'status'     => $status,
                'time_in'    => date('h:i A', strtotime($time_in)),
                'date'       => $date
            ]
        ]);
        exit;
    } elseif ($action === 'clear_record') {
        if ($student_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student ID.']);
            exit;
        }
        $del = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND subject_id = ? AND date = ?");
        $del->execute([$student_id, $subject_id, $date]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'Attendance record cleared for this date.',
            'data'    => ['student_id' => $student_id, 'status' => 'Unmarked']
        ]);
        exit;
    } elseif ($action === 'mark_all_present') {
        // Fetch all enrolled students for this subject
        $enrolled = $pdo->prepare("SELECT student_id FROM student_subjects WHERE subject_id = ?");
        $enrolled->execute([$subject_id]);
        $students = $enrolled->fetchAll();

        $count = 0;
        $currentTime = date('H:i:s');
        $insert = $pdo->prepare("
            INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by)
            VALUES (:student_id, :subject_id, :date, :time_in, 'Present', :scanned_by)
            ON DUPLICATE KEY UPDATE status = 'Present', time_in = VALUES(time_in), scanned_by = VALUES(scanned_by)
        ");

        foreach ($students as $stu) {
            $insert->execute([
                ':student_id' => $stu['student_id'],
                ':subject_id' => $subject_id,
                ':date'       => $date,
                ':time_in'    => $currentTime,
                ':scanned_by' => $prof_id
            ]);
            $count++;
        }

        echo json_encode([
            'status'  => 'success',
            'message' => "Marked $count enrolled students as Present for $date!",
            'count'   => $count,
            'time_in' => date('h:i A', strtotime($currentTime))
        ]);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
