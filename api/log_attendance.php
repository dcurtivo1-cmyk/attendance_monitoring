<?php
// api/log_attendance.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

// Ensure user is authenticated
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in first.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$inputData  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$qr_token   = trim($inputData['qr_token'] ?? '');
$subject_id = intval($inputData['subject_id'] ?? 0);
$user_role  = $_SESSION['user_role'] ?? '';
$user_id    = intval($_SESSION['user_id'] ?? 0);

if ($qr_token === '') {
    echo json_encode(['status' => 'error', 'message' => 'Walang na-scan na QR Code data.']);
    exit;
}

try {
    $pdo = get_db_connection();

    // =========================================================================
    // FLOW 1: STUDENT SCANS PROFESSOR'S QR CODE (Primary Student Scanner Flow)
    // =========================================================================
    if ($user_role === 'student') {
        // Fetch student info
        $stuStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $stuStmt->execute([$user_id]);
        $student = $stuStmt->fetch();

        if (!$student) {
            echo json_encode(['status' => 'error', 'message' => 'Student account not found.']);
            exit;
        }

        $parsed_subject_id = $subject_id;
        $prof_user = null;

        // Check if QR code is JSON or structured string: PROF_ATTENDANCE|{prof_qr_token}|{subject_id}|{date}
        $decodedJson = json_decode($qr_token, true);
        if (is_array($decodedJson) && (!empty($decodedJson['prof_token']) || !empty($decodedJson['subject_id']))) {
            $tokenPart = trim($decodedJson['prof_token'] ?? '');
            if (!empty($decodedJson['subject_id'])) {
                $parsed_subject_id = intval($decodedJson['subject_id']);
            }
            if ($tokenPart !== '') {
                $pStmt = $pdo->prepare("SELECT * FROM users WHERE (qr_token = ? OR identifier = ?) AND role IN ('professor', 'admin') LIMIT 1");
                $pStmt->execute([$tokenPart, $tokenPart]);
                $prof_user = $pStmt->fetch();
            }
        } elseif (strpos($qr_token, 'PROF_ATTENDANCE|') === 0) {
            $parts = explode('|', $qr_token);
            $tokenPart = trim($parts[1] ?? '');
            if (!empty($parts[2])) {
                $parsed_subject_id = intval($parts[2]);
            }
            if ($tokenPart !== '') {
                $pStmt = $pdo->prepare("SELECT * FROM users WHERE (qr_token = ? OR identifier = ?) AND role IN ('professor', 'admin') LIMIT 1");
                $pStmt->execute([$tokenPart, $tokenPart]);
                $prof_user = $pStmt->fetch();
            }
        } else {
            // Direct professor QR token, Teacher ID, or Subject Code
            $pStmt = $pdo->prepare("SELECT * FROM users WHERE (qr_token = ? OR identifier = ?) AND role IN ('professor', 'admin') LIMIT 1");
            $pStmt->execute([$qr_token, $qr_token]);
            $prof_user = $pStmt->fetch();

            if (!$prof_user) {
                // Check teachers table
                $tStmt = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ? OR full_name = ? LIMIT 1");
                $tStmt->execute([$qr_token, $qr_token]);
                $teacherRow = $tStmt->fetch();
                if ($teacherRow && !empty($teacherRow['user_id'])) {
                    $pStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                    $pStmt->execute([$teacherRow['user_id']]);
                    $prof_user = $pStmt->fetch();
                    if ($parsed_subject_id <= 0 && !empty($teacherRow['subject_id'])) {
                        $parsed_subject_id = intval($teacherRow['subject_id']);
                    }
                }
            }

            if (!$prof_user) {
                // Check if scanned token is a subject_code directly
                $subLookup = $pdo->prepare("SELECT * FROM subjects WHERE subject_code = ? LIMIT 1");
                $subLookup->execute([$qr_token]);
                $foundSub = $subLookup->fetch();
                if ($foundSub) {
                    $parsed_subject_id = intval($foundSub['id']);
                    $pStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                    $pStmt->execute([$foundSub['professor_id']]);
                    $prof_user = $pStmt->fetch();
                }
            }
        }

        // Resolve Subject
        $subject = null;
        if ($parsed_subject_id > 0) {
            $sStmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? LIMIT 1");
            $sStmt->execute([$parsed_subject_id]);
            $subject = $sStmt->fetch();
        }

        // If subject not explicitly in QR, find subject taught by this professor that student is enrolled in (or professor's first subject)
        if (!$subject && $prof_user) {
            $sStmt = $pdo->prepare("
                SELECT s.* FROM subjects s
                JOIN student_subjects ss ON ss.subject_id = s.id
                WHERE s.professor_id = ? AND ss.student_id = ?
                LIMIT 1
            ");
            $sStmt->execute([$prof_user['id'], $student['id']]);
            $subject = $sStmt->fetch();

            if (!$subject) {
                $sStmt2 = $pdo->prepare("SELECT * FROM subjects WHERE professor_id = ? ORDER BY id ASC LIMIT 1");
                $sStmt2->execute([$prof_user['id']]);
                $subject = $sStmt2->fetch();
            }
        }

        if (!$subject) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Hindi makilala ang QR Code ng Teacher o Subject. Pakisiguradong tama ang QR code na iyong na-scan.'
            ]);
            exit;
        }

        if (!$prof_user) {
            $pStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $pStmt->execute([$subject['professor_id']]);
            $prof_user = $pStmt->fetch();
        }

        $prof_id = $prof_user ? intval($prof_user['id']) : intval($subject['professor_id']);
        $prof_name = $prof_user ? preg_replace('/^Prof\.?\s+/i', 'Teacher ', trim($prof_user['name'])) : 'Assigned Teacher';

        // Ensure student is enrolled in this subject
        $enrollStmt = $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
        $enrollStmt->execute([$student['id'], $subject['id']]);

        // Check if already recorded today
        $todayDate = date('Y-m-d');
        $checkStmt = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? AND subject_id = ? AND date = ?");
        $checkStmt->execute([$student['id'], $subject['id'], $todayDate]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            echo json_encode([
                'status'  => 'already_recorded',
                'message' => 'Naitala na ang iyong attendance para sa klase na ito ngayong araw!',
                'student' => [
                    'name'           => $student['name'],
                    'identifier'     => $student['identifier'],
                    'course_section' => $student['course_section'] ?? 'N/A'
                ],
                'subject' => [
                    'id'             => $subject['id'],
                    'subject_code'   => $subject['subject_code'],
                    'subject_name'   => $subject['subject_name'],
                    'professor_name' => $prof_name
                ],
                'attendance' => [
                    'date'    => date('M d, Y', strtotime($existing['date'])),
                    'time_in' => date('h:i A', strtotime($existing['time_in'])),
                    'status'  => $existing['status']
                ]
            ]);
            exit;
        }

        // Determine Present or Late based on Subject Schedule
        $currentDay  = date('l');
        $currentTime = date('H:i:s');
        $status      = 'Present';

        $schedStmt = $pdo->prepare("SELECT * FROM schedules WHERE subject_id = ? AND day_of_week = ? LIMIT 1");
        $schedStmt->execute([$subject['id'], $currentDay]);
        $sched = $schedStmt->fetch();

        if ($sched) {
            $startTime         = strtotime($sched['start_time']);
            $lateThresholdMins = (int)($sched['late_threshold_mins'] ?? 15);
            $lateCutoffTime    = $startTime + ($lateThresholdMins * 60);

            if (time() > $lateCutoffTime) {
                $status = 'Late';
            }
        }

        // Record Attendance
        $insertStmt = $pdo->prepare("
            INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by) 
            VALUES (:student_id, :subject_id, :date, :time_in, :status, :scanned_by)
        ");
        $insertStmt->execute([
            ':student_id' => $student['id'],
            ':subject_id' => $subject['id'],
            ':date'       => $todayDate,
            ':time_in'    => $currentTime,
            ':status'     => $status,
            ':scanned_by' => $prof_id
        ]);

        // Also mirror into admin attendance_records table if it exists so Admin Attendance page stays updated
        try {
            $parts = preg_split('/\s+/', trim($student['name']));
            $initials = count($parts) >= 2
                ? strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1))
                : strtoupper(substr($student['name'], 0, 2));
            $gradeLevel = !empty($student['grade_level']) ? $student['grade_level'] : 'Grade 8';
            $secName    = !empty($student['course_section']) ? $student['course_section'] : 'Section A';

            $pdo->prepare("
                INSERT INTO attendance_records (record_date, record_time, student_name, student_code, avatar_initials, grade_level, section_name, subject_name, status, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'QR Scanned')
            ")->execute([
                $todayDate,
                $currentTime,
                $student['name'],
                $student['identifier'],
                $initials,
                $gradeLevel,
                $secName,
                $subject['subject_name'],
                $status
            ]);
        } catch (PDOException $e) {
            // Ignore if optional mirror table not present
        }

        echo json_encode([
            'status'  => 'success',
            'message' => 'Matagumpay na naitala ang iyong attendance!',
            'student' => [
                'name'           => $student['name'],
                'identifier'     => $student['identifier'],
                'course_section' => $student['course_section'] ?? 'N/A'
            ],
            'subject' => [
                'id'             => $subject['id'],
                'subject_code'   => $subject['subject_code'],
                'subject_name'   => $subject['subject_name'],
                'professor_name' => $prof_name
            ],
            'attendance' => [
                'date'    => date('M d, Y', strtotime($todayDate)),
                'time_in' => date('h:i A', strtotime($currentTime)),
                'status'  => $status
            ]
        ]);
        exit;
    }

    // =========================================================================
    // FLOW 2: PROFESSOR OR ADMIN LOGS STUDENT BY ID/TOKEN
    // =========================================================================
    if (!in_array($user_role, ['professor', 'admin'], true)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
    }

    if ($subject_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please select an active Subject / Class first.']);
        exit;
    }

    $subjectStmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
    $subjectStmt->execute([$subject_id]);
    $subject = $subjectStmt->fetch();

    if (!$subject) {
        echo json_encode(['status' => 'error', 'message' => 'Subject not found.']);
        exit;
    }

    $studentStmt = $pdo->prepare("SELECT * FROM users WHERE (qr_token = ? OR identifier = ?) AND role = 'student' LIMIT 1");
    $studentStmt->execute([$qr_token, $qr_token]);
    $student = $studentStmt->fetch();

    if (!$student) {
        echo json_encode(['status' => 'error', 'message' => 'Student not found in database.']);
        exit;
    }

    $enrollStmt = $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
    $enrollStmt->execute([$student['id'], $subject_id]);

    $todayDate = date('Y-m-d');
    $checkStmt = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? AND subject_id = ? AND date = ?");
    $checkStmt->execute([$student['id'], $subject_id, $todayDate]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        echo json_encode([
            'status' => 'already_recorded',
            'message' => 'Attendance already recorded earlier today!',
            'student' => [
                'name'           => $student['name'],
                'identifier'     => $student['identifier'],
                'course_section' => $student['course_section'] ?? 'N/A'
            ],
            'attendance' => [
                'date'    => $existing['date'],
                'time_in' => date('h:i A', strtotime($existing['time_in'])),
                'status'  => $existing['status']
            ]
        ]);
        exit;
    }

    $currentTime = date('H:i:s');
    $status = 'Present';

    $insertStmt = $pdo->prepare("
        INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by) 
        VALUES (:student_id, :subject_id, :date, :time_in, :status, :scanned_by)
    ");
    $insertStmt->execute([
        ':student_id' => $student['id'],
        ':subject_id' => $subject_id,
        ':date'       => $todayDate,
        ':time_in'    => $currentTime,
        ':status'     => $status,
        ':scanned_by' => $user_id
    ]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Attendance logged successfully!',
        'student' => [
            'name'           => $student['name'],
            'identifier'     => $student['identifier'],
            'course_section' => $student['course_section'] ?? 'N/A'
        ],
        'attendance' => [
            'date'    => $todayDate,
            'time_in' => date('h:i A', strtotime($currentTime)),
            'status'  => $status
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
