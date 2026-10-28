<?php
// professor/manual_attendance.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/student_sections.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();
$accountStmt = $pdo->prepare('SELECT id, name, email, identifier FROM users WHERE id = ?');
$accountStmt->execute([$user['id']]);
$teacherAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: $user;
$prof_id = $user['id'];

// Get all subjects for this professor
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE professor_id = ? ORDER BY subject_code ASC");
$stmt->execute([$prof_id]);
$subjects = $stmt->fetchAll();

$selected_subject_id = intval($_GET['subject_id'] ?? ($subjects[0]['id'] ?? 0));
$selected_date       = trim($_GET['date'] ?? date('Y-m-d'));
$requested_section   = is_string($_GET['section'] ?? null) ? trim($_GET['section']) : '';
$selected_section    = $requested_section;
$_SESSION['manual_attendance_csrf'] ??= bin2hex(random_bytes(32));
if (!in_array($selected_subject_id, array_map('intval', array_column($subjects, 'id')), true)) {
    $selected_subject_id = 0;
}

// Fetch active subject details and schedule
$subjectInfo = null;
if ($selected_subject_id > 0) {
    $sInfoStmt = $pdo->prepare("
        SELECT s.*, sc.day_of_week, sc.start_time, sc.end_time, sc.late_threshold_mins 
        FROM subjects s 
        LEFT JOIN schedules sc ON s.id = sc.subject_id 
        WHERE s.id = ? AND s.professor_id = ?
    ");
    $sInfoStmt->execute([$selected_subject_id, $prof_id]);
    $subjectInfo = $sInfoStmt->fetch();
}

// Show catalog sections and enrolled sections for the selected subject.
$sectionOptions = [];
if ($subjectInfo) {
    $sectionsStmt = $pdo->prepare("SELECT DISTINCT u.course_section FROM student_subjects ss JOIN users u ON u.id = ss.student_id WHERE ss.subject_id = ? AND u.role = 'student' AND u.course_section IS NOT NULL AND u.course_section <> '' ORDER BY u.course_section");
    $sectionsStmt->execute([$selected_subject_id]);
    $sectionOptions = $sectionsStmt->fetchAll(PDO::FETCH_COLUMN);
    $gradeStmt = $pdo->prepare('SELECT grade_name FROM grade_levels WHERE id = ?');
    $gradeStmt->execute([$subjectInfo['grade_level_id']]);
    $gradeName = $gradeStmt->fetchColumn();
    $catalog = get_student_sections_by_grade($pdo);
    $sectionOptions = array_values(array_unique(array_merge($sectionOptions, $catalog[$gradeName] ?? [])));
}
$sectionOptions = array_values(array_filter($sectionOptions, static fn($section) => trim($section) !== '' && $section !== 'ALL'));
sort($sectionOptions, SORT_NATURAL);
if ($selected_section === '' || $selected_section === 'ALL') {
    $selected_section = $sectionOptions[0] ?? '';
} elseif (!in_array($selected_section, $sectionOptions, true)) {
    $selected_section = '';
}
$sectionQuery = '&amp;section=' . urlencode($selected_section);

$error = '';
$success = '';

// Handle Direct POST Fallback (Non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = trim($_POST['action'] ?? '');
    $student_id = intval($_POST['student_id'] ?? 0);
    $status     = trim($_POST['status'] ?? 'Present');
    $time_in    = date('H:i:s');

    if (!hash_equals($_SESSION['manual_attendance_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif ($selected_section === '' || $requested_section !== $selected_section) {
        $error = 'Choose a specific section before adding or marking a student.';
    } elseif ($selected_subject_id <= 0 || !in_array($status, ['Present', 'Late', 'Absent', 'Excused'], true)) {
        $error = 'Choose one of your subjects and a valid attendance status.';
    } elseif ($action === 'enroll_and_mark') {
        $student_identifier = trim($_POST['student_identifier'] ?? '');
        $findStmt = $pdo->prepare("SELECT id FROM users WHERE (identifier = :identifier OR email = :email) AND role = 'student' AND course_section = :section LIMIT 1");
        $findStmt->execute([':identifier' => $student_identifier, ':email' => $student_identifier, ':section' => $selected_section]);
        $foundStu = $findStmt->fetch();

        if ($foundStu) {
            $student_id = $foundStu['id'];
            $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)")->execute([$student_id, $selected_subject_id]);
            $pdo->prepare("
                INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = COALESCE(time_in, VALUES(time_in))
            ")->execute([$student_id, $selected_subject_id, $selected_date, $time_in, $status, $prof_id]);
            $success = "Student enrolled and marked as $status!";
        } else {
            $error = "Student ID/Email not found in the selected section.";
        }
    }
}

// Fetch enrolled students for this subject along with their attendance for $selected_date
$students = [];
if ($selected_subject_id > 0 && $selected_section !== '') {
    $rosterStmt = $pdo->prepare("
        SELECT u.id as student_id, u.identifier, u.name, u.course_section, u.email,
               a.id as attendance_id, a.time_in, a.status as attendance_status, a.date as attendance_date
        FROM student_subjects ss
        JOIN users u ON ss.student_id = u.id
        LEFT JOIN attendance a ON a.student_id = u.id AND a.subject_id = ? AND a.date = ?
        WHERE ss.subject_id = ? AND u.role = 'student'
          AND u.course_section = ?
        ORDER BY u.name ASC
    ");
    $rosterStmt->execute([$selected_subject_id, $selected_date, $selected_subject_id, $selected_section]);
    $students = $rosterStmt->fetchAll();
}

// Fetch all other students not yet enrolled in this subject (for quick enrollment dropdown)
$otherStudents = [];
if ($selected_subject_id > 0 && $selected_section !== '') {
    $otherStmt = $pdo->prepare("
        SELECT id, identifier, name, course_section FROM users 
        WHERE role = 'student' AND course_section = ?
          AND id NOT IN (SELECT student_id FROM student_subjects WHERE subject_id = ?)
        ORDER BY name ASC
    ");
    $otherStmt->execute([$selected_section, $selected_subject_id]);
    $otherStudents = $otherStmt->fetchAll();
}

// Quick Attendance Counts for Selected Date
$presentCount = 0;
$lateCount    = 0;
$absentCount  = 0;
$excusedCount = 0;
$unmarkedCount = 0;

foreach ($students as $st) {
    if ($st['attendance_status'] === 'Present') $presentCount++;
    elseif ($st['attendance_status'] === 'Late') $lateCount++;
    elseif ($st['attendance_status'] === 'Absent') $absentCount++;
    elseif ($st['attendance_status'] === 'Excused') $excusedCount++;
    else $unmarkedCount++;
}
$profDisplayName = professor_display_name($teacherAccount['name'] ?? null);
$profParts = preg_split('/\s+/', $profDisplayName);
$profParts = array_values(array_filter($profParts));
$profInitials = count($profParts) >= 2
    ? strtoupper(substr($profParts[0], 0, 1) . substr($profParts[1], 0, 1))
    : strtoupper(substr($profParts[0] ?? 'TC', 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Attendance - Teacher Portal</title>
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
            --primary-hover: #094336;
            --primary-soft: #dff4ec;
            --green: #14855e;
            --green-soft: #def7ec;
            --amber: #d98218;
            --amber-soft: #fef0d9;
            --red: #d94848;
            --red-soft: #fde8e8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--page-bg);
            color: var(--ink-main);
            min-height: 100vh;
        }
        a { text-decoration: none; color: inherit; }

        .layout-shell {
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
            background: #0d5b49 !important;
            color: #ffffff;
            padding: 20px 16px 18px;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
        }
        .brand-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 4px 6px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: #e6f4ef;
            color: #094336;
            display: grid;
            place-items: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }
        .brand-title { font-size: 0.9rem; font-weight: 800; line-height: 1.2; }
        .brand-sub {
            display: block; font-size: 0.64rem; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: rgba(255, 255, 255, 0.65); margin-top: 3px;
        }
        .nav-section-title {
            font-size: 0.68rem; font-weight: 700; letter-spacing: 0.11em;
            text-transform: uppercase; color: rgba(255, 255, 255, 0.55);
            margin: 20px 10px 10px;
        }
        .sidebar-nav { display: flex; flex-direction: column; gap: 5px; }
        .nav-link {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; border-radius: 12px;
            font-size: 0.9rem !important; font-weight: 600 !important;
            color: rgba(255, 255, 255, 0.82);
            transition: all 0.16s ease;
        }
        .nav-link i { width: 20px !important; min-width: 20px !important; text-align: center !important; font-size: 0.95rem !important; flex-shrink: 0 !important; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.1) !important; color: #ffffff; }
        .nav-link.active {
            background: #083d32 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            font-size: 0.9rem !important;
            box-shadow: 0 4px 14px rgba(8, 61, 50, 0.35) !important;
        }
        .nav-link.active i {
            color: #16cb48 !important;
        }
        .sidebar-footer {
            margin-top: auto; padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .prof-card {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 13px; padding: 14px;
        }
        .prof-card small {
            display: block; font-size: 0.65rem; text-transform: uppercase;
            letter-spacing: 0.09em; color: rgba(255, 255, 255, 0.65); font-weight: 700;
        }
        .prof-card strong {
            display: block; font-size: 0.94rem; font-weight: 800;
            margin-top: 4px; color: #ffffff;
        }

        .main-panel { display: flex; flex-direction: column; min-width: 0; }
        .top-header {
            height: 66px; background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(8px); border-bottom: 1px solid var(--card-border);
            padding: 0 28px; display: flex; align-items: center;
            justify-content: space-between; position: sticky; top: 0; z-index: 40;
        }
        .top-header-title {
            font-size: 1.05rem; font-weight: 800; color: var(--ink-dark);
            display: flex; align-items: center; gap: 10px;
        }
        .top-header-actions { display: flex; align-items: center; gap: 12px; }
        .btn-logout {
            height: 36px; padding: 0 14px; border-radius: 10px;
            border: 1px solid var(--card-border); background: #ffffff;
            color: var(--ink-main); font-size: 0.83rem; font-weight: 700;
            display: inline-flex; align-items: center; gap: 7px;
            transition: all 0.15s ease;
        }
        .btn-logout:hover { background: #fde8e8; color: #c53030; border-color: #f8b4b4; }
        .profile-pill { display: flex; align-items: center; gap: 10px; padding-left: 6px; }
        .avatar-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: #c8e6dc; color: #0c5141; font-size: 0.8rem;
            font-weight: 800; display: grid; place-items: center;
        }
        .profile-meta strong { display: block; font-size: 0.84rem; color: var(--ink-dark); }
        .profile-meta small { font-size: 0.72rem; color: var(--ink-muted); }

        .content-container {
            padding: 22px 28px 36px;
            max-width: 1520px;
            width: 100%;
            margin: 0 auto;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 20px 22px;
            box-shadow: 0 2px 10px rgba(11, 57, 48, 0.03);
            margin-bottom: 20px;
        }
        .card-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; padding-bottom: 14px; margin-bottom: 16px;
            border-bottom: 1px solid #edf3f0; flex-wrap: wrap;
        }
        .card-title {
            font-size: 1.05rem; font-weight: 800; color: var(--ink-dark);
            display: flex; align-items: center; gap: 9px;
        }

        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 9px 15px; border-radius: 11px;
            font-size: 0.84rem; font-weight: 700; cursor: pointer;
            border: 1px solid transparent; transition: all 0.15s ease;
        }
        .btn-primary { background: var(--primary); color: #ffffff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #f2f7f5; color: var(--ink-main); border-color: var(--card-border); }
        .btn-secondary:hover { background: #e4efeb; }
        .btn-success { background: var(--green); color: #fff; }
        .btn-success:hover { background: #0e6e4c; }

        .form-control, .form-input {
            height: 40px; padding: 0 13px; border-radius: 10px;
            border: 1px solid var(--card-border); font-size: 0.85rem;
            color: var(--ink-main); background: #f9fcfa; font-family: inherit;
        }

        .clean-table { width: 100%; border-collapse: collapse; }
        .clean-table thead th {
            text-align: left; padding: 12px 14px; font-size: 0.73rem;
            font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
            color: #46665e; background: #f4f9f7; border-bottom: 1px solid var(--card-border);
        }
        .clean-table tbody tr { border-bottom: 1px solid #edf3f0; transition: background 0.14s ease; }
        .clean-table tbody tr:hover { background: #f8fcfa; }
        .clean-table td { padding: 12px 14px; font-size: 0.85rem; vertical-align: middle; }

        /* Manual Attendance List & Buttons */
        .manual-shell {
            border: 1px solid var(--card-border);
            border-radius: 16px;
            background: #ffffff;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(11, 57, 48, 0.03);
            margin-top: 14px;
        }

        .manual-search-wrap {
            padding: 14px 18px;
            background: #f7fbf9;
            border-bottom: 1px solid #edf3f0;
        }

        .manual-search {
            width: 100%;
            height: 42px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            border-radius: 11px;
            padding: 0 14px;
            font-size: 0.88rem;
            color: var(--ink-main);
            font-family: inherit;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .manual-search:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .manual-actions-section {
            background: #ffffff;
        }

        .manual-actions-title {
            padding: 12px 18px 8px;
            font-size: 0.74rem;
            letter-spacing: 0.1em;
            font-weight: 800;
            text-transform: uppercase;
            color: #46665e;
            background: #f4f9f7;
            border-bottom: 1px solid #edf3f0;
        }

        .manual-attendance-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 18px;
            border-bottom: 1px solid #edf3f0;
            background: #ffffff;
            transition: background 0.14s ease;
        }

        .manual-attendance-row:last-child {
            border-bottom: none;
        }

        .manual-attendance-row:hover {
            background: #f8fcfa;
        }

        .manual-attendance-row.hidden {
            display: none !important;
        }

        .manual-row-meta {
            flex: 1;
            min-width: 0;
        }

        .manual-row-meta strong {
            display: block;
            color: var(--ink-dark);
            font-size: 0.92rem;
            font-weight: 700;
        }

        .manual-row-meta span {
            display: inline-block;
            margin-top: 3px;
            color: var(--ink-muted);
            font-size: 0.76rem;
            font-weight: 600;
        }

        .manual-status-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 6px;
        }

        .btn-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 33px;
            padding: 0 12px;
            border-radius: 9px;
            border: 1px solid var(--card-border);
            background: #f7fbf9;
            color: var(--ink-main);
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.14s ease;
            font-family: inherit;
        }

        .btn-status:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(11, 57, 48, 0.08);
            background: #eef6f3;
        }

        .btn-status.btn-present.active-present {
            background: var(--green) !important;
            color: #ffffff !important;
            border-color: var(--green) !important;
        }

        .btn-status.btn-late.active-late {
            background: var(--amber) !important;
            color: #ffffff !important;
            border-color: var(--amber) !important;
        }

        .btn-status.btn-absent.active-absent {
            background: var(--red) !important;
            color: #ffffff !important;
            border-color: var(--red) !important;
        }

        .btn-status.btn-excused.active-excused {
            background: #1d64c8 !important;
            color: #ffffff !important;
            border-color: #1d64c8 !important;
        }

        .btn-status.btn-clear {
            border-color: #fca5a5;
            color: #dc2626;
            background: #fef2f2;
            padding: 0 10px;
        }

        .btn-status.btn-clear:hover {
            background: #fee2e2;
        }

        @media (max-width: 760px) {
            .manual-attendance-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .manual-status-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }

        @media (max-width: 860px) {
            .layout-shell { grid-template-columns: 1fr; }
            .sidebar { width: 100% !important; min-width: 100% !important; max-width: 100% !important; position: relative; height: auto; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/teacher-account.css">
</head>
<body>

<div class="layout-shell">
    <aside class="sidebar">
        <div class="brand-box">
            <div class="brand-icon" style="background:#ffffff;padding:2px;"><img src="../assets/images/logo.png" alt="School Logo" style="width:100%;height:100%;object-fit:contain;border-radius:50%;"></div>
            <div>
                <div class="brand-title">ODB Montessori School of Novaliches</div>
                <span class="brand-sub">Teacher Portal</span>
            </div>
        </div>

        <div class="nav-section-title">Workspace</div>
        <nav class="sidebar-nav">
            <a href="dashboard.php?subject_id=<?= $selected_subject_id ?>&amp;date=<?= urlencode($selected_date) ?><?= $sectionQuery ?>" class="nav-link">
                <i class="fa-solid fa-qrcode"></i>
                <span>Dashboard &amp; QR Code</span>
            </a>
            <a href="manual_attendance.php?subject_id=<?= $selected_subject_id ?>&amp;date=<?= urlencode($selected_date) ?><?= $sectionQuery ?>" class="nav-link active">
                <i class="fa-solid fa-clipboard-user"></i>
                <span>Student Attendance</span>
            </a>
            <a href="subjects.php" class="nav-link">
                <i class="fa-solid fa-book-open"></i>
                <span>My Subjects &amp; Students</span>
            </a>
            <a href="reports.php" class="nav-link">
                <i class="fa-solid fa-chart-line"></i>
                <span>Attendance Reports</span>
            </a>
            <a href="profile.php" class="nav-link">
                <i class="fa-solid fa-user-gear"></i>
                <span>Profile</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <?php require __DIR__ . '/../views/teacher_account_card.php'; ?>
        </div>
    </aside>

    <div class="main-panel">
        <header class="top-header">
            <div class="top-header-title">
                <i class="fa-solid fa-clipboard-user" style="color:var(--primary);"></i>
                <span>Student Attendance Sheet</span>
            </div>
            <div class="top-header-actions">
                <a href="../logout.php" class="btn-logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
                <?php require __DIR__ . '/../views/teacher_header_profile.php'; ?>
            </div>
        </header>

        <main class="content-container">
            <!-- Notifications -->
            <div id="toast-message" style="display: none; margin-bottom: 1rem;"></div>

            <?php if (!empty($error)): ?>
                <div style="background:var(--red-soft);color:#9b1c1c;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:0.86rem;font-weight:600;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div style="background:var(--green-soft);color:#0c5e40;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:0.86rem;font-weight:600;">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <!-- Header / Control Box -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                    <div>
                        <h2 style="font-size: 1.2rem; font-weight: 800; color: var(--ink-dark); display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-clipboard-user" style="color: var(--primary);"></i> Manual Attendance Checking
                        </h2>
                        <p style="color: var(--ink-muted); font-size: 0.85rem; margin-top: 3px;">
                            Gamitin ang attendance sheet na ito para sa manu-manong pagtatala o pagsusuri ng attendance ng klase.
                        </p>
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" onclick="markAllEnrolledPresent()" class="btn btn-success" <?= $selected_section === '' ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-check-double"></i> Mark Selected Section Present
                        </button>
                        <a href="dashboard.php?subject_id=<?= $selected_subject_id ?>&amp;date=<?= urlencode($selected_date) ?><?= $sectionQuery ?>" class="btn btn-primary">
                            <i class="fa-solid fa-qrcode"></i> Show Attendance QR Code
                        </a>
                    </div>
                </div>

                <!-- Filter Selectors Form -->
                <form action="manual_attendance.php" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                    <div style="flex: 1; min-width: 240px;">
                        <label style="display:block;font-size:0.78rem;font-weight:700;color:var(--ink-muted);margin-bottom:5px;" for="subject_id">
                            <i class="fa-solid fa-book"></i> Subject / Class:
                        </label>
                        <select id="subject_id" name="subject_id" class="form-control" style="width:100%;" onchange="this.form.elements.section.value='';this.form.submit()">
                            <?php if (empty($subjects)): ?>
                                <option value="0">No subjects available</option>
                            <?php else: ?>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $selected_subject_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['subject_code']) ?> — <?= htmlspecialchars($s['subject_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div style="flex:1;min-width:200px;">
                        <label for="section" style="display:block;font-size:0.78rem;font-weight:700;color:var(--ink-muted);margin-bottom:5px;">Section:</label>
                        <select id="section" name="section" class="form-control" style="width:100%;" onchange="this.form.submit()">
                            <?php if ($selected_section === ''): ?><option value="" selected disabled><?= $sectionOptions ? 'Choose a section' : 'No sections available' ?></option><?php endif; ?>
                            <?php foreach ($sectionOptions as $section): ?>
                                <option value="<?= htmlspecialchars($section, ENT_QUOTES) ?>" <?= $selected_section === $section ? 'selected' : '' ?>><?= htmlspecialchars($section) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="width: 200px;">
                        <label style="display:block;font-size:0.78rem;font-weight:700;color:var(--ink-muted);margin-bottom:5px;" for="date">
                            <i class="fa-solid fa-calendar-day"></i> Attendance Date:
                        </label>
                        <input type="date" id="date" name="date" class="form-control" style="width:100%;" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()">
                    </div>

                    <div>
                        <button type="submit" class="btn btn-secondary" style="height: 40px;">
                            <i class="fa-solid fa-arrows-rotate"></i> Load Sheet
                        </button>
                    </div>
                </form>
            </div>

    <!-- Attendance Roster Card -->
    <div class="card">
        <div class="card-header" style="flex-wrap: wrap; gap: 0.75rem;">
            <div class="card-title">
                <i class="fa-solid fa-list-ol" style="color: var(--secondary);"></i> 
                Class Roster (<?= count($students) ?> Enrolled Students) &middot; <?= htmlspecialchars($selected_section !== '' ? $selected_section : 'No section selected') ?>
            </div>
            
            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <!-- Quick Enroll Student Modal Trigger / Dropdown -->
                <?php if (!empty($otherStudents)): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddStudentBox()">
                        <i class="fa-solid fa-user-plus"></i> + Enroll Student
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Student Drawer -->
        <?php if (!empty($otherStudents)): ?>
            <div id="addStudentBox" style="display: none; padding: 1.1rem; background: #f4f9f7; border: 1px solid var(--card-border); border-radius: 14px; margin-bottom: 1.25rem;">
                <form action="manual_attendance.php?subject_id=<?= $selected_subject_id ?>&amp;date=<?= urlencode($selected_date) ?><?= $sectionQuery ?>" method="POST" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
                    <input type="hidden" name="action" value="enroll_and_mark">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['manual_attendance_csrf'], ENT_QUOTES) ?>">
                    <div style="flex: 1; min-width: 250px;">
                        <label class="form-label" style="font-size:0.78rem;font-weight:700;color:var(--ink-muted);margin-bottom:6px;display:block;">Select Registered Student to Add to this Class:</label>
                        <select name="student_identifier" class="form-control" style="width:100%;" required>
                            <option value="">-- Choose Student --</option>
                            <?php foreach ($otherStudents as $os): ?>
                                <option value="<?= htmlspecialchars($os['identifier']) ?>">
                                    <?= htmlspecialchars($os['name']) ?> (<?= htmlspecialchars($os['identifier']) ?> - <?= htmlspecialchars($os['course_section'] ?? 'No section assigned') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="width:160px;">
                        <label class="form-label" style="font-size:0.78rem;font-weight:700;color:var(--ink-muted);margin-bottom:6px;display:block;">Initial Status:</label>
                        <select name="status" class="form-control" style="width:100%;">
                            <option value="Present">Present</option>
                            <option value="Late">Late</option>
                            <option value="Absent">Absent</option>
                            <option value="Excused">Excused</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="height:40px;"><i class="fa-solid fa-plus"></i> Add &amp; Mark</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (empty($students)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--ink-muted);">
                <i class="fa-solid fa-users-slash" style="font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.35;"></i>
                <p>No students enrolled in this subject yet.</p>
                <small>Students can register or you can add them using the "+ Enroll Student" button above.</small>
            </div>
        <?php else: ?>
            <div class="manual-shell" id="rosterTable">
                <div class="manual-search-wrap">
                    <input type="text" id="rosterSearch" class="manual-search" placeholder="Search student name or ID..." onkeyup="filterRosterTable()">
                </div>

                <div class="manual-actions-section">
                    <div class="manual-actions-title">Manual Mark Actions</div>

                    <?php foreach ($students as $stu): ?>
                        <div class="manual-attendance-row" id="row-student-<?= $stu['student_id'] ?>" data-search="<?= strtolower(htmlspecialchars($stu['name'] . ' ' . $stu['identifier'] . ' ' . ($stu['course_section'] ?? ''))) ?>">
                            <div class="manual-row-meta">
                                <strong><?= htmlspecialchars($stu['name']) ?></strong>
                                <span><?= htmlspecialchars($stu['identifier']) ?> · <?= htmlspecialchars($stu['course_section'] ?? 'N/A') ?></span>
                            </div>

                            <div class="manual-status-actions">
                                <button type="button" class="btn-status btn-present <?= $stu['attendance_status'] === 'Present' ? 'active-present' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Present')" title="Mark Present">
                                    <i class="fa-solid fa-check"></i> Present
                                </button>
                                <button type="button" class="btn-status btn-late <?= $stu['attendance_status'] === 'Late' ? 'active-late' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Late')" title="Mark Late">
                                    <i class="fa-solid fa-clock"></i> Late
                                </button>
                                <button type="button" class="btn-status btn-absent <?= $stu['attendance_status'] === 'Absent' ? 'active-absent' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Absent')" title="Mark Absent">
                                    <i class="fa-solid fa-xmark"></i> Absent
                                </button>
                                <button type="button" class="btn-status btn-excused <?= $stu['attendance_status'] === 'Excused' ? 'active-excused' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Excused')" title="Mark Excused">
                                    <i class="fa-solid fa-star"></i> Excused
                                </button>
                                <button type="button" class="btn-status btn-clear"
                                        onclick="clearStudentAttendance(<?= $stu['student_id'] ?>)" title="Clear / Reset Attendance">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
        </main>
    </div>
</div>

<script>
    const currentSection = <?= json_encode($selected_section) ?>;
    const attendanceCsrf = <?= json_encode($_SESSION['manual_attendance_csrf']) ?>;
    const currentSubjectId = <?= (int)$selected_subject_id ?>;
    const currentDate = "<?= addslashes($selected_date) ?>";

    // Audio effects
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    function playBeep(freq = 880, duration = 0.12) {
        try {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch(e) {}
    }

    function showToast(msg, type = 'success') {
        const toast = document.getElementById("toast-message");
        toast.style.display = "block";
        toast.className = `alert alert-${type}`;
        toast.innerHTML = `<i class="fa-solid fa-circle-info"></i> <span>${msg}</span>`;
        setTimeout(() => {
            toast.style.display = "none";
        }, 3500);
    }

    function toggleAddStudentBox() {
        const box = document.getElementById("addStudentBox");
        box.style.display = box.style.display === "none" ? "block" : "none";
    }

    function filterRosterTable() {
        const q = document.getElementById("rosterSearch").value.toLowerCase();
        const rows = document.querySelectorAll("#rosterTable .manual-attendance-row");
        rows.forEach(r => {
            const text = r.getAttribute("data-search") || "";
            r.classList.toggle("hidden", q && !text.includes(q));
            if (!q) r.classList.remove("hidden");
        });
    }

    function updateStudentAttendance(studentId, status) {
        if (!currentSection) return;
        fetch('../api/manual_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'set_status',
                section: currentSection,
                csrf: attendanceCsrf,
                subject_id: currentSubjectId,
                student_id: studentId,
                date: currentDate,
                status: status
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                playBeep(880, 0.1);
                showToast(data.message, 'success');

                // Update Status Cell
                // Update button active states in row
                const row = document.getElementById(`row-student-${studentId}`);
                row.querySelectorAll(".btn-status").forEach(b => {
                    b.classList.remove('active-present', 'active-late', 'active-absent', 'active-excused');
                });
                if (status === 'Present') row.querySelector('.btn-present').classList.add('active-present');
                if (status === 'Late') row.querySelector('.btn-late').classList.add('active-late');
                if (status === 'Absent') row.querySelector('.btn-absent').classList.add('active-absent');
                if (status === 'Excused') row.querySelector('.btn-excused').classList.add('active-excused');
            } else {
                showToast(data.message || 'Error updating attendance', 'danger');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Server error while saving attendance.', 'danger');
        });
    }

    function clearStudentAttendance(studentId) {
        if (!currentSection) return;
        if (!confirm("Clear attendance record for this student on this date?")) return;

        fetch('../api/manual_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'clear_record',
                section: currentSection,
                csrf: attendanceCsrf,
                subject_id: currentSubjectId,
                student_id: studentId,
                date: currentDate
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message, 'info');
                const row = document.getElementById(`row-student-${studentId}`);
                row.querySelectorAll(".btn-status").forEach(b => {
                    b.classList.remove('active-present', 'active-late', 'active-absent', 'active-excused');
                });
            }
        });
    }

    function markAllEnrolledPresent() {
        if (!currentSection) return;
        if (!confirm(`Mark all students in ${currentSection} as Present for ${currentDate}?`)) return;

        fetch('../api/manual_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'mark_all_present',
                section: currentSection,
                csrf: attendanceCsrf,
                subject_id: currentSubjectId,
                date: currentDate
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                playBeep(980, 0.18);
                showToast(data.message, 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 800);
            } else {
                showToast(data.message || 'Could not mark attendance.', 'danger');
            }
        }).catch(() => showToast('Server error while saving attendance.', 'danger'));
    }
</script>

</body>
</html>
