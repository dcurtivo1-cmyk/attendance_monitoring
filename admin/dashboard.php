<?php
// admin/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

$today = date('Y-m-d');
$message = '';
$error = '';

// =========================================================================
// BACKEND HANDLERS FOR QUICK ACTIONS MODALS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Quick Action: Add Student
        if ($action === 'quick_add_student') {
            $identifier       = trim($_POST['identifier'] ?? '');
            $first_name       = trim($_POST['first_name'] ?? '');
            $middle_initial   = trim($_POST['middle_initial'] ?? '');
            $last_name        = trim($_POST['last_name'] ?? '');
            $name             = trim($_POST['name'] ?? '');

            if ($name === '' && ($first_name !== '' || $last_name !== '')) {
                $name = trim($first_name . ' ' . ($middle_initial !== '' ? rtrim($middle_initial, '.') . '. ' : '') . $last_name);
            }
            if ($first_name === '' && $name !== '') {
                $p = preg_split('/\s+/', $name);
                $first_name = $p[0];
                $last_name = count($p) > 1 ? end($p) : '';
                $middle_initial = count($p) > 2 ? $p[1] : '';
            }

            $grade_level      = trim($_POST['grade_level'] ?? 'Grade 8');
            $course_section   = trim($_POST['course_section'] ?? 'Section A - Bonifacio');
            $email            = trim($_POST['email'] ?? '');
            $guardian_contact = trim($_POST['guardian_contact'] ?? '+63 917 123 4567');

            if ($identifier === '' || ($first_name === '' && $name === '') || $email === '') {
                $error = 'Student ID, Name, and Email are required.';
            } else {
                $dup = $pdo->prepare("SELECT id FROM users WHERE identifier = ? OR email = ? LIMIT 1");
                $dup->execute([$identifier, $email]);
                if ($dup->fetch()) {
                    $error = 'Student ID or Email already exists.';
                } else {
                    $hashedPass = password_hash('password123', PASSWORD_BCRYPT);
                    $qrToken = generate_qr_token($identifier);
                    $ins = $pdo->prepare("
                        INSERT INTO users (identifier, name, first_name, middle_initial, last_name, email, password, role, course_section, grade_level, guardian_contact, student_subtitle, status, qr_token)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'student', ?, ?, ?, ?, 'Active', ?)
                    ");
                    $ins->execute([$identifier, $name, $first_name, $middle_initial, $last_name, $email, $hashedPass, $course_section, $grade_level, $guardian_contact, $identifier, $qrToken]);
                    $newStuId = (int)$pdo->lastInsertId();

                    // Auto-enroll in subjects of that grade level
                    $pdo->prepare("
                        INSERT IGNORE INTO student_subjects (student_id, subject_id)
                        SELECT ?, s.id
                        FROM subjects s
                        JOIN grade_levels gl ON gl.id = s.grade_level_id
                        WHERE gl.grade_name = ?
                    ")->execute([$newStuId, $grade_level]);

                    $message = "Student '$name' ($identifier) added and enrolled in $grade_level subjects!";
                }
            }
        }

        // 2. Quick Action: Add Teacher
        if ($action === 'quick_add_teacher') {
            $teacher_id       = trim($_POST['teacher_id'] ?? '');
            $full_name        = trim($_POST['full_name'] ?? '');
            $department_code  = trim($_POST['department_code'] ?? 'FACULTY');
            $grade_level_name = trim($_POST['grade_level_name'] ?? 'Grade 7');
            $subject_name     = trim($_POST['subject_name'] ?? 'English');
            $section_name     = trim($_POST['section_name'] ?? 'Section A - Rizal');
            $email            = trim($_POST['email'] ?? '');
            $contact_number   = trim($_POST['contact_number'] ?? '');

            if ($teacher_id === '' || $full_name === '' || $email === '') {
                $error = 'Teacher ID, Full Name, and Email are required.';
            } else {
                $dup = $pdo->prepare("SELECT id FROM teachers WHERE teacher_id = ? OR email = ? LIMIT 1");
                $dup->execute([$teacher_id, $email]);
                if ($dup->fetch()) {
                    $error = 'Teacher ID or Email already exists.';
                } else {
                    $gStmt = $pdo->prepare("SELECT id FROM grade_levels WHERE grade_name = ? LIMIT 1");
                    $gStmt->execute([$grade_level_name]);
                    $gId = $gStmt->fetchColumn() ?: 1;

                    $sStmt = $pdo->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND subject_name = ? LIMIT 1");
                    $sStmt->execute([$gId, $subject_name]);
                    $sId = $sStmt->fetchColumn() ?: null;

                    $hashedPass = password_hash('password123', PASSWORD_BCRYPT);
                    $qrToken = 'PROF_QR_' . md5($teacher_id . microtime(true));
                    $pdo->prepare("INSERT IGNORE INTO users (identifier, name, email, password, role, qr_token) VALUES (?, ?, ?, ?, 'professor', ?)")
                        ->execute([$teacher_id, $full_name, $email, $hashedPass, $qrToken]);

                    $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $uStmt->execute([$email]);
                    $uId = $uStmt->fetchColumn() ?: null;

                    $ins = $pdo->prepare("
                        INSERT INTO teachers (user_id, teacher_id, full_name, department_code, grade_level_id, subject_id, grade_level_name, subject_name, section_name, email, contact_number, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                    ");
                    $ins->execute([$uId, $teacher_id, $full_name, $department_code, $gId, $sId, $grade_level_name, $subject_name, $section_name, $email, $contact_number]);
                    $newTId = (int)$pdo->lastInsertId();

                    if ($uId && $sId) {
                        $pdo->prepare("UPDATE subjects SET professor_id = ? WHERE id = ?")->execute([$uId, $sId]);
                    }

                    $pdo->prepare("INSERT INTO teacher_assignments (teacher_id, grade_level_id, subject_id, subject_name, section_name) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newTId, $gId, $sId, $subject_name, $section_name]);

                    $message = "Teacher '$full_name' added and assigned to $grade_level_name ($subject_name - $section_name)!";
                }
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// =========================================================================
// LOAD DASHBOARD METRICS & GRADE LEVEL OVERVIEW
// =========================================================================
$coreSubjectsCol1 = ['English', 'Mathematics', 'Science', 'Filipino', 'Araling Panlipunan'];
$coreSubjectsCol2 = ['MAPEH', 'TLE', 'Computer'];
$allCoreSubjects  = array_merge($coreSubjectsCol1, $coreSubjectsCol2);

$totalStudentsCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$activeStudentsCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Active'")->fetchColumn();
$totalTeachersCount = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'Active'")->fetchColumn();
$totalSubjectsCount = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();

// Count students per grade level
$gradeCountsStmt = $pdo->query("
    SELECT grade_level, COUNT(*) AS cnt
    FROM users
    WHERE role = 'student'
    GROUP BY grade_level
");
$gradeStudentCounts = [];
foreach ($gradeCountsStmt->fetchAll() as $gRow) {
    $gradeStudentCounts[$gRow['grade_level']] = (int)$gRow['cnt'];
}

$gradeCards = [
    [
        'id'         => 1,
        'grade'      => 'Grade 7',
        'count'      => '8 Core Subjects',
        'students'   => $gradeStudentCounts['Grade 7'] ?? 0,
        'icon_bg'    => '#dbf2e8',
        'icon_color' => '#147a57',
        'dot_color'  => '#208c68',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ],
    [
        'id'         => 2,
        'grade'      => 'Grade 8',
        'count'      => '8 Core Subjects',
        'students'   => $gradeStudentCounts['Grade 8'] ?? 0,
        'icon_bg'    => '#dcebfa',
        'icon_color' => '#2b70c9',
        'dot_color'  => '#2b70c9',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ],
    [
        'id'         => 3,
        'grade'      => 'Grade 9',
        'count'      => '8 Core Subjects',
        'students'   => $gradeStudentCounts['Grade 9'] ?? 0,
        'icon_bg'    => '#fcecd2',
        'icon_color' => '#d1811b',
        'dot_color'  => '#dc8b22',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ],
    [
        'id'         => 4,
        'grade'      => 'Grade 10',
        'count'      => '8 Core Subjects',
        'students'   => $gradeStudentCounts['Grade 10'] ?? 0,
        'icon_bg'    => '#e9defa',
        'icon_color' => '#6d44b8',
        'dot_color'  => '#7047eb',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ]
];

$nextTeacherIdCode = get_next_teacher_id($pdo);
$nextStudentIdCode = get_next_student_id($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ODB Montessori School of Novaliches</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sidebar-bg: #0d5b49;
            --page-bg: #eef4f1;
            --card-bg: #ffffff;
            --card-border: #dce7e2;
            --ink-dark: #0b3930;
            --ink-main: #1b3832;
            --ink-muted: #5c746e;
            --primary: #0d5b49;
            --primary-hover: #083d32;
            --green-accent: #14855e;
            --green-soft: #def7ec;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--page-bg);
            color: var(--ink-main);
            min-height: 100vh;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* =========================================================
           APP SHELL & FIXED LEFT SIDEBAR
           ========================================================= */
        .dashboard-layout {
            display: grid;
            grid-template-columns: 255px minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            width: 255px !important;
            min-width: 255px !important;
            max-width: 255px !important;
            flex-shrink: 0 !important;
            box-sizing: border-box !important;
            background: var(--sidebar-bg);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            padding: 20px 16px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 2px 4px 18px;
            margin-bottom: 6px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-logo {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.85);
            color: #083d32;
            font-size: 1.15rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .sidebar-brand-text {
            line-height: 1.2;
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 800;
        }

        .sidebar-brand-text span {
            display: block;
            line-height: 1.18;
        }

        .sidebar-brand-text small {
            display: block;
            font-size: 0.68rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            opacity: 0.75;
            font-weight: 700;
            margin-top: 4px;
        }

        .sidebar-section-label {
            margin: 16px 8px 8px;
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.65);
            font-weight: 800;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 4px;
        }

        .sidebar-nav .nav-item,
        .sidebar-nav .nav-link {
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

        .sidebar-nav .nav-item i,
        .sidebar-nav .nav-link i {
            width: 20px !important;
            min-width: 20px !important;
            text-align: center !important;
            font-size: 0.95rem !important;
            flex-shrink: 0 !important;
        }

        .sidebar-nav .nav-item:hover,
        .sidebar-nav .nav-link:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            color: #ffffff !important;
        }

        .sidebar-nav .nav-item.active,
        .sidebar-nav .nav-link.active {
            background: #083d32 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            font-size: 0.9rem !important;
            box-shadow: 0 4px 14px rgba(8, 61, 50, 0.35) !important;
        }

        .sidebar-nav .nav-item.active i,
        .sidebar-nav .nav-link.active i {
            color: #16cb48 !important;
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

        /* =========================================================
           MAIN PANEL & TOP HEADER BAR
           ========================================================= */
        .main-area {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .top-header {
            height: 66px;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--card-border);
            padding: 0 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .header-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--ink-dark);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logout-pill {
            height: 36px;
            padding: 0 14px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            color: var(--ink-main);
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.15s ease;
        }

        .logout-pill:hover {
            background: #fde8e8;
            color: #c53030;
            border-color: #f8b4b4;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-left: 4px;
        }

        .admin-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #c8e6dc;
            color: #0c5141;
            font-size: 0.78rem;
            font-weight: 800;
            display: grid;
            place-items: center;
        }

        .admin-meta strong {
            display: block;
            font-size: 0.83rem;
            font-weight: 700;
            color: var(--ink-main);
        }

        .admin-meta small {
            font-size: 0.72rem;
            color: var(--ink-muted);
        }

        /* =========================================================
           DASHBOARD BODY CONTENT
           ========================================================= */
        .dashboard-container {
            padding: 26px 30px 40px;
            max-width: 1520px;
            width: 100%;
            margin: 0 auto;
        }

        .hero-card {
            background: linear-gradient(135deg, #094336 0%, #0f6853 100%);
            border-radius: 20px;
            padding: 26px 30px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 24px;
            box-shadow: 0 12px 28px rgba(9, 67, 54, 0.16);
        }

        .hero-card h2 {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .hero-card p {
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.84);
            margin-top: 5px;
        }

        .hero-btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .hero-btn {
            padding: 11px 18px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            border: none;
            transition: transform 0.15s ease, background 0.15s ease;
        }

        .hero-btn.light {
            background: #ffffff;
            color: #094336;
        }

        .hero-btn.glass {
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.24);
        }

        .hero-btn:hover {
            transform: translateY(-2px);
        }

        .alert-banner {
            padding: 13px 18px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 0.86rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert-banner.success {
            background: #def7ec;
            color: #0c5e40;
            border: 1px solid #b5ebd3;
        }

        .alert-banner.error {
            background: #fde8e8;
            color: #9b1c1c;
            border: 1px solid #f8b4b4;
        }

        /* =========================================================
           4 KPI OVERVIEW CARDS
           ========================================================= */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .kpi-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(11, 57, 48, 0.03);
            transition: transform 0.15s ease;
        }

        .kpi-box:hover {
            transform: translateY(-2px);
        }

        .kpi-icon-circle {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .kpi-icon-circle.green { background: #dff4ec; color: #0e6f53; }
        .kpi-icon-circle.blue { background: #e0f0ff; color: #1d64c8; }
        .kpi-icon-circle.amber { background: #fef0d9; color: #c87412; }
        .kpi-icon-circle.purple { background: #ede6ff; color: #6d44b8; }

        .kpi-box strong {
            display: block;
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--ink-dark);
            line-height: 1.1;
        }

        .kpi-box span {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--ink-muted);
            margin-top: 3px;
        }

        /* =========================================================
           SECTION HEADER
           ========================================================= */
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-head h3 {
            font-size: 1.12rem;
            font-weight: 800;
            color: var(--ink-dark);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .section-head h3 i {
            color: var(--primary);
        }

        /* =========================================================
           GRADE LEVEL CURRICULUM CARDS (4 HORIZONTAL CARDS)
           ========================================================= */
        .grade-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 26px;
        }

        .grade-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 18px 18px 16px;
            box-shadow: 0 2px 10px rgba(11, 57, 48, 0.03);
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }

        .grade-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(11, 57, 48, 0.07);
        }

        .grade-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            padding-bottom: 12px;
            border-bottom: 1px solid #edf3f0;
            margin-bottom: 12px;
        }

        .grade-title-wrap {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .grade-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }

        .grade-title-text h4 {
            font-size: 1.02rem;
            font-weight: 800;
            color: var(--ink-dark);
        }

        .grade-title-text span {
            display: block;
            font-size: 0.75rem;
            color: var(--ink-muted);
            font-weight: 600;
            margin-top: 2px;
        }

        .view-details-link {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--primary);
            background: #f0f7f4;
            padding: 5px 10px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .view-details-link:hover {
            background: #dff0ea;
        }

        .subjects-two-col {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 6px 10px;
        }

        .subject-col {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .subject-col li {
            font-size: 0.79rem;
            color: #36524b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .subject-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* =========================================================
           CLEAN SYSTEM MODULES & QUICK ACTIONS CARDS
           ========================================================= */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .module-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 14px;
            box-shadow: 0 2px 8px rgba(11, 57, 48, 0.03);
            transition: all 0.16s ease;
        }

        .module-card:hover {
            transform: translateY(-3px);
            border-color: #b7d4c9;
            box-shadow: 0 10px 24px rgba(11, 57, 48, 0.07);
        }

        .module-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #e6f4ef;
            color: var(--primary);
            display: grid;
            place-items: center;
            font-size: 1.15rem;
            margin-bottom: 10px;
        }

        .module-card h4 {
            font-size: 1rem;
            font-weight: 800;
            color: var(--ink-dark);
        }

        .module-card p {
            font-size: 0.81rem;
            color: var(--ink-muted);
            margin-top: 4px;
            line-height: 1.45;
        }

        .module-btn {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-radius: 11px;
            background: #f3f8f6;
            color: var(--primary);
            font-size: 0.82rem;
            font-weight: 800;
            border: 1px solid #dce8e3;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .module-btn:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        /* =========================================================
           MODALS
           ========================================================= */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(7, 40, 33, 0.48);
            backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 18px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--card-border);
            width: min(100%, 540px);
            max-height: 90vh;
            overflow-y: auto;
            padding: 22px 24px;
            box-shadow: 0 20px 50px rgba(7, 40, 33, 0.22);
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 12px;
            border-bottom: 1px solid #e6efec;
            margin-bottom: 16px;
        }

        .modal-head h3 {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--ink-dark);
        }

        .modal-close-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            background: #f5f9f7;
            cursor: pointer;
            display: grid;
            place-items: center;
        }

        .form-field {
            margin-bottom: 13px;
        }

        .form-field label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--ink-main);
            margin-bottom: 5px;
        }

        .form-input {
            width: 100%;
            height: 38px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            font-family: inherit;
            font-size: 0.84rem;
            color: var(--ink-main);
            background: #ffffff;
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #e6efec;
        }

        .btn-cancel {
            padding: 9px 16px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #f5f9f7;
            font-weight: 700;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .btn-save {
            padding: 9px 18px;
            border-radius: 10px;
            border: none;
            background: #0b4d3e;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.82rem;
            cursor: pointer;
        }

        @media (max-width: 1280px) {
            .kpi-row, .grade-grid, .modules-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 880px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
                position: relative;
                height: auto;
            }
        }

        @media (max-width: 600px) {
            .kpi-row, .grade-grid, .modules-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-layout">
    <!-- =========================================================
         CLEAN & MODERN LEFT SIDEBAR NAVIGATION
         ========================================================= -->
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
            <a href="dashboard.php" class="nav-item active">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
            <a href="students.php" class="nav-item">
                <i class="fa-solid fa-user-group"></i>
                <span>Students</span>
            </a>
            <a href="teachers.php" class="nav-item">
                <i class="fa-solid fa-chalkboard-user"></i>
                <span>Teachers</span>
            </a>
            <a href="attendance.php" class="nav-item">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>Attendance</span>
            </a>
            <a href="reports.php" class="nav-item">
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

    <!-- =========================================================
         MAIN CONTENT AREA
         ========================================================= -->
    <div class="main-area">
        <header class="top-header">
            <div class="header-title">
                <i class="fa-solid fa-Sliders" style="color:var(--primary);"></i>
                <span>Admin System Overview</span>
            </div>
            <div class="header-actions">
                <a href="../logout.php" class="logout-pill">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
                <div class="admin-profile">
                    <div class="admin-avatar">AD</div>
                    <div class="admin-meta">
                        <strong><?= htmlspecialchars($user['name'] ?? 'System Administrator') ?></strong>
                        <small>Administrator</small>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-container">
            <?php if ($message !== ''): ?>
                <div class="alert-banner success">
                    <span><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-weight:800;">✕</button>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert-banner error">
                    <span><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-weight:800;">✕</button>
                </div>
            <?php endif; ?>

            <!-- WELCOME HERO CARD -->
            <div class="hero-card">
                <div>
                    <h2>Magandang araw, <?= htmlspecialchars($user['name'] ?? 'Administrator') ?>!</h2>
                    <p><?= date('l, F j, Y') ?> • Narito ang malinis at organisadong pangkalahatang-ideya ng paaralan.</p>
                </div>
                <div class="hero-btn-group">
                    <button type="button" class="hero-btn light" onclick="openModal('addStudentQuickModal')">
                        <i class="fa-solid fa-user-plus"></i> Add Student
                    </button>
                    <button type="button" class="hero-btn glass" onclick="openModal('addTeacherModal')">
                        <i class="fa-solid fa-chalkboard-user"></i> Add Teacher
                    </button>
                </div>
            </div>

            <!-- 4 TOP SUMMARY KPI CARDS -->
            <section class="kpi-row">
                <div class="kpi-box">
                    <div class="kpi-icon-circle green">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <div>
                        <strong><?= $totalStudentsCount ?></strong>
                        <span>Total Enrolled Students</span>
                    </div>
                </div>

                <div class="kpi-box">
                    <div class="kpi-icon-circle blue">
                        <i class="fa-solid fa-chalkboard-user"></i>
                    </div>
                    <div>
                        <strong><?= $totalTeachersCount ?></strong>
                        <span>Active Faculty / Teachers</span>
                    </div>
                </div>

                <div class="kpi-box">
                    <div class="kpi-icon-circle amber">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div>
                        <strong>4</strong>
                        <span>Grade Levels (Grade 7 - 10)</span>
                    </div>
                </div>

                <div class="kpi-box">
                    <div class="kpi-icon-circle purple">
                        <i class="fa-solid fa-book-open"></i>
                    </div>
                    <div>
                        <strong><?= $totalSubjectsCount ?></strong>
                        <span>Curriculum Subjects</span>
                    </div>
                </div>
            </section>

            <!-- GRADE LEVEL CURRICULUM CARDS -->
            <div class="section-head">
                <h3><i class="fa-solid fa-graduation-cap"></i> Grade Level Curriculum &amp; Subjects</h3>
            </div>

            <section class="grade-grid">
                <?php foreach ($gradeCards as $gc): ?>
                    <div class="grade-card">
                        <div class="grade-card-header">
                            <div class="grade-title-wrap">
                                <div class="grade-icon-box" style="background: <?= $gc['icon_bg'] ?>; color: <?= $gc['icon_color'] ?>;">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <div class="grade-title-text">
                                    <h4><?= htmlspecialchars($gc['grade']) ?></h4>
                                    <span><?= htmlspecialchars($gc['count']) ?> • <?= (int)$gc['students'] ?> Students</span>
                                </div>
                            </div>
                            <button type="button" class="view-details-link" onclick="openGradeModal('<?= htmlspecialchars($gc['grade'], ENT_QUOTES) ?>')">
                                Details <i class="fa-solid fa-arrow-right" style="font-size:0.66rem;"></i>
                            </button>
                        </div>

                        <div class="subjects-two-col">
                            <ul class="subject-col">
                                <?php foreach ($gc['col1'] as $sub1): ?>
                                    <li>
                                        <span class="subject-dot" style="background: <?= $gc['dot_color'] ?>;"></span>
                                        <?= htmlspecialchars($sub1) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <ul class="subject-col">
                                <?php foreach ($gc['col2'] as $sub2): ?>
                                    <li>
                                        <span class="subject-dot" style="background: <?= $gc['dot_color'] ?>;"></span>
                                        <?= htmlspecialchars($sub2) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- CLEAN QUICK ACCESS MODULES (Replaces bottom Teachers & Attendance tables) -->
            <div class="section-head">
                <h3><i class="fa-solid fa-compass"></i> Quick System Management</h3>
            </div>

            <section class="modules-grid">
                <div class="module-card">
                    <div>
                        <div class="module-icon"><i class="fa-solid fa-user-graduate"></i></div>
                        <h4>Student Records</h4>
                        <p>Manage student profiles, grade levels, sections, and assigned subjects.</p>
                    </div>
                    <a href="students.php" class="module-btn">
                        <span>Open Students</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <div class="module-card">
                    <div>
                        <div class="module-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <h4>Faculty &amp; Teachers</h4>
                        <p>View teacher profiles, assign subjects and grade levels, and update faculty info.</p>
                    </div>
                    <a href="teachers.php" class="module-btn">
                        <span>Manage Teachers</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <div class="module-card">
                    <div>
                        <div class="module-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                        <h4>Attendance Monitoring</h4>
                        <p>Review daily student attendance logs, status updates, and class check-ins.</p>
                    </div>
                    <a href="attendance.php" class="module-btn">
                        <span>View Attendance</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <div class="module-card">
                    <div>
                        <div class="module-icon"><i class="fa-solid fa-chart-pie"></i></div>
                        <h4>Reports &amp; Analytics</h4>
                        <p>Generate printable and exportable attendance reports by student, teacher, or grade.</p>
                    </div>
                    <a href="reports.php" class="module-btn">
                        <span>Generate Reports</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- =========================================================
     MODAL 1: GRADE LEVEL DETAILS MODAL
     ========================================================= -->
<div id="gradeDetailsModal" class="modal-overlay" onclick="if(event.target===this) closeModal('gradeDetailsModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="gradeModalTitle">Grade Level Curriculum</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('gradeDetailsModal')">✕</button>
        </div>
        <p style="font-size:0.84rem;color:var(--ink-muted);margin-bottom:14px;">
            8 Core Curriculum Subjects under <strong id="gradeModalSub">Grade 7</strong>:
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
            <?php foreach ($allCoreSubjects as $cs): ?>
                <div style="padding:10px 12px;border-radius:10px;background:#f4f9f6;border:1px solid #dce8e3;font-size:0.83rem;font-weight:700;color:var(--ink-main);">
                    <i class="fa-solid fa-book" style="color:#14855e;margin-right:7px;"></i> <?= htmlspecialchars($cs) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('gradeDetailsModal')">Close</button>
            <a id="gradeModalStudentsLink" href="students.php" class="btn-save">View Students</a>
        </div>
    </div>
</div>

<!-- =========================================================
     MODAL 2: QUICK ADD STUDENT
     ========================================================= -->
<div id="addStudentQuickModal" class="modal-overlay" onclick="if(event.target===this) closeModal('addStudentQuickModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fa-solid fa-user-plus" style="color:#0e5847;margin-right:7px;"></i> Quick Add Student</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('addStudentQuickModal')">✕</button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="quick_add_student">
            <div class="form-field" style="margin-bottom:12px;">
                <label>Student ID *</label>
                <input type="text" name="identifier" class="form-input" value="<?= htmlspecialchars($nextStudentIdCode) ?>" required>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 90px 1fr; gap:10px; margin-bottom:12px;">
                <div class="form-field">
                    <label>First Name *</label>
                    <input type="text" name="first_name" class="form-input" placeholder="e.g. Carlo" required>
                </div>
                <div class="form-field">
                    <label>M.I.</label>
                    <input type="text" name="middle_initial" class="form-input" placeholder="M." maxlength="10">
                </div>
                <div class="form-field">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" class="form-input" placeholder="e.g. Mendoza" required>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-field">
                    <label>Grade Level *</label>
                    <select name="grade_level" class="form-input" required>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8" selected>Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                    </select>
                </div>
                <div class="form-field">
                    <label>Section *</label>
                    <input type="text" name="course_section" class="form-input" placeholder="e.g. Section A - Bonifacio" value="Section A - Bonifacio" required>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-field">
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-input" placeholder="student@gmail.com" required>
                </div>
                <div class="form-field">
                    <label>Guardian Contact *</label>
                    <input type="text" name="guardian_contact" class="form-input" value="+63 917 123 4567" required>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('addStudentQuickModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Student</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 3: QUICK ADD TEACHER
     ========================================================= -->
<div id="addTeacherModal" class="modal-overlay" onclick="if(event.target===this) closeModal('addTeacherModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fa-solid fa-chalkboard-user" style="color:#0e5847;margin-right:7px;"></i> Add New Teacher</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('addTeacherModal')">✕</button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="quick_add_teacher">
            <div class="form-row-2">
                <div class="form-field">
                    <label>Teacher ID *</label>
                    <input type="text" name="teacher_id" class="form-input" value="<?= htmlspecialchars($nextTeacherIdCode) ?>" required>
                </div>
                <div class="form-field">
                    <label>Course / Dept Subtitle</label>
                    <input type="text" name="department_code" class="form-input" placeholder="e.g. HCI (IT), SCITECH" value="FACULTY">
                </div>
            </div>
            <div class="form-field">
                <label>Full Name *</label>
                <input type="text" name="full_name" class="form-input" placeholder="e.g. Mr. John Angac" required>
            </div>
            <div class="form-row-2">
                <div class="form-field">
                    <label>Grade Level *</label>
                    <select name="grade_level_name" class="form-input" required>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                    </select>
                </div>
                <div class="form-field">
                    <label>Assigned Subject *</label>
                    <select name="subject_name" class="form-input" required>
                        <?php foreach ($allCoreSubjects as $subName): ?>
                            <option value="<?= htmlspecialchars($subName) ?>"><?= htmlspecialchars($subName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-field">
                <label>Section Handled *</label>
                <input type="text" name="section_name" class="form-input" placeholder="e.g. Section A - Rizal" value="Section A - Rizal" required>
            </div>
            <div class="form-row-2">
                <div class="form-field">
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-input" placeholder="teacher@school.edu.ph" required>
                </div>
                <div class="form-field">
                    <label>Contact Number *</label>
                    <input type="text" name="contact_number" class="form-input" placeholder="0917-XXX-XXXX" required>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('addTeacherModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Teacher</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id)?.classList.add('open');
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
}

function openGradeModal(gradeName) {
    document.getElementById('gradeModalTitle').textContent = gradeName + ' — Curriculum Overview';
    document.getElementById('gradeModalSub').textContent = gradeName;
    document.getElementById('gradeModalStudentsLink').href = 'students.php';
    openModal('gradeDetailsModal');
}
</script>

</body>
</html>
