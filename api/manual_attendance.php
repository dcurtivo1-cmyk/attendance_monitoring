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

if (!is_array($input) || !is_string($input['csrf'] ?? null) || empty($_SESSION['manual_attendance_csrf']) || !hash_equals($_SESSION['manual_attendance_csrf'], $input['csrf'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Your form expired. Refresh the page and try again.']);
    exit;
}
$section = is_string($input['section'] ?? null) ? trim($input['section']) : '';
if ($section === '' || $section === 'ALL') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Choose a specific section before marking attendance.']);
    exit;
}
$action     = trim($input['action'] ?? 'set_status');
$subject_id = intval($input['subject_id'] ?? 0);
$student_id = intval($input['student_id'] ?? 0);
$manualNow  = school_now();
$date       = trim($input['date'] ?? $manualNow->format('Y-m-d'));
$status     = trim($input['status'] ?? 'Present');
$time_in    = $manualNow->format('H:i:s'); // The browser cannot supply arrival time.
$prof_id    = $_SESSION['user_id'];

$allowed_statuses = ['Present', 'Late', 'Absent', 'Excused'];

try {
    $pdo = get_db_connection();

    if ($subject_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Subject.']);
        exit;
    }

    // Verify subject
    $subStmt = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND (professor_id = ? OR ? = 'admin')");
    $subStmt->execute([$subject_id, $prof_id, $_SESSION['user_role']]);
    if (!$subStmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Subject not found.']);
        exit;
    }

    // The visible section applies to both individual actions and bulk marking.
    if (in_array($action, ['set_status', 'clear_record'], true)) {
        $studentStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND course_section = ?");
        $studentStmt->execute([$student_id, $section]);
        if (!$studentStmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Student is not in the selected section.']);
            exit;
        }
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
            ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = COALESCE(time_in, VALUES(time_in)), scanned_by = VALUES(scanned_by)
        ");
        $stmt->execute([
            ':student_id' => $student_id,
            ':subject_id' => $subject_id,
            ':date'       => $date,
            ':time_in'    => $time_in,
            ':status'     => $status,
            ':scanned_by' => $prof_id
        ]);

        // Status corrections retain and return the original recorded arrival time.
        $saved = $pdo->prepare('SELECT time_in FROM attendance WHERE student_id = ? AND subject_id = ? AND date = ?');
        $saved->execute([$student_id, $subject_id, $date]);
        $savedTime = $saved->fetchColumn();

        echo json_encode([
            'status'  => 'success',
            'message' => "Marked as $status successfully!",
            'data'    => [
                'student_id' => $student_id,
                'status'     => $status,
                'time_in'    => $savedTime ? date('h:i A', strtotime($savedTime)) : null,
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
        // Fetch only enrolled students in the selected section
        $enrolled = $pdo->prepare("SELECT ss.student_id FROM student_subjects ss JOIN users u ON u.id = ss.student_id WHERE ss.subject_id = ? AND u.role = 'student' AND u.course_section = ?");
        $enrolled->execute([$subject_id, $section]);
        $students = $enrolled->fetchAll();

        $count = 0;
        $currentTime = $manualNow->format('H:i:s');
        $insert = $pdo->prepare("
            INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by)
            VALUES (:student_id, :subject_id, :date, :time_in, 'Present', :scanned_by)
            ON DUPLICATE KEY UPDATE status = 'Present', time_in = COALESCE(time_in, VALUES(time_in)), scanned_by = VALUES(scanned_by)
        ");

        $pdo->beginTransaction();
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
        $pdo->commit();

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
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('Manual attendance failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Could not save attendance. Please try again.']);
}
