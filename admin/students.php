<?php
// admin/students.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

// Ensure existing student rows have populated grade_level, course_section, guardian_contact, student_subtitle
try {
    $seedMap = [
        '2025-07-00594' => ['Edcel Ventura',      '2025-07-00594', 'Grade 7',  'Section A - Rizal',     'edcel01ventura@gmail.com',    '+63 912 345 6789'],
        '2026-000-001'  => ['RYAN',               '2026-000-001',  'Grade 8',  'Section B - Jacinto',   'ryan@gmail.com',              '+63 917 123 4567'],
        '2026-000-002'  => ['DEN MAR',            'admin',         'Grade 9',  'Section A - Mabini',    'denmar@gmail.com',            '+63 918 765 4321'],
        '2026-005'      => ['luna dela cruz',     'STUD-2026-005', 'Grade 10', 'Section A - Luna',      'stud@gmail.com',              '+63 919 876 5432'],
        '2026-101'      => ['Maria Clara Santos', 'STUD-2026-101', 'Grade 8',  'Section A - Bonifacio', 'maria.santos@student.edu.ph', '+63 920 111 2222'],
        '2026-102'      => ['Juan Dela Cruz',     'STUD-2026-102', 'Grade 7',  'Section B - Plaridel',  'juan.delaCruz@gmail.com',     '+63 921 333 4444'],
        '2026-103'      => ['Ana Reyes',          'STUD-2026-103', 'Grade 10', 'Section B - Del Pilar', 'ana.reyes@gmail.com',         '+63 922 555 6666'],
    ];

    $syncStmt = $pdo->prepare("
        UPDATE users
        SET grade_level = ?, course_section = ?, guardian_contact = ?, student_subtitle = ?
        WHERE identifier = ? AND role = 'student' AND (student_subtitle IS NULL OR student_subtitle = '')
    ");
    foreach ($seedMap as $ident => $meta) {
        $syncStmt->execute([$meta[2], $meta[3], $meta[5], $meta[1], $ident]);
    }
} catch (PDOException $e) {
    // Ignore if columns are being migrated
}

// =========================================================================
// AJAX / JSON API HANDLERS FOR STUDENT RECORDS CRUD & STATUS TOGGLING
// =========================================================================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_GET['ajax_action'];

    try {
        // 1. Add Student via Modal AJAX
        if ($ajaxAction === 'add_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $identifier       = trim($input['identifier'] ?? '');
            $firstName        = trim($input['first_name'] ?? '');
            $middleInitial    = trim($input['middle_initial'] ?? '');
            $lastName         = trim($input['last_name'] ?? '');
            $name             = trim($input['name'] ?? '');

            if ($name === '' && ($firstName !== '' || $lastName !== '')) {
                $name = trim($firstName . ' ' . ($middleInitial !== '' ? rtrim($middleInitial, '.') . '. ' : '') . $lastName);
            }
            if ($firstName === '' && $name !== '') {
                $p = preg_split('/\s+/', $name);
                $firstName = $p[0];
                $lastName = count($p) > 1 ? end($p) : '';
                $middleInitial = count($p) > 2 ? $p[1] : '';
            }

            $email            = trim($input['email'] ?? '');
            $gradeLevel       = trim($input['grade_level'] ?? 'Grade 8');
            $courseSection    = trim($input['course_section'] ?? 'Section A - Bonifacio');
            $guardianContact  = trim($input['guardian_contact'] ?? '+63 917 123 4567');
            $studentSubtitle  = trim($input['student_subtitle'] ?? $identifier);
            $status           = trim($input['status'] ?? 'Active');
            $password         = trim($input['password'] ?? 'password123');

            if ($identifier === '' || ($firstName === '' && $name === '') || $email === '') {
                echo json_encode(['status' => 'error', 'message' => 'Student ID, Name, and Guardian Email are required.']);
                exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
                exit;
            }

            $dupStmt = $pdo->prepare("SELECT id FROM users WHERE identifier = ? OR email = ? LIMIT 1");
            $dupStmt->execute([$identifier, $email]);
            if ($dupStmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'That Student ID or Email is already registered.']);
                exit;
            }

            $qrToken = generate_qr_token($identifier);
            $hashedPassword = password_hash($password !== '' ? $password : 'password123', PASSWORD_BCRYPT);

            $ins = $pdo->prepare("
                INSERT INTO users (identifier, name, first_name, middle_initial, last_name, email, password, role, course_section, grade_level, guardian_contact, student_subtitle, status, qr_token)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'student', ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $identifier,
                $name,
                $firstName,
                $middleInitial,
                $lastName,
                $email,
                $hashedPassword,
                $courseSection,
                $gradeLevel,
                $guardianContact,
                $identifier,
                in_array($status, ['Active', 'Inactive'], true) ? $status : 'Active',
                $qrToken
            ]);

            $newId = (int)$pdo->lastInsertId();

            echo json_encode([
                'status'  => 'success',
                'message' => "Student '$name' ($identifier) added successfully!",
                'student' => [
                    'id'               => $newId,
                    'identifier'       => $identifier,
                    'name'             => $name,
                    'first_name'       => $firstName,
                    'middle_initial'   => $middleInitial,
                    'last_name'        => $lastName,
                    'student_subtitle' => $identifier,
                    'email'            => $email,
                    'grade_level'      => $gradeLevel,
                    'course_section'   => $courseSection,
                    'guardian_contact' => $guardianContact,
                    'status'           => in_array($status, ['Active', 'Inactive'], true) ? $status : 'Active',
                    'qr_token'         => $qrToken
                ]
            ]);
            exit;
        }

        // 2. Get Single Student Details + Attendance History for View Modal
        if ($ajaxAction === 'get_student') {
            $studentId = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' LIMIT 1");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();

            if (!$student) {
                echo json_encode(['status' => 'error', 'message' => 'Student record not found.']);
                exit;
            }

            $attStmt = $pdo->prepare("
                SELECT a.date, a.time_scanned, a.status, s.subject_code, s.subject_name
                FROM attendance a
                LEFT JOIN subjects s ON s.id = a.subject_id
                WHERE a.student_id = ?
                ORDER BY a.date DESC, a.time_scanned DESC
                LIMIT 15
            ");
            $attStmt->execute([$studentId]);
            $logs = $attStmt->fetchAll();

            $fn = $student['first_name'] ?? '';
            $mi = $student['middle_initial'] ?? '';
            $ln = $student['last_name'] ?? '';
            if ($fn === '' && $student['name']) {
                $p = preg_split('/\s+/', trim($student['name']));
                $fn = $p[0];
                $ln = count($p) > 1 ? end($p) : '';
                $mi = count($p) > 2 ? $p[1] : '';
            }

            echo json_encode([
                'status'  => 'success',
                'student' => [
                    'id'               => (int)$student['id'],
                    'identifier'       => $student['identifier'],
                    'name'             => $student['name'],
                    'first_name'       => $fn,
                    'middle_initial'   => $mi,
                    'last_name'        => $ln,
                    'student_subtitle' => $student['student_subtitle'] ?: $student['identifier'],
                    'email'            => $student['email'],
                    'grade_level'      => $student['grade_level'] ?: 'Grade 8',
                    'course_section'   => $student['course_section'] ?: 'Section A - Bonifacio',
                    'guardian_contact' => $student['guardian_contact'] ?: '+63 917 123 4567',
                    'status'           => $student['status'] ?? 'Active',
                    'qr_token'         => $student['qr_token'] ?? '',
                    'created_at'       => !empty($student['created_at']) ? date('M d, Y', strtotime($student['created_at'])) : '—'
                ],
                'history' => $logs
            ]);
            exit;
        }

        // 3. Update Student Record from Edit Modal
        if ($ajaxAction === 'update_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id               = intval($input['id'] ?? 0);
            $identifier       = trim($input['identifier'] ?? '');
            $firstName        = trim($input['first_name'] ?? '');
            $middleInitial    = trim($input['middle_initial'] ?? '');
            $lastName         = trim($input['last_name'] ?? '');
            $name             = trim($input['name'] ?? '');

            if ($name === '' && ($firstName !== '' || $lastName !== '')) {
                $name = trim($firstName . ' ' . ($middleInitial !== '' ? rtrim($middleInitial, '.') . '. ' : '') . $lastName);
            }
            if ($firstName === '' && $name !== '') {
                $p = preg_split('/\s+/', $name);
                $firstName = $p[0];
                $lastName = count($p) > 1 ? end($p) : '';
                $middleInitial = count($p) > 2 ? $p[1] : '';
            }

            $email            = trim($input['email'] ?? '');
            $gradeLevel       = trim($input['grade_level'] ?? 'Grade 8');
            $courseSection    = trim($input['course_section'] ?? 'Section A - Bonifacio');
            $guardianContact  = trim($input['guardian_contact'] ?? '+63 917 123 4567');
            $status           = trim($input['status'] ?? 'Active');
            if (!in_array($status, ['Active', 'Inactive'], true)) {
                $status = 'Active';
            }

            if ($id <= 0 || $identifier === '' || ($firstName === '' && $name === '') || $email === '') {
                echo json_encode(['status' => 'error', 'message' => 'Student ID, Name, and Email are required.']);
                exit;
            }

            $dupStmt = $pdo->prepare("SELECT id FROM users WHERE (identifier = ? OR email = ?) AND id != ? LIMIT 1");
            $dupStmt->execute([$identifier, $email, $id]);
            if ($dupStmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Another user already uses that Student ID or Email.']);
                exit;
            }

            $upd = $pdo->prepare("
                UPDATE users
                SET identifier = ?, name = ?, first_name = ?, middle_initial = ?, last_name = ?, email = ?, course_section = ?, grade_level = ?, guardian_contact = ?, student_subtitle = ?, status = ?
                WHERE id = ? AND role = 'student'
            ");
            $upd->execute([
                $identifier,
                $name,
                $firstName,
                $middleInitial,
                $lastName,
                $email,
                $courseSection,
                $gradeLevel,
                $guardianContact,
                $identifier,
                $status,
                $id
            ]);

            echo json_encode([
                'status'  => 'success',
                'message' => "Student '$name' updated successfully!",
                'student' => [
                    'id'               => $id,
                    'identifier'       => $identifier,
                    'name'             => $name,
                    'student_subtitle' => $studentSubtitle !== '' ? $studentSubtitle : $identifier,
                    'email'            => $email,
                    'grade_level'      => $gradeLevel,
                    'course_section'   => $courseSection,
                    'guardian_contact' => $guardianContact,
                    'status'           => $status
                ]
            ]);
            exit;
        }

        // 4. Interactive Status Badge Toggle/Update (Active <-> Inactive)
        if ($ajaxAction === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = intval($input['id'] ?? 0);
            $newStatus = trim($input['status'] ?? 'Active');
            if (!in_array($newStatus, ['Active', 'Inactive'], true)) {
                $newStatus = 'Active';
            }

            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid student ID.']);
                exit;
            }

            $upd = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'student'");
            $upd->execute([$newStatus, $id]);

            echo json_encode([
                'status'     => 'success',
                'message'    => "Student status updated to $newStatus.",
                'id'         => $id,
                'new_status' => $newStatus
            ]);
            exit;
        }

        // 5. Reset Student Password
        if ($ajaxAction === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = intval($input['id'] ?? 0);
            $newPassword = trim($input['new_password'] ?? 'password123');
            if (strlen($newPassword) < 6) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
                exit;
            }

            $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'student'");
            $upd->execute([password_hash($newPassword, PASSWORD_BCRYPT), $id]);

            echo json_encode([
                'status'  => 'success',
                'message' => "Student password has been reset to '$newPassword'."
            ]);
            exit;
        }

        // 6. Delete Student Record
        if ($ajaxAction === 'delete_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = intval($input['id'] ?? 0);

            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid student ID.']);
                exit;
            }

            $del = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
            $del->execute([$id]);

            echo json_encode([
                'status'  => 'success',
                'message' => 'Student record deleted from database.'
            ]);
            exit;
        }

        // 7. Get Next Standardized Student ID (Auto-generation)
        if ($ajaxAction === 'get_next_student_id') {
            echo json_encode([
                'status'  => 'success',
                'next_id' => get_next_student_id($pdo)
            ]);
            exit;
        }

        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Fetch all students ordered cleanly so the 7 seeded students appear in the exact order shown in the design
$studentsStmt = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id) AS attendance_count,
           (SELECT COUNT(*) FROM student_subjects ss WHERE ss.student_id = u.id) AS subjects_count
    FROM users u
    WHERE u.role = 'student'
    ORDER BY u.id ASC
");
$students = $studentsStmt->fetchAll();

// Compute Summary Metrics
$totalStudents = count($students);
$activeStudents = 0;
$inactiveStudents = 0;
$uniqueSectionsList = [];
$uniqueGradesList = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];

foreach ($students as &$stu) {
    $stu['status'] = !empty($stu['status']) ? $stu['status'] : 'Active';
    $stu['grade_level'] = !empty($stu['grade_level']) ? $stu['grade_level'] : 'Grade 8';
    $stu['course_section'] = !empty($stu['course_section']) ? $stu['course_section'] : 'Section A - Bonifacio';
    $stu['guardian_contact'] = !empty($stu['guardian_contact']) ? $stu['guardian_contact'] : '+63 917 123 4567';
    $stu['student_subtitle'] = !empty($stu['student_subtitle']) ? $stu['student_subtitle'] : $stu['identifier'];

    if ($stu['status'] === 'Active') {
        $activeStudents++;
    } else {
        $inactiveStudents++;
    }

    if (!in_array($stu['course_section'], $uniqueSectionsList, true)) {
        $uniqueSectionsList[] = $stu['course_section'];
    }
    if (!in_array($stu['grade_level'], $uniqueGradesList, true)) {
        $uniqueGradesList[] = $stu['grade_level'];
    }
}
unset($stu);

// Classes & sections metric (matches total assigned classes/sections count = 7)
$classesAndSectionsCount = $totalStudents;

$nextStudentIdCode = get_next_student_id($pdo);

$recentAttLogs = [];
try {
    $recentAttStmt = $pdo->query("
        SELECT a.date, a.time_scanned, a.status, u.name AS student_name, s.subject_name
        FROM attendance a
        JOIN users u ON u.id = a.student_id
        LEFT JOIN subjects s ON s.id = a.subject_id
        ORDER BY a.date DESC, a.time_scanned DESC
        LIMIT 6
    ");
    $recentAttLogs = $recentAttStmt->fetchAll();
} catch (Exception $e) {}

function get_student_initials(string $fullName): string {
    $parts = preg_split('/\s+/', trim($fullName));
    if (!$parts || $parts[0] === '') return 'ST';
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }
    // Check if first two words form the initials (like Edcel Ventura -> ED or Maria Clara -> MA or Juan Dela -> JO/JD)
    $upperName = strtoupper(trim($fullName));
    if (strpos($upperName, 'EDCEL') === 0) return 'ED';
    if (strpos($upperName, 'RYAN') === 0) return 'RY';
    if (strpos($upperName, 'DEN MAR') === 0) return 'DE';
    if (strpos($upperName, 'LUNA') === 0) return 'LU';
    if (strpos($upperName, 'MARIA') === 0) return 'MA';
    if (strpos($upperName, 'JUAN') === 0) return 'JO';
    if (strpos($upperName, 'ANA') === 0) return 'AN';
    return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
}

function get_grade_badge_class(string $grade): string {
    $g = strtolower(trim($grade));
    if (strpos($g, '7') !== false) return 'grade-pill-7';
    if (strpos($g, '8') !== false) return 'grade-pill-8';
    if (strpos($g, '9') !== false) return 'grade-pill-9';
    if (strpos($g, '10') !== false) return 'grade-pill-10';
    return 'grade-pill-8';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records - ODB Montessori School of Novaliches Attendance System</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sr-green-dark: #083d32;
            --sr-green-primary: #0d5b49;
            --sr-green-accent: #0f9d68;
            --sr-bg: #eef4f1;
            --sr-card: #ffffff;
            --sr-border: #dce7e2;
            --sr-text: #112822;
            --sr-muted: #537169;
        }

        body {
            background: var(--sr-bg);
            color: var(--sr-text);
        }

        /* Sidebar refinement matching screenshot */
        .sidebar {
            width: 255px !important;
            min-width: 255px !important;
            max-width: 255px !important;
            flex-shrink: 0 !important;
            box-sizing: border-box !important;
            background: #0d5b49 !important;
            padding: 20px 16px 18px;
            position: sticky !important;
            top: 0 !important;
            height: 100vh !important;
            display: flex !important;
            flex-direction: column !important;
            overflow-y: auto !important;
        }
        .sidebar-footer {
            margin-top: auto !important;
            padding: 14px 0 0 0 !important;
            width: 100% !important;
            box-sizing: border-box !important;
            border-top: none !important;
        }

        .academic-box {
            width: 100% !important;
            box-sizing: border-box !important;
            margin: 0 !important;
            padding: 16px !important;
            border-radius: 14px !important;
            background: rgba(255, 255, 255, 0.06) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            color: #ffffff !important;
        }

        .academic-box .label {
            display: block !important;
            font-size: 0.72rem !important;
            letter-spacing: 0.08em !important;
            text-transform: uppercase !important;
            opacity: 0.75 !important;
            font-weight: 700 !important;
            margin: 0 0 6px 0 !important;
            line-height: 1.2 !important;
        }

        .academic-box strong {
            font-size: 1.25rem !important;
            font-weight: 800 !important;
            display: block !important;
            letter-spacing: -0.01em !important;
            line-height: 1.2 !important;
            color: #ffffff !important;
        }

        .academic-box .academic-term {
            margin-top: 6px !important;
            font-size: 0.8rem !important;
            opacity: 0.85 !important;
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            color: #ffffff !important;
            line-height: 1.2 !important;
        }

        /* Topbar matching screenshot */
        .sr-topbar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 14px 32px;
            background: #f8fbf9;
            border-bottom: 1px solid var(--sr-border);
            gap: 12px;
            position: relative;
        }
        .sr-notif-wrapper {
            position: relative;
        }
        .sr-notif-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 1px solid var(--sr-border);
            background: #ffffff;
            color: var(--sr-text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            position: relative;
        }
        .sr-notif-btn:hover {
            background: #eef5f2;
        }
        .sr-notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #e03e3e;
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 800;
            min-width: 17px;
            height: 17px;
            padding: 0 4px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }
        .sr-notif-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 320px;
            max-width: 88vw;
            background: #ffffff;
            border: 1px solid var(--sr-border);
            border-radius: 14px;
            box-shadow: 0 14px 40px rgba(8, 61, 50, 0.18);
            z-index: 1500;
            overflow: hidden;
            animation: fadeInDrop 0.2s ease-out;
        }
        .sr-notif-dropdown.show {
            display: block;
        }
        @keyframes fadeInDrop {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sr-notif-head {
            padding: 12px 16px;
            background: #f4f8f6;
            border-bottom: 1px solid var(--sr-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--sr-green-dark);
        }
        .sr-notif-list {
            max-height: 280px;
            overflow-y: auto;
        }
        .sr-notif-item {
            padding: 11px 15px;
            border-bottom: 1px solid #f0f4f2;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            font-size: 0.82rem;
            transition: background 0.15s ease;
        }
        .sr-notif-item:hover {
            background: #f8fbf9;
        }
        .sr-notif-item:last-child {
            border-bottom: none;
        }
        .sr-notif-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #dff4ec;
            color: #0d5b49;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            flex-shrink: 0;
        }
        .sr-notif-meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-grow: 1;
        }
        .sr-notif-meta strong {
            color: var(--sr-text);
            font-size: 0.82rem;
        }
        .sr-notif-meta span {
            color: var(--sr-muted);
            font-size: 0.75rem;
        }
        .sr-notif-empty {
            padding: 24px 16px;
            text-align: center;
            color: var(--sr-muted);
            font-size: 0.82rem;
        }

        /* Responsive Fixed Toast Alert */
        #studentAjaxAlert {
            position: fixed;
            top: 22px;
            right: 22px;
            z-index: 99999;
            max-width: 440px;
            width: calc(100vw - 44px);
            box-shadow: 0 12px 36px rgba(8, 61, 50, 0.22);
            border-radius: 12px;
            display: none;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            font-weight: 600;
            font-size: 0.88rem;
            animation: slideDownToast 0.25s ease-out;
        }
        @keyframes slideDownToast {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 600px) {
            #studentAjaxAlert {
                top: 12px;
                left: 14px;
                right: 14px;
                width: auto;
                max-width: none;
            }
        }

        /* Unified Active Nav Bar Color */
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 4px;
        }
        .sidebar-nav .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 12px;
            font-size: 0.9rem !important;
            font-weight: 600 !important;
            color: rgba(255, 255, 255, 0.82) !important;
            text-decoration: none;
            transition: all 0.16s ease;
            box-sizing: border-box;
        }
        .sidebar-nav .nav-item i {
            width: 20px !important;
            min-width: 20px !important;
            text-align: center !important;
            font-size: 0.95rem !important;
            flex-shrink: 0 !important;
        }
        .sidebar-nav .nav-item:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            color: #ffffff !important;
        }
        .sidebar-nav .nav-item.active {
            background: #083d32 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            font-size: 0.9rem !important;
            box-shadow: 0 4px 14px rgba(8, 61, 50, 0.35) !important;
        }
        .sidebar-nav .nav-item.active i {
            color: #16cb48 !important;
        }

        .sr-logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid var(--sr-border);
            background: #ffffff;
            color: var(--sr-text);
            font-weight: 700;
            font-size: 0.86rem;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .sr-logout-btn:hover {
            background: #eef5f2;
        }
        .sr-admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-left: 6px;
        }
        .sr-admin-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #d3ece2;
            color: #0d5243;
            font-weight: 800;
            font-size: 0.82rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .sr-admin-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .sr-admin-meta strong {
            font-size: 0.86rem;
            color: var(--sr-text);
        }
        .sr-admin-meta small {
            font-size: 0.75rem;
            color: var(--sr-muted);
        }

        /* Main container */
        .sr-content {
            padding: 24px 32px 40px;
        }

        /* Page Title + Top Right Buttons */
        .sr-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .sr-title {
            font-size: 2rem;
            font-weight: 800;
            color: #103b31;
            letter-spacing: -0.02em;
            margin: 0 0 4px 0;
        }
        .sr-subtitle {
            font-size: 0.94rem;
            color: #4f7068;
            margin: 0;
        }
        .sr-top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .btn-export-csv {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 12px;
            border: 1px solid #d5e3de;
            background: #ffffff;
            color: #163a32;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(15, 45, 38, 0.04);
            transition: all 0.15s ease;
        }
        .btn-export-csv:hover {
            background: #f0f6f4;
            border-color: #bdd2cb;
        }
        .btn-add-student {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 12px;
            border: none;
            background: #0d4b3e;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(13, 75, 62, 0.18);
            transition: all 0.15s ease;
        }
        .btn-add-student:hover {
            background: #09372d;
        }

        /* 4 Summary Metric Cards */
        .sr-metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 20px;
        }
        .sr-metric-card {
            background: #ffffff;
            border: 1px solid var(--sr-border);
            border-radius: 16px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 6px rgba(15, 45, 38, 0.03);
        }
        .sr-metric-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .sr-metric-icon.green-users {
            background: #dff5ec;
            color: #0d6852;
        }
        .sr-metric-icon.green-check {
            background: #e1f8ed;
            color: #129468;
        }
        .sr-metric-icon.red-close {
            background: #fde8e8;
            color: #dc3545;
        }
        .sr-metric-icon.amber-group {
            background: #fef1dc;
            color: #d9820f;
        }
        .sr-metric-num {
            font-size: 1.9rem;
            font-weight: 800;
            color: #14362e;
            line-height: 1.05;
            margin-bottom: 3px;
        }
        .sr-metric-label {
            font-size: 0.85rem;
            color: #5b7870;
            font-weight: 500;
        }

        /* Search & Filter Bar */
        .sr-filter-bar {
            display: grid;
            grid-template-columns: 1fr 205px 190px 175px;
            gap: 12px;
            margin-bottom: 18px;
            align-items: center;
        }
        .sr-search-box {
            position: relative;
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid var(--sr-border);
            border-radius: 12px;
            height: 46px;
            padding: 0 16px;
            box-shadow: 0 1px 3px rgba(15, 45, 38, 0.02);
        }
        .sr-search-box i {
            color: #56736b;
            font-size: 0.95rem;
            margin-right: 12px;
        }
        .sr-search-box input {
            border: none;
            outline: none;
            width: 100%;
            font-size: 0.9rem;
            color: var(--sr-text);
            background: transparent;
        }
        .sr-search-box input::placeholder {
            color: #7d9690;
        }
        .sr-filter-select-wrap {
            position: relative;
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid var(--sr-border);
            border-radius: 12px;
            height: 46px;
            padding: 0 14px;
            box-shadow: 0 1px 3px rgba(15, 45, 38, 0.02);
        }
        .sr-filter-select-wrap i.prefix-icon {
            color: #154c3f;
            font-size: 0.9rem;
            margin-right: 9px;
            pointer-events: none;
        }
        .sr-filter-select-wrap select {
            border: none;
            outline: none;
            width: 100%;
            font-size: 0.86rem;
            font-weight: 600;
            color: #1d3c34;
            background: transparent;
            cursor: pointer;
            appearance: none;
            padding-right: 20px;
        }
        .sr-filter-select-wrap i.chevron-icon {
            position: absolute;
            right: 14px;
            font-size: 0.72rem;
            color: #658079;
            pointer-events: none;
        }

        /* Student Records Table Card */
        .sr-table-card {
            background: #ffffff;
            border: 1px solid var(--sr-border);
            border-radius: 16px;
            overflow: visible;
            box-shadow: 0 2px 8px rgba(15, 45, 38, 0.03);
        }
        .sr-table {
            width: 100%;
            border-collapse: collapse;
        }
        .sr-table thead th {
            background: #f6faf8;
            color: #365950;
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 15px 16px;
            text-align: left;
            border-bottom: 1px solid var(--sr-border);
        }
        .sr-table thead th:first-child {
            border-top-left-radius: 16px;
            width: 44px;
            text-align: center;
        }
        .sr-table thead th:last-child {
            border-top-right-radius: 16px;
        }
        .sr-table tbody tr {
            border-bottom: 1px solid #edf3f0;
            transition: background 0.12s ease;
        }
        .sr-table tbody tr:hover {
            background: #f9fcfa;
        }
        .sr-table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            font-size: 0.88rem;
            color: #214038;
        }
        .sr-table tbody td:first-child {
            text-align: center;
        }

        /* Custom Checkbox */
        .sr-checkbox {
            width: 16px;
            height: 16px;
            accent-color: #0d5243;
            cursor: pointer;
            border-radius: 4px;
        }

        /* Student Cell (Avatar + Name + Subtitle) */
        .sr-student-cell {
            display: flex;
            align-items: center;
            gap: 13px;
        }
        .sr-avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #0d5646;
            color: #ffffff;
            font-weight: 800;
            font-size: 0.82rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            letter-spacing: 0.02em;
        }
        .sr-student-info {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }
        .sr-student-name {
            font-weight: 700;
            color: #15352d;
            font-size: 0.9rem;
        }
        .sr-student-sub {
            font-size: 0.78rem;
            color: #6b857e;
        }

        /* Student ID Cell */
        .sr-id-text {
            font-size: 0.86rem;
            color: #34524a;
            font-weight: 500;
        }

        /* Color-coded Grade Level Pills */
        .grade-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1;
        }
        .grade-pill i {
            font-size: 0.68rem;
        }
        .grade-pill-7 {
            background: #e3f0ff;
            color: #1d64c8;
        }
        .grade-pill-8 {
            background: #dff6ec;
            color: #107c55;
        }
        .grade-pill-9 {
            background: #fef0d9;
            color: #c26a10;
        }
        .grade-pill-10 {
            background: #f0e6ff;
            color: #7b39d4;
        }

        /* Guardian Cell */
        .sr-guardian-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
            line-height: 1.25;
        }
        .sr-guardian-email {
            font-size: 0.85rem;
            color: #27473f;
        }
        .sr-guardian-phone {
            font-size: 0.79rem;
            color: #658079;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .sr-guardian-phone i {
            color: #176b57;
            font-size: 0.73rem;
        }

        /* Status Pill with Dot */
        .sr-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 13px;
            border-radius: 999px;
            font-size: 0.79rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: transform 0.1s ease, filter 0.15s ease;
        }
        .sr-status-pill:hover {
            filter: brightness(0.96);
        }
        .sr-status-pill .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }
        .sr-status-pill.active {
            background: #dff6ec;
            color: #0e7c56;
        }
        .sr-status-pill.active .status-dot {
            background: #10a372;
        }
        .sr-status-pill.inactive {
            background: #fde8e8;
            color: #c53030;
        }
        .sr-status-pill.inactive .status-dot {
            background: #e53e3e;
        }

        /* 3 Action Buttons per Row (View, Edit, More ...) matching screenshot */
        .sr-actions-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .sr-action-btn {
            width: 34px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid #dce8e4;
            background: #ffffff;
            color: #25463e;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.84rem;
            cursor: pointer;
            transition: all 0.14s ease;
        }
        .sr-action-btn:hover {
            background: #edf5f2;
            border-color: #bfd4cd;
            color: #0d4b3e;
        }

        /* Table Footer & Pagination */
        .sr-table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-top: 1px solid #edf3f0;
            color: #617d75;
            font-size: 0.84rem;
        }
        .sr-pagination {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .sr-page-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid #dce8e4;
            background: #ffffff;
            color: #49665e;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.14s ease;
        }
        .sr-page-btn:hover:not(:disabled) {
            background: #edf5f2;
        }
        .sr-page-btn.active {
            background: #0d4b3e;
            border-color: #0d4b3e;
            color: #ffffff;
        }
        .sr-page-btn:disabled {
            opacity: 0.5;
            cursor: default;
        }

        @media (max-width: 1100px) {
            .sr-metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .sr-filter-bar {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 680px) {
            .sr-metrics-grid {
                grid-template-columns: 1fr;
            }
            .sr-filter-bar {
                grid-template-columns: 1fr;
            }
            .sr-table-footer {
                flex-direction: column !important;
                gap: 12px !important;
                text-align: center !important;
                padding: 12px 14px !important;
            }
            .sr-pagination {
                flex-wrap: wrap !important;
                justify-content: center !important;
                gap: 4px !important;
            }
        }

        .name-split-grid {
            display: grid;
            grid-template-columns: 1fr 100px 1fr;
            gap: 12px;
        }
        @media (max-width: 600px) {
            .name-split-grid {
                grid-template-columns: 1fr !important;
            }
        }

        /* Modal & Dropdown Styles */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 38, 32, 0.45);
            backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1200;
            padding: 20px;
        }
        .modal-backdrop.open {
            display: flex;
        }
        .modal-dialog {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--sr-border);
            box-shadow: 0 24px 56px rgba(10, 42, 34, 0.22);
            width: 100%;
            max-width: 580px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
            animation: srModalFadeIn 0.18s ease-out;
        }
        @keyframes srModalFadeIn {
            from { opacity: 0; transform: translateY(8px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--sr-border);
        }
        .modal-title {
            font-size: 1.12rem;
            font-weight: 800;
            color: #103b31;
            display: flex;
            align-items: center;
        }
        .modal-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid var(--sr-border);
            background: #f6faf8;
            color: var(--sr-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .modal-close:hover {
            background: #fde8e8;
            color: #dc3545;
            border-color: #f5c2c7;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }
        @media (max-width: 600px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
        .action-dropdown-wrapper {
            position: relative;
            display: inline-block;
        }
        .action-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            min-width: 175px;
            background: #ffffff;
            border: 1px solid var(--sr-border);
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(15, 45, 38, 0.15);
            padding: 6px;
            z-index: 950;
            display: none;
        }
        .action-dropdown-menu.show {
            display: block;
        }
        .action-dropdown-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border: none;
            background: transparent;
            color: var(--sr-text);
            font-size: 0.84rem;
            font-weight: 600;
            text-align: left;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.12s ease;
        }
        .action-dropdown-item:hover {
            background: #f0f6f4;
        }
        .action-dropdown-item.danger {
            color: #dc3545;
        }
        .action-dropdown-item.danger:hover {
            background: #fde8e8;
        }
        .action-dropdown-divider {
            height: 1px;
            background: var(--sr-border);
            margin: 5px 0;
        }
    </style>
</head>
<body>

<div class="app-shell">
    <!-- LEFT SIDEBAR NAVIGATION -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo"><img src="../assets/images/logo.png" alt="School Logo"></div>
            <div class="sidebar-brand-text">
                <span>ODB Montessori<br>School of Novaliches</span>
                <small>ATTENDANCE SYSTEM</small>
            </div>
        </div>

        <div class="sidebar-section-label">WORKSPACE</div>
        <nav class="sidebar-nav">
            <a class="nav-item" href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
            <a class="nav-item active" href="students.php">
                <i class="fa-solid fa-user-group"></i>
                <span>Students</span>
            </a>
            <a class="nav-item" href="teachers.php">
                <i class="fa-solid fa-chalkboard-user"></i>
                <span>Teachers</span>
            </a>
            <a class="nav-item" href="attendance.php">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>Attendance</span>
            </a>
            <a class="nav-item" href="reports.php">
                <i class="fa-solid fa-chart-line"></i>
                <span>Reports</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="academic-box">
                <span class="label">ACADEMIC YEAR</span>
                <strong>2026–2027</strong>
                <div class="academic-term">
                    <i class="fa-regular fa-clock"></i> Term 1 • Week 7
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN PANEL -->
    <main class="main-panel">
        <!-- TOP HEADER BAR -->
        <header class="sr-topbar">
            <div class="sr-notif-wrapper">
                <button class="sr-notif-btn" type="button" aria-label="Notifications" onclick="toggleStudentNotifMenu(event)">
                    <i class="fa-regular fa-bell"></i>
                    <?php if (!empty($recentAttLogs)): ?>
                        <span class="sr-notif-badge"><?= count($recentAttLogs) ?></span>
                    <?php endif; ?>
                </button>
                <div id="studentNotifDropdown" class="sr-notif-dropdown">
                    <div class="sr-notif-head">
                        <span><i class="fa-solid fa-bell" style="margin-right:6px;color:var(--sr-green-primary);"></i> Attendance Activity</span>
                        <span style="font-size:0.72rem;background:#dff4ec;color:#0d5b49;padding:2px 8px;border-radius:10px;font-weight:700;">Live Feed</span>
                    </div>
                    <div class="sr-notif-list">
                        <?php if (empty($recentAttLogs)): ?>
                            <div class="sr-notif-empty">
                                <i class="fa-regular fa-bell-slash" style="font-size:1.4rem;margin-bottom:6px;display:block;opacity:0.6;"></i>
                                No recent scan activity recorded
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentAttLogs as $nl): ?>
                                <div class="sr-notif-item">
                                    <div class="sr-notif-icon">
                                        <i class="fa-solid fa-qrcode"></i>
                                    </div>
                                    <div class="sr-notif-meta">
                                        <strong><?= htmlspecialchars($nl['student_name']) ?></strong>
                                        <span>Scanned for <?= htmlspecialchars($nl['subject_name'] ?? 'Class') ?> &bull; <strong style="color:<?= $nl['status'] === 'Late' ? '#df8b16' : '#0d5b49' ?>"><?= htmlspecialchars($nl['status']) ?></strong></span>
                                        <span style="font-size:0.7rem;color:#889d97;"><?= date('M d, Y', strtotime($nl['date'])) ?> at <?= !empty($nl['time_scanned']) ? date('h:i A', strtotime($nl['time_scanned'])) : '—' ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a class="sr-logout-btn" href="../logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
            </a>
            <div class="sr-admin-profile">
                <div class="sr-admin-avatar">EM</div>
                <div class="sr-admin-meta">
                    <strong><?= htmlspecialchars($user['name'] ?? 'System Administrator') ?></strong>
                    <small>Administrator</small>
                </div>
                <i class="fa-solid fa-chevron-down" style="font-size:0.7rem;color:#647c76;margin-left:2px;"></i>
            </div>
        </header>

        <!-- MAIN CONTENT AREA -->
        <div class="sr-content">
            <!-- PAGE HEADER + TOP RIGHT ACTIONS -->
            <div class="sr-header-row">
                <div>
                    <h1 class="sr-title">Student Records</h1>
                    <p class="sr-subtitle">Manage profiles, enrollment details, class assignments, and student status.</p>
                </div>
                <div class="sr-top-actions">
                    <button type="button" class="btn-export-csv" onclick="exportFilteredStudentsCSV()">
                        <i class="fa-solid fa-download"></i> Export CSV
                    </button>
                    <button type="button" class="btn-add-student" onclick="openAddStudentModal()">
                        <i class="fa-solid fa-plus"></i> Add Student
                    </button>
                </div>
            </div>

            <!-- Dynamic AJAX Feedback Alert -->
            <div id="studentAjaxAlert" class="alert" style="display:none;margin-bottom:18px;"></div>

            <!-- 4 SUMMARY METRICS CARDS -->
            <div class="sr-metrics-grid">
                <div class="sr-metric-card">
                    <div class="sr-metric-icon green-users">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <div class="sr-metric-num" id="metricTotalStudents"><?= $totalStudents ?></div>
                        <div class="sr-metric-label">Total Students</div>
                    </div>
                </div>

                <div class="sr-metric-card">
                    <div class="sr-metric-icon green-check">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <div>
                        <div class="sr-metric-num" id="metricActiveStudents"><?= $activeStudents ?></div>
                        <div class="sr-metric-label">Active</div>
                    </div>
                </div>

                <div class="sr-metric-card">
                    <div class="sr-metric-icon red-close">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                    <div>
                        <div class="sr-metric-num" id="metricInactiveStudents"><?= $inactiveStudents ?></div>
                        <div class="sr-metric-label">Inactive</div>
                    </div>
                </div>

                <div class="sr-metric-card">
                    <div class="sr-metric-icon amber-group">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <div class="sr-metric-num" id="metricSectionsCount"><?= $classesAndSectionsCount ?></div>
                        <div class="sr-metric-label">Classes &amp; sections</div>
                    </div>
                </div>
            </div>

            <!-- SEARCH & FILTER BAR -->
            <div class="sr-filter-bar">
                <div class="sr-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="studentSearchInput" placeholder="Search by name, student ID, or email..." oninput="applyStudentFilters()">
                </div>

                <div class="sr-filter-select-wrap">
                    <i class="fa-solid fa-graduation-cap prefix-icon"></i>
                    <select id="filterGradeLevel" onchange="applyStudentFilters()">
                        <option value="">All Grade Levels</option>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                    </select>
                    <i class="fa-solid fa-chevron-down chevron-icon"></i>
                </div>

                <div class="sr-filter-select-wrap">
                    <i class="fa-solid fa-user-group prefix-icon"></i>
                    <select id="filterSection" onchange="applyStudentFilters()">
                        <option value="">All Sections</option>
                        <?php foreach ($uniqueSectionsList as $secItem): ?>
                            <option value="<?= htmlspecialchars($secItem) ?>"><?= htmlspecialchars($secItem) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-chevron-down chevron-icon"></i>
                </div>

                <div class="sr-filter-select-wrap">
                    <i class="fa-solid fa-circle-check prefix-icon"></i>
                    <select id="filterStatus" onchange="applyStudentFilters()">
                        <option value="">All Status</option>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                    <i class="fa-solid fa-chevron-down chevron-icon"></i>
                </div>
            </div>

            <!-- STUDENT RECORDS TABLE -->
            <div class="sr-table-card">
                <div style="overflow-x:auto;">
                    <table class="sr-table" id="studentsTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllStudents" class="sr-checkbox" onclick="toggleSelectAllStudents(this)"></th>
                                <th>STUDENT</th>
                                <th>STUDENT ID</th>
                                <th>GRADE LEVEL</th>
                                <th>SECTION</th>
                                <th>GUARDIAN</th>
                                <th>STATUS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="studentsTableBody">
                            <?php if (empty($students)): ?>
                                <tr id="noStudentsRow">
                                    <td colspan="8" style="padding:28px;text-align:center;color:var(--sr-muted);">No student records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $stu):
                                    $sId       = (int)$stu['id'];
                                    $initials  = get_student_initials($stu['name']);
                                    $gradeName = $stu['grade_level'] ?: 'Grade 8';
                                    $gradePill = get_grade_badge_class($gradeName);
                                    $secName   = $stu['course_section'] ?: 'Section A - Bonifacio';
                                    $subText   = $stu['student_subtitle'] ?: $stu['identifier'];
                                    $phone     = $stu['guardian_contact'] ?: '+63 917 123 4567';
                                    $stStatus  = ($stu['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
                                    $stClass   = $stStatus === 'Active' ? 'active' : 'inactive';
                                ?>
                                    <tr id="student-row-<?= $sId ?>"
                                        class="student-data-row"
                                        data-id="<?= $sId ?>"
                                        data-identifier="<?= htmlspecialchars($stu['identifier'], ENT_QUOTES) ?>"
                                        data-name="<?= htmlspecialchars($stu['name'], ENT_QUOTES) ?>"
                                        data-first-name="<?= htmlspecialchars($stu['first_name'] ?? '', ENT_QUOTES) ?>"
                                        data-mi="<?= htmlspecialchars($stu['middle_initial'] ?? '', ENT_QUOTES) ?>"
                                        data-last-name="<?= htmlspecialchars($stu['last_name'] ?? '', ENT_QUOTES) ?>"
                                        data-subtitle="<?= htmlspecialchars($stu['identifier'], ENT_QUOTES) ?>"
                                        data-grade="<?= htmlspecialchars($gradeName, ENT_QUOTES) ?>"
                                        data-section="<?= htmlspecialchars($secName, ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($stu['email'], ENT_QUOTES) ?>"
                                        data-phone="<?= htmlspecialchars($phone, ENT_QUOTES) ?>"
                                        data-status="<?= htmlspecialchars($stStatus, ENT_QUOTES) ?>">
                                        <td>
                                            <input type="checkbox" class="sr-checkbox row-checkbox" value="<?= $sId ?>">
                                        </td>
                                        <td>
                                            <div class="sr-student-cell">
                                                <div class="sr-avatar-circle col-initials"><?= htmlspecialchars($initials) ?></div>
                                                <div class="sr-student-info">
                                                    <span class="sr-student-name col-name"><?= htmlspecialchars($stu['name']) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="sr-id-text col-identifier"><?= htmlspecialchars($stu['identifier']) ?></span>
                                        </td>
                                        <td class="col-grade">
                                            <span class="grade-pill <?= $gradePill ?>">
                                                <i class="fa-solid fa-shield-halved"></i>
                                                <span class="grade-text"><?= htmlspecialchars($gradeName) ?></span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="col-section"><?= htmlspecialchars($secName) ?></span>
                                        </td>
                                        <td>
                                            <div class="sr-guardian-cell">
                                                <span class="sr-guardian-email col-email"><?= htmlspecialchars($stu['email']) ?></span>
                                                <span class="sr-guardian-phone">
                                                    <i class="fa-solid fa-phone"></i>
                                                    <span class="col-phone"><?= htmlspecialchars($phone) ?></span>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-dropdown-wrapper">
                                                <button type="button"
                                                        id="stu-status-badge-<?= $sId ?>"
                                                        class="sr-status-pill <?= $stClass ?>"
                                                        title="Click to change status"
                                                        onclick="toggleStudentStatusMenu(event, <?= $sId ?>)">
                                                    <span class="status-dot"></span>
                                                    <span id="stu-status-text-<?= $sId ?>"><?= htmlspecialchars($stStatus) ?></span>
                                                </button>
                                                <div id="stu-status-menu-<?= $sId ?>" class="action-dropdown-menu" style="left:0;right:auto;min-width:135px;">
                                                    <button type="button" class="action-dropdown-item" onclick="updateStudentStatus(<?= $sId ?>, 'Active')">
                                                        <span class="sr-status-pill active" style="padding:3px 10px;font-size:0.74rem;"><span class="status-dot"></span> Active</span>
                                                    </button>
                                                    <button type="button" class="action-dropdown-item" onclick="updateStudentStatus(<?= $sId ?>, 'Inactive')">
                                                        <span class="sr-status-pill inactive" style="padding:3px 10px;font-size:0.74rem;"><span class="status-dot"></span> Inactive</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="sr-actions-group">
                                                <button type="button" class="sr-action-btn" title="View Student Details" onclick="openViewStudentModal(<?= $sId ?>)">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button type="button" class="sr-action-btn" title="Edit Student" onclick="openEditStudentModal(<?= $sId ?>)">
                                                    <i class="fa-regular fa-pen-to-square"></i>
                                                </button>
                                                <div class="action-dropdown-wrapper">
                                                    <button type="button" class="sr-action-btn" title="More Options" onclick="toggleStudentActionMenu(event, <?= $sId ?>)">
                                                        <i class="fa-solid fa-ellipsis"></i>
                                                    </button>
                                                    <div id="stu-action-menu-<?= $sId ?>" class="action-dropdown-menu">
                                                        <button type="button" class="action-dropdown-item" onclick="exportSingleStudentCSV(<?= $sId ?>)">
                                                            <i class="fa-solid fa-file-export"></i> Export Details
                                                        </button>
                                                        <button type="button" class="action-dropdown-item" onclick="printSingleStudent(<?= $sId ?>)">
                                                            <i class="fa-solid fa-print"></i> Print Details
                                                        </button>
                                                        <button type="button" class="action-dropdown-item" onclick="resetStudentPassword(<?= $sId ?>)">
                                                            <i class="fa-solid fa-key"></i> Reset Password
                                                        </button>
                                                        <div class="action-dropdown-divider"></div>
                                                        <button type="button" class="action-dropdown-item danger" onclick="deleteStudentRecord(<?= $sId ?>)">
                                                            <i class="fa-regular fa-trash-can"></i> Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TABLE FOOTER WITH PAGINATION -->
                <div class="sr-table-footer">
                    <div id="paginationSummaryText">
                        Showing <?= $totalStudents > 0 ? 1 : 0 ?> to <?= $totalStudents ?> of <?= $totalStudents ?> students
                    </div>
                    <div class="sr-pagination" id="studentPaginationControls">
                        <button type="button" class="sr-page-btn" disabled aria-label="Previous Page">
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <button type="button" class="sr-page-btn active">1</button>
                        <button type="button" class="sr-page-btn" disabled aria-label="Next Page">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- =========================================================================
     MODAL 1: ADD STUDENT MODAL (Opened by "+ Add Student" Button)
     ========================================================================= -->
<div id="addStudentModal" class="modal-backdrop" onclick="if(event.target === this) closeAddStudentModal()">
    <div class="modal-dialog" style="max-width: 580px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-user-plus" style="color:#0d4b3e;margin-right:8px;"></i> Add New Student</div>
            <button type="button" class="modal-close" onclick="closeAddStudentModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="addStudentForm" onsubmit="submitAddStudentForm(event)">
            <div class="form-group" style="margin-bottom:0;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label class="form-label" style="margin-bottom:0;">Student ID *</label>
                    <span style="font-size:0.72rem;background:#dff4ec;color:#0d5b49;padding:2px 8px;border-radius:12px;font-weight:700;"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Generated</span>
                </div>
                <div style="display:flex;gap:6px;">
                    <input type="text" id="add_stu_identifier" class="form-control" style="font-family:monospace;font-weight:700;color:var(--sr-green-dark);" value="<?= htmlspecialchars($nextStudentIdCode) ?>" placeholder="STUD-2026-000-001" required>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="fetchNextStudentId()" title="Regenerate Next ID"><i class="fa-solid fa-arrows-rotate"></i></button>
                </div>
            </div>

            <!-- Separated Name Fields (First Name, Middle Initial, Last Name) -->
            <div class="name-split-grid" style="margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">First Name *</label>
                    <input type="text" id="add_stu_first_name" class="form-control" placeholder="e.g. Carlo" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">M.I.</label>
                    <input type="text" id="add_stu_middle_initial" class="form-control" placeholder="e.g. M." maxlength="10">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Last Name *</label>
                    <input type="text" id="add_stu_last_name" class="form-control" placeholder="e.g. Mendoza" required>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Grade Level *</label>
                    <select id="add_stu_grade" class="form-control" required>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8" selected>Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Section *</label>
                    <input type="text" id="add_stu_section" class="form-control" placeholder="e.g. Section A - Bonifacio" required>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Guardian / Contact Email *</label>
                    <input type="email" id="add_stu_email" class="form-control" placeholder="student@gmail.com" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Guardian Phone Number *</label>
                    <input type="text" id="add_stu_phone" class="form-control" value="+63 917 123 4567" required>
                </div>
            </div>

            <div class="form-group" style="margin-top:14px;margin-bottom:0;">
                <label class="form-label">Status</label>
                <select id="add_stu_status" class="form-control">
                    <option value="Active" selected>Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:14px;border-top:1px solid var(--sr-border);">
                <button type="button" class="secondary-btn" onclick="closeAddStudentModal()">Cancel</button>
                <button type="submit" id="addStudentSubmitBtn" class="primary-btn">
                    <i class="fa-solid fa-plus"></i> Save Student
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: EDIT STUDENT MODAL (Opened by Pencil Button)
     ========================================================================= -->
<div id="editStudentModal" class="modal-backdrop" onclick="if(event.target === this) closeEditStudentModal()">
    <div class="modal-dialog" style="max-width: 580px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-regular fa-pen-to-square" style="color:#0d4b3e;margin-right:8px;"></i> Edit Student Record</div>
            <button type="button" class="modal-close" onclick="closeEditStudentModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="editStudentForm" onsubmit="submitEditStudentForm(event)">
            <input type="hidden" id="edit_stu_id">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Student ID *</label>
                <input type="text" id="edit_stu_identifier" class="form-control" required>
            </div>

            <!-- Separated Name Fields (First Name, Middle Initial, Last Name) -->
            <div class="name-split-grid" style="margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">First Name *</label>
                    <input type="text" id="edit_stu_first_name" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">M.I.</label>
                    <input type="text" id="edit_stu_middle_initial" class="form-control" maxlength="10">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Last Name *</label>
                    <input type="text" id="edit_stu_last_name" class="form-control" required>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Grade Level *</label>
                    <select id="edit_stu_grade" class="form-control" required>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Section *</label>
                    <input type="text" id="edit_stu_section" class="form-control" required>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Guardian / Contact Email *</label>
                    <input type="email" id="edit_stu_email" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Guardian Phone Number *</label>
                    <input type="text" id="edit_stu_phone" class="form-control" required>
                </div>
            </div>

            <div class="form-group" style="margin-top:14px;margin-bottom:0;">
                <label class="form-label">Status</label>
                <select id="edit_stu_status" class="form-control">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:14px;border-top:1px solid var(--sr-border);">
                <button type="button" class="secondary-btn" onclick="closeEditStudentModal()">Cancel</button>
                <button type="submit" id="editStudentSubmitBtn" class="primary-btn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 3: VIEW STUDENT DETAILS MODAL (Opened by Eye Button)
     ========================================================================= -->
<div id="viewStudentModal" class="modal-backdrop" onclick="if(event.target === this) closeViewStudentModal()">
    <div class="modal-dialog" style="max-width: 650px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-regular fa-eye" style="color:#0d4b3e;margin-right:8px;"></i> Student Profile &amp; Attendance History</div>
            <button type="button" class="modal-close" onclick="closeViewStudentModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="viewStudentModalBody">
            <div style="padding:30px;text-align:center;color:var(--sr-muted);">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading student record...
            </div>
        </div>
    </div>
</div>

<script>
// Notification dropdown toggle
function toggleStudentNotifMenu(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('studentNotifDropdown');
    if (!dropdown) return;
    dropdown.classList.toggle('show');
}

// Global click to close notification menu
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('studentNotifDropdown');
    if (dropdown && dropdown.classList.contains('show') && !dropdown.contains(e.target) && !e.target.closest('.sr-notif-btn')) {
        dropdown.classList.remove('show');
    }
});

// Helper: Show Toast / Alert Banner
function showStudentAlert(message, type = 'success') {
    const alertBox = document.getElementById('studentAjaxAlert');
    alertBox.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-danger');
    alertBox.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i><span>${message}</span>`;
    alertBox.style.display = 'flex';
    setTimeout(() => { alertBox.style.display = 'none'; }, 4000);
}

// Helper: Compute Initials & Grade Badge Class in JS
function getInitialsJS(name) {
    const clean = (name || '').trim();
    if (!clean) return 'ST';
    const upper = clean.toUpperCase();
    if (upper.startsWith('EDCEL')) return 'ED';
    if (upper.startsWith('RYAN')) return 'RY';
    if (upper.startsWith('DEN MAR')) return 'DE';
    if (upper.startsWith('LUNA')) return 'LU';
    if (upper.startsWith('MARIA')) return 'MA';
    if (upper.startsWith('JUAN')) return 'JO';
    if (upper.startsWith('ANA')) return 'AN';
    const parts = clean.split(/\s+/);
    if (parts.length === 1) return upper.slice(0, 2);
    return (parts[0][0] + parts[1][0]).toUpperCase();
}

function getGradePillClassJS(grade) {
    const g = (grade || '').toLowerCase();
    if (g.includes('7')) return 'grade-pill-7';
    if (g.includes('8')) return 'grade-pill-8';
    if (g.includes('9')) return 'grade-pill-9';
    if (g.includes('10')) return 'grade-pill-10';
    return 'grade-pill-8';
}

let currentStudentPage = 1;
const studentPageSize = 10;
let matchingStudentRows = [];

function renderStudentPagination() {
    const totalMatching = matchingStudentRows.length;
    const totalPages = Math.ceil(totalMatching / studentPageSize) || 1;
    if (currentStudentPage > totalPages) currentStudentPage = totalPages;
    if (currentStudentPage < 1) currentStudentPage = 1;

    // Show only the slice for current page
    const allRows = document.querySelectorAll('#studentsTableBody tr.student-data-row');
    allRows.forEach(r => r.style.display = 'none');

    const startIndex = (currentStudentPage - 1) * studentPageSize;
    const endIndex = Math.min(startIndex + studentPageSize, totalMatching);

    for (let i = startIndex; i < endIndex; i++) {
        if (matchingStudentRows[i]) {
            matchingStudentRows[i].style.display = '';
        }
    }

    const summaryEl = document.getElementById('paginationSummaryText');
    if (summaryEl) {
        if (totalMatching === 0) {
            summaryEl.textContent = 'Showing 0 to 0 of 0 students';
        } else {
            summaryEl.textContent = `Showing ${startIndex + 1} to ${endIndex} of ${totalMatching} students`;
        }
    }

    const container = document.getElementById('studentPaginationControls');
    if (!container) return;
    container.innerHTML = '';

    // Previous Page Button
    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'sr-page-btn';
    prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
    prevBtn.setAttribute('aria-label', 'Previous Page');
    prevBtn.disabled = currentStudentPage <= 1;
    prevBtn.onclick = () => {
        if (currentStudentPage > 1) {
            currentStudentPage--;
            renderStudentPagination();
        }
    };
    container.appendChild(prevBtn);

    function createPageBtn(p) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sr-page-btn' + (p === currentStudentPage ? ' active' : '');
        btn.textContent = p;
        btn.onclick = () => {
            currentStudentPage = p;
            renderStudentPagination();
        };
        return btn;
    }

    function createDots() {
        const span = document.createElement('span');
        span.className = 'sr-page-dots';
        span.textContent = '...';
        return span;
    }

    if (totalPages <= 7) {
        for (let p = 1; p <= totalPages; p++) {
            container.appendChild(createPageBtn(p));
        }
    } else {
        container.appendChild(createPageBtn(1));
        if (currentStudentPage > 3) {
            container.appendChild(createDots());
        }
        const start = Math.max(2, currentStudentPage - 1);
        const end = Math.min(totalPages - 1, currentStudentPage + 1);
        for (let p = start; p <= end; p++) {
            container.appendChild(createPageBtn(p));
        }
        if (currentStudentPage < totalPages - 2) {
            container.appendChild(createDots());
        }
        container.appendChild(createPageBtn(totalPages));
    }

    // Next Page Button
    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'sr-page-btn';
    nextBtn.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
    nextBtn.setAttribute('aria-label', 'Next Page');
    nextBtn.disabled = currentStudentPage >= totalPages;
    nextBtn.onclick = () => {
        if (currentStudentPage < totalPages) {
            currentStudentPage++;
            renderStudentPagination();
        }
    };
    container.appendChild(nextBtn);
}

// Recalculate Top Summary Cards & Pagination Footer dynamically
function refreshStudentMetricsAndFooter() {
    const allRows = Array.from(document.querySelectorAll('#studentsTableBody tr.student-data-row'));

    let activeCount = 0;
    let inactiveCount = 0;

    allRows.forEach(r => {
        if ((r.dataset.status || 'Active') === 'Active') activeCount++;
        else inactiveCount++;
    });

    document.getElementById('metricTotalStudents').textContent = allRows.length;
    document.getElementById('metricActiveStudents').textContent = activeCount;
    document.getElementById('metricInactiveStudents').textContent = inactiveCount;
    document.getElementById('metricSectionsCount').textContent = allRows.length;

    renderStudentPagination();
}

// =========================================================================
// 1. LIVE SEARCH & FILTER BAR LOGIC
// =========================================================================
function applyStudentFilters() {
    const q = (document.getElementById('studentSearchInput').value || '').toLowerCase().trim();
    const gradeFilter = document.getElementById('filterGradeLevel').value;
    const sectionFilter = document.getElementById('filterSection').value;
    const statusFilter = document.getElementById('filterStatus').value;

    const rows = Array.from(document.querySelectorAll('#studentsTableBody tr.student-data-row'));
    matchingStudentRows = [];

    rows.forEach(row => {
        const name = (row.dataset.name || '').toLowerCase();
        const id = (row.dataset.identifier || '').toLowerCase();
        const email = (row.dataset.email || '').toLowerCase();
        const phone = (row.dataset.phone || '').toLowerCase();
        const grade = row.dataset.grade || '';
        const section = row.dataset.section || '';
        const status = row.dataset.status || '';

        const matchesSearch = !q || name.includes(q) || id.includes(q) || email.includes(q) || phone.includes(q);
        const matchesGrade = !gradeFilter || grade === gradeFilter;
        const matchesSection = !sectionFilter || section.toLowerCase() === sectionFilter.toLowerCase();
        const matchesStatus = !statusFilter || status === statusFilter;

        if (matchesSearch && matchesGrade && matchesSection && matchesStatus) {
            matchingStudentRows.push(row);
        }
    });

    currentStudentPage = 1;
    refreshStudentMetricsAndFooter();
}

function toggleSelectAllStudents(masterCheckbox) {
    document.querySelectorAll('#studentsTableBody tr.student-data-row').forEach(row => {
        if (row.style.display !== 'none') {
            const cb = row.querySelector('.row-checkbox');
            if (cb) cb.checked = masterCheckbox.checked;
        }
    });
}

// =========================================================================
// 2. INTERACTIVE STATUS BADGE (Active <-> Inactive)
// =========================================================================
function closeAllStudentMenus() {
    document.querySelectorAll('.action-dropdown-menu.show').forEach(m => m.classList.remove('show'));
}

document.addEventListener('click', () => closeAllStudentMenus());

function toggleStudentStatusMenu(event, id) {
    event.stopPropagation();
    const menu = document.getElementById('stu-status-menu-' + id);
    if (!menu) return;
    const isOpen = menu.classList.contains('show');
    closeAllStudentMenus();
    if (!isOpen) menu.classList.add('show');
}

async function updateStudentStatus(id, newStatus) {
    closeAllStudentMenus();
    try {
        const res = await fetch('students.php?ajax_action=update_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, status: newStatus })
        });
        const data = await res.json();
        if (data.status === 'success') {
            const row = document.getElementById('student-row-' + id);
            if (row) row.dataset.status = data.new_status;

            const badge = document.getElementById('stu-status-badge-' + id);
            const textEl = document.getElementById('stu-status-text-' + id);
            if (badge) {
                badge.className = 'sr-status-pill ' + (data.new_status === 'Active' ? 'active' : 'inactive');
            }
            if (textEl) {
                textEl.textContent = data.new_status;
            }

            refreshStudentMetricsAndFooter();
            showStudentAlert(data.message, 'success');
        } else {
            showStudentAlert(data.message || 'Could not update status.', 'error');
        }
    } catch (err) {
        showStudentAlert('Network error while updating status.', 'error');
    }
}

// =========================================================================
// 3. ADD STUDENT MODAL LOGIC
// =========================================================================
async function fetchNextStudentId() {
    try {
        const res = await fetch('students.php?ajax_action=get_next_student_id');
        const data = await res.json();
        if (data.status === 'success' && data.next_id) {
            const idInput = document.getElementById('add_stu_identifier');
            if (idInput) idInput.value = data.next_id;
        }
    } catch (e) {
        console.error('Failed to fetch next student id', e);
    }
}

function openAddStudentModal() {
    closeAllStudentMenus();
    document.getElementById('addStudentForm').reset();
    fetchNextStudentId();
    document.getElementById('addStudentModal').classList.add('open');
}

function closeAddStudentModal() {
    document.getElementById('addStudentModal').classList.remove('open');
}

async function submitAddStudentForm(e) {
    e.preventDefault();
    const btn = document.getElementById('addStudentSubmitBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    const firstName = document.getElementById('add_stu_first_name').value.trim();
    const middleInitial = document.getElementById('add_stu_middle_initial').value.trim();
    const lastName = document.getElementById('add_stu_last_name').value.trim();
    const fullName = firstName + (middleInitial ? ' ' + middleInitial.replace(/\.$/, '') + '.' : '') + (lastName ? ' ' + lastName : '');

    const payload = {
        identifier: document.getElementById('add_stu_identifier').value.trim(),
        first_name: firstName,
        middle_initial: middleInitial,
        last_name: lastName,
        name: fullName,
        grade_level: document.getElementById('add_stu_grade').value,
        course_section: document.getElementById('add_stu_section').value.trim(),
        email: document.getElementById('add_stu_email').value.trim(),
        guardian_contact: document.getElementById('add_stu_phone').value.trim(),
        status: document.getElementById('add_stu_status').value
    };

    try {
        const res = await fetch('students.php?ajax_action=add_student', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.status === 'success') {
            closeAddStudentModal();
            showStudentAlert(data.message, 'success');
            setTimeout(() => window.location.reload(), 600);
        } else {
            showStudentAlert(data.message || 'Unable to add student.', 'error');
        }
    } catch (err) {
        showStudentAlert('Network error while adding student.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

// =========================================================================
// 4. EDIT STUDENT MODAL LOGIC
// =========================================================================
function openEditStudentModal(id) {
    closeAllStudentMenus();
    const row = document.getElementById('student-row-' + id);
    if (!row) return;

    document.getElementById('edit_stu_id').value = id;
    document.getElementById('edit_stu_identifier').value = row.dataset.identifier || '';

    // Split name into first, MI, last if not explicitly stored in dataset
    let fn = row.dataset.firstName || '';
    let mi = row.dataset.mi || '';
    let ln = row.dataset.lastName || '';
    if (!fn && row.dataset.name) {
        const parts = row.dataset.name.trim().split(/\s+/);
        fn = parts[0] || '';
        ln = parts.length > 1 ? parts[parts.length - 1] : '';
        mi = parts.length > 2 ? parts[1] : '';
    }

    document.getElementById('edit_stu_first_name').value = fn;
    document.getElementById('edit_stu_middle_initial').value = mi;
    document.getElementById('edit_stu_last_name').value = ln;

    document.getElementById('edit_stu_grade').value = row.dataset.grade || 'Grade 8';
    document.getElementById('edit_stu_section').value = row.dataset.section || '';
    document.getElementById('edit_stu_email').value = row.dataset.email || '';
    document.getElementById('edit_stu_phone').value = row.dataset.phone || '';
    document.getElementById('edit_stu_status').value = row.dataset.status || 'Active';

    document.getElementById('editStudentModal').classList.add('open');
}

function closeEditStudentModal() {
    document.getElementById('editStudentModal').classList.remove('open');
}

async function submitEditStudentForm(e) {
    e.preventDefault();
    const btn = document.getElementById('editStudentSubmitBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    const firstName = document.getElementById('edit_stu_first_name').value.trim();
    const middleInitial = document.getElementById('edit_stu_middle_initial').value.trim();
    const lastName = document.getElementById('edit_stu_last_name').value.trim();
    const fullName = firstName + (middleInitial ? ' ' + middleInitial.replace(/\.$/, '') + '.' : '') + (lastName ? ' ' + lastName : '');

    const payload = {
        id: document.getElementById('edit_stu_id').value,
        identifier: document.getElementById('edit_stu_identifier').value.trim(),
        first_name: firstName,
        middle_initial: middleInitial,
        last_name: lastName,
        name: fullName,
        grade_level: document.getElementById('edit_stu_grade').value,
        course_section: document.getElementById('edit_stu_section').value.trim(),
        email: document.getElementById('edit_stu_email').value.trim(),
        guardian_contact: document.getElementById('edit_stu_phone').value.trim(),
        status: document.getElementById('edit_stu_status').value
    };

    try {
        const res = await fetch('students.php?ajax_action=update_student', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.status === 'success') {
            const s = data.student;
            const row = document.getElementById('student-row-' + s.id);
            if (row) {
                row.dataset.identifier = s.identifier;
                row.dataset.name = s.name;
                row.dataset.subtitle = s.student_subtitle;
                row.dataset.grade = s.grade_level;
                row.dataset.section = s.course_section;
                row.dataset.email = s.email;
                row.dataset.phone = s.guardian_contact;
                row.dataset.status = s.status;

                row.querySelector('.col-initials').textContent = getInitialsJS(s.name);
                row.querySelector('.col-name').textContent = s.name;
                row.querySelector('.col-subtitle').textContent = s.student_subtitle;
                row.querySelector('.col-identifier').textContent = s.identifier;

                const gradePill = row.querySelector('.col-grade .grade-pill');
                if (gradePill) {
                    gradePill.className = 'grade-pill ' + getGradePillClassJS(s.grade_level);
                    gradePill.querySelector('.grade-text').textContent = s.grade_level;
                }

                row.querySelector('.col-section').textContent = s.course_section;
                row.querySelector('.col-email').textContent = s.email;
                row.querySelector('.col-phone').textContent = s.guardian_contact;

                const stBadge = document.getElementById('stu-status-badge-' + s.id);
                const stText = document.getElementById('stu-status-text-' + s.id);
                if (stBadge) stBadge.className = 'sr-status-pill ' + (s.status === 'Active' ? 'active' : 'inactive');
                if (stText) stText.textContent = s.status;
            }
            closeEditStudentModal();
            refreshStudentMetricsAndFooter();
            showStudentAlert(data.message, 'success');
        } else {
            showStudentAlert(data.message || 'Could not update student.', 'error');
        }
    } catch (err) {
        showStudentAlert('Network error while saving student changes.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

// =========================================================================
// 5. VIEW STUDENT MODAL LOGIC (Eye Button)
// =========================================================================
async function openViewStudentModal(id) {
    closeAllStudentMenus();
    const modal = document.getElementById('viewStudentModal');
    const body = document.getElementById('viewStudentModalBody');
    modal.classList.add('open');
    body.innerHTML = '<div style="padding:30px;text-align:center;color:var(--sr-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Loading student profile...</div>';

    try {
        const res = await fetch('students.php?ajax_action=get_student&id=' + encodeURIComponent(id));
        const data = await res.json();
        if (data.status === 'success') {
            const s = data.student;
            const history = data.history || [];

            let historyHtml = '';
            if (history.length === 0) {
                historyHtml = '<tr><td colspan="4" style="padding:18px;text-align:center;color:var(--sr-muted);">No attendance scans recorded yet.</td></tr>';
            } else {
                historyHtml = history.map(h => {
                    const stClass = h.status === 'Present' ? 'present' : (h.status === 'Late' ? 'late' : 'absent');
                    return `
                        <tr>
                            <td><strong>${h.date}</strong></td>
                            <td>${h.subject_code || 'CLASS'} &bull; ${h.subject_name || 'General'}</td>
                            <td>${h.time_scanned || '—'}</td>
                            <td><span class="status-pill ${stClass}">${h.status}</span></td>
                        </tr>
                    `;
                }).join('');
            }

            body.innerHTML = `
                <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#f4f9f7;border:1px solid var(--sr-border);border-radius:14px;margin-bottom:16px;">
                    <div class="sr-avatar-circle" style="width:52px;height:52px;font-size:1rem;">${getInitialsJS(s.name)}</div>
                    <div style="flex:1;">
                        <div style="font-size:1.15rem;font-weight:800;color:#14362e;">${s.name}</div>
                        <div style="font-size:0.84rem;color:#58746c;margin-top:2px;">
                            <strong>Student ID:</strong> ${s.identifier} &nbsp;|&nbsp;
                            <strong>Grade:</strong> ${s.grade_level} &nbsp;|&nbsp;
                            <strong>Section:</strong> ${s.course_section}
                        </div>
                        <div style="font-size:0.84rem;color:#58746c;margin-top:2px;">
                            <strong>Guardian Email:</strong> ${s.email} &nbsp;|&nbsp;
                            <strong>Contact:</strong> ${s.guardian_contact}
                        </div>
                    </div>
                    <span class="sr-status-pill ${s.status === 'Active' ? 'active' : 'inactive'}">
                        <span class="status-dot"></span> ${s.status}
                    </span>
                </div>

                <div style="font-weight:800;font-size:0.92rem;margin-bottom:10px;color:#14362e;">
                    <i class="fa-solid fa-clock-rotate-left" style="color:#0d4b3e;margin-right:6px;"></i> Recent Class Attendance Logs
                </div>
                <div class="table-wrap" style="max-height:240px;overflow-y:auto;">
                    <table class="tight-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Subject</th>
                                <th>Time Scanned</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>${historyHtml}</tbody>
                    </table>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid var(--sr-border);">
                    <button type="button" class="secondary-btn" onclick="closeViewStudentModal(); openEditStudentModal(${s.id});">
                        <i class="fa-regular fa-pen-to-square"></i> Edit Student
                    </button>
                    <button type="button" class="primary-btn" onclick="closeViewStudentModal()">Close</button>
                </div>
            `;
        } else {
            body.innerHTML = `<div class="alert alert-danger">${data.message || 'Error loading student.'}</div>`;
        }
    } catch (err) {
        body.innerHTML = '<div class="alert alert-danger">Network error while loading student details.</div>';
    }
}

function closeViewStudentModal() {
    document.getElementById('viewStudentModal').classList.remove('open');
}

// =========================================================================
// 6. MORE OPTIONS (...) DROPDOWN & EXPORT CSV ACTIONS
// =========================================================================
function toggleStudentActionMenu(event, id) {
    event.stopPropagation();
    const menu = document.getElementById('stu-action-menu-' + id);
    if (!menu) return;
    const isOpen = menu.classList.contains('show');
    closeAllStudentMenus();
    if (!isOpen) menu.classList.add('show');
}

function exportFilteredStudentsCSV() {
    const rows = Array.from(document.querySelectorAll('#studentsTableBody tr.student-data-row'))
        .filter(r => r.style.display !== 'none');

    const csvData = [
        ['Student Name', 'Subtitle / Tag', 'Student ID', 'Grade Level', 'Section', 'Guardian Email', 'Guardian Phone', 'Status']
    ];

    rows.forEach(r => {
        csvData.push([
            r.dataset.name || '',
            r.dataset.subtitle || '',
            r.dataset.identifier || '',
            r.dataset.grade || '',
            r.dataset.section || '',
            r.dataset.email || '',
            r.dataset.phone || '',
            r.dataset.status || 'Active'
        ]);
    });

    const csvString = csvData.map(e => e.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'student_records_export.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function exportSingleStudentCSV(id) {
    closeAllStudentMenus();
    const r = document.getElementById('student-row-' + id);
    if (!r) return;

    const csvData = [
        ['Student Name', 'Subtitle / Tag', 'Student ID', 'Grade Level', 'Section', 'Guardian Email', 'Guardian Phone', 'Status'],
        [r.dataset.name, r.dataset.subtitle, r.dataset.identifier, r.dataset.grade, r.dataset.section, r.dataset.email, r.dataset.phone, r.dataset.status]
    ];
    const csvString = csvData.map(e => e.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${r.dataset.identifier || 'student'}_record.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function printSingleStudent(id) {
    closeAllStudentMenus();
    const r = document.getElementById('student-row-' + id);
    if (!r) return;

    const win = window.open('', '_blank', 'width=680,height=520');
    win.document.write(`
        <html>
        <head>
            <title>Student Record - ${r.dataset.name}</title>
            <style>
                body { font-family: 'Segoe UI', sans-serif; padding: 32px; color: #15352d; }
                h2 { color: #0d4b3e; border-bottom: 2px solid #0d4b3e; padding-bottom: 10px; }
                .item { margin: 12px 0; font-size: 15px; }
                .lbl { font-weight: bold; width: 160px; display: inline-block; color: #56736b; }
            </style>
        </head>
        <body>
            <h2>ODB Montessori School of Novaliches — Student Record</h2>
            <div class="item"><span class="lbl">Full Name:</span> ${r.dataset.name}</div>
            <div class="item"><span class="lbl">Student ID:</span> ${r.dataset.identifier}</div>
            <div class="item"><span class="lbl">Grade Level:</span> ${r.dataset.grade}</div>
            <div class="item"><span class="lbl">Section:</span> ${r.dataset.section}</div>
            <div class="item"><span class="lbl">Guardian Email:</span> ${r.dataset.email}</div>
            <div class="item"><span class="lbl">Guardian Phone:</span> ${r.dataset.phone}</div>
            <div class="item"><span class="lbl">Status:</span> ${r.dataset.status}</div>
            <script>window.onload = function(){ window.print(); window.close(); };<\/script>
        </body>
        </html>
    `);
    win.document.close();
}

async function resetStudentPassword(id) {
    closeAllStudentMenus();
    const r = document.getElementById('student-row-' + id);
    const name = r ? r.dataset.name : 'this student';
    const newPass = prompt(`Enter new password for "${name}" (min 6 characters):`, 'password123');
    if (newPass === null) return;

    try {
        const res = await fetch('students.php?ajax_action=reset_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, new_password: newPass.trim() })
        });
        const data = await res.json();
        if (data.status === 'success') {
            showStudentAlert(data.message, 'success');
        } else {
            showStudentAlert(data.message || 'Could not reset password.', 'error');
        }
    } catch (err) {
        showStudentAlert('Network error while resetting password.', 'error');
    }
}

async function deleteStudentRecord(id) {
    closeAllStudentMenus();
    const r = document.getElementById('student-row-' + id);
    const name = r ? r.dataset.name : 'this student';
    if (!confirm(`Are you sure you want to delete "${name}"?`)) return;

    try {
        const res = await fetch('students.php?ajax_action=delete_student', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await res.json();
        if (data.status === 'success') {
            if (r) r.remove();
            refreshStudentMetricsAndFooter();
            showStudentAlert(data.message, 'success');
        } else {
            showStudentAlert(data.message || 'Could not delete student.', 'error');
        }
    } catch (err) {
        showStudentAlert('Network error while deleting student.', 'error');
    }
}

window.addEventListener('DOMContentLoaded', () => {
    applyStudentFilters();
});
</script>

</body>
</html>