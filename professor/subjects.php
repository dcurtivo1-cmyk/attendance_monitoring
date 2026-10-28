<?php
// professor/subjects.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/subject_sections.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();
$accountStmt = $pdo->prepare('SELECT id, name, email, identifier FROM users WHERE id = ?');
$accountStmt->execute([$user['id']]);
$teacherAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: $user;
$prof_id = $user['id'];

$error = '';
$success = '';

// Add an existing student only to a subject owned by the signed-in professor.
$_SESSION['enrollment_csrf'] ??= bin2hex(random_bytes(32));
$success = $_SESSION['enrollment_success'] ?? '';
unset($_SESSION['enrollment_success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student') {
    try {
        if (!hash_equals($_SESSION['enrollment_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
        }
        $enrollSubjectId = filter_var($_POST['subject_id'] ?? '', FILTER_VALIDATE_INT);
        $enrollStudentId = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$enrollSubjectId || !$enrollStudentId) throw new InvalidArgumentException('Choose a valid subject and student.');
        $pdo->beginTransaction();
        $owned = $pdo->prepare('SELECT s.id, s.subject_code, s.grade_level_id, gl.grade_name FROM subjects s LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id WHERE s.id = ? AND s.professor_id = ? FOR UPDATE');
        $owned->execute([$enrollSubjectId, $prof_id]);
        $ownedSubject = $owned->fetch();
        if (!$ownedSubject) throw new InvalidArgumentException('You can only add students to your own subjects.');
        if (!isset($_POST['section']) || !is_string($_POST['section'])) throw new InvalidArgumentException('Choose a section before adding a student.');
        $enrollSection = $_POST['section'];
        $allowedSections = subject_section_counts($pdo, (int)$enrollSubjectId, $ownedSubject['grade_level_id'] !== null ? (int)$ownedSubject['grade_level_id'] : null);
        if (!array_key_exists($enrollSection, $allowedSections)) throw new InvalidArgumentException('Choose a valid section for this subject.');
        $studentQuery = $pdo->prepare("SELECT id, name, course_section, grade_level FROM users WHERE id = ? AND role = 'student' AND status = 'Active' FOR UPDATE");
        $studentQuery->execute([$enrollStudentId]);
        $enrollStudent = $studentQuery->fetch();
        if (!$enrollStudent) throw new InvalidArgumentException('Select an active registered student.');
        if (($enrollStudent['course_section'] ?? '') !== $enrollSection || ($ownedSubject['grade_name'] !== null && $enrollStudent['grade_level'] !== $ownedSubject['grade_name'])) {
            throw new InvalidArgumentException('Select a student from this section and grade level.');
        }
        $existing = $pdo->prepare('SELECT id FROM student_subjects WHERE subject_id = ? AND student_id = ?');
        $existing->execute([$enrollSubjectId, $enrollStudentId]);
        if ($existing->fetch()) throw new InvalidArgumentException('This student is already enrolled in this subject.');
        $pdo->prepare('INSERT INTO student_subjects (student_id, subject_id) VALUES (?, ?)')->execute([$enrollStudentId, $enrollSubjectId]);
        $pdo->commit();
        $_SESSION['enrollment_success'] = $enrollStudent['name'] . ' was added to ' . $ownedSubject['subject_code'] . ' (' . ($enrollSection !== '' ? $enrollSection : 'No section assigned') . ').';
        header('Location: subjects.php?subject_id=' . $enrollSubjectId . '&section=' . rawurlencode($enrollSection) . '#subject-students');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to add the student. Refresh the list and try again.';
        if (!($e instanceof InvalidArgumentException)) error_log('Subject enrollment failed: ' . $e->getMessage());
    }
}

// Subject creation belongs to the admin portal, including direct POST requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_subject') {
    http_response_code(403);
    $error = 'Only administrators can add subjects and schedules. Contact the administrator for a new class.';
}

// Fetch all subjects of professor with their schedules, then separate each section.
$stmt = $pdo->prepare("SELECT s.*, gl.grade_name, sc.day_of_week, sc.start_time, sc.end_time, sc.late_threshold_mins
    FROM subjects s LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
    LEFT JOIN schedules sc ON s.id = sc.subject_id
    WHERE s.professor_id = ? ORDER BY s.subject_code, sc.day_of_week, sc.start_time");
$stmt->execute([$prof_id]);
$subjects = $stmt->fetchAll();
$sectionCountsBySubject = [];
$subjectClasses = [];
foreach ($subjects as $subject) {
    $subjectId = (int)$subject['id'];
    if (!isset($sectionCountsBySubject[$subjectId])) {
        $sectionCountsBySubject[$subjectId] = subject_section_counts($pdo, $subjectId, $subject['grade_level_id'] !== null ? (int)$subject['grade_level_id'] : null);
    }
    foreach ($sectionCountsBySubject[$subjectId] as $section => $count) {
        $classKey = $subjectId . ':' . $section;
        if (!isset($subjectClasses[$classKey])) {
            $subjectClasses[$classKey] = $subject + ['section_name' => $section, 'enrolled_students' => $count, 'schedules' => []];
        }
        if ($subject['day_of_week']) $subjectClasses[$classKey]['schedules'][] = $subject;
    }
}
$subjectClasses = array_values($subjectClasses);

$isEnrollmentPost = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student';
$selection = $isEnrollmentPost ? $_POST : $_GET;
$selected_subject_id = intval($selection['subject_id'] ?? 0);
$selected_section = isset($selection['section']) && is_string($selection['section']) ? $selection['section'] : null;
$selectedSubject = null;
$enrolledStudents = [];
$availableStudents = [];
$selectedSectionCounts = [];
if ($selected_subject_id > 0) {
    foreach ($subjects as $subject) {
        if ((int)$subject['id'] === $selected_subject_id) { $selectedSubject = $subject; break; }
    }
    if ($selectedSubject) {
        $selectedSectionCounts = $sectionCountsBySubject[$selected_subject_id];
        if ($selected_section === null || $selected_section === 'ALL') $selected_section = array_key_first($selectedSectionCounts);
        if (!array_key_exists($selected_section, $selectedSectionCounts)) {
            $selectedSubject = null;
            $error = $error ?: 'Section not found for this subject. Choose a section from the list.';
        }
    }
    if ($selectedSubject) {
        $studentsStmt = $pdo->prepare("SELECT u.identifier, u.name, u.email, u.course_section FROM student_subjects ss JOIN users u ON u.id = ss.student_id
            WHERE ss.subject_id = ? AND u.role = 'student' AND COALESCE(u.course_section, '') = ? ORDER BY u.name, u.id");
        $studentsStmt->execute([$selected_subject_id, $selected_section]);
        $enrolledStudents = $studentsStmt->fetchAll();
        $availableStmt = $pdo->prepare("SELECT u.id, u.identifier, u.name, u.grade_level, u.course_section
            FROM users u WHERE u.role = 'student' AND u.status = 'Active' AND COALESCE(u.course_section, '') = ?
            AND (? IS NULL OR u.grade_level = ?)
            AND NOT EXISTS (SELECT 1 FROM student_subjects ss WHERE ss.student_id = u.id AND ss.subject_id = ?)
            ORDER BY u.name, u.id");
        $availableStmt->execute([$selected_section, $selectedSubject['grade_name'], $selectedSubject['grade_name'], $selected_subject_id]);
        $availableStudents = $availableStmt->fetchAll();
    }
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
    <title>My Subjects &amp; Students - Teacher Portal</title>
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

        .subjects-column-stack {
            display: flex;
            flex-direction: column;
            gap: 22px;
            width: 100%;
        }

        .subject-search-wrap {
            position: relative;
            margin-bottom: 16px;
        }

        .subject-search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-muted);
            font-size: 0.9rem;
            pointer-events: none;
        }

        .subject-search-wrap input {
            padding-left: 40px !important;
            height: 44px !important;
            border-radius: 12px !important;
            font-size: 0.88rem !important;
            border: 1px solid var(--card-border);
            background: #fdfefe;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            width: 100%;
        }

        .subject-search-wrap input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 91, 73, 0.12);
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

        .form-group { margin-bottom: 14px; }
        .form-label { display: block; font-size: 0.78rem; font-weight: 700; color: var(--ink-muted); margin-bottom: 6px; }
        .form-control {
            width: 100%; height: 40px; padding: 0 13px; border-radius: 10px;
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

        @media (max-width: 992px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 860px) {
            .layout-shell { grid-template-columns: 1fr; }
            .sidebar { width: 100% !important; min-width: 100% !important; max-width: 100% !important; position: relative; height: auto; }
        }
        .section-pill { display:inline-block;padding:6px 10px;border-radius:9px;background:#e4f1ec;color:#0d5b49;font-weight:800;font-size:.8rem;white-space:nowrap; }
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
            <a href="dashboard.php" class="nav-link">
                <i class="fa-solid fa-qrcode"></i>
                <span>Dashboard &amp; QR Code</span>
            </a>
            <a href="manual_attendance.php" class="nav-link">
                <i class="fa-solid fa-clipboard-user"></i>
                <span>Student Attendance</span>
            </a>
            <a href="subjects.php" class="nav-link active">
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
                <i class="fa-solid fa-book-open" style="color:var(--primary);"></i>
                <span>My Subjects &amp; Enrolled Students</span>
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

            <div class="subjects-column-stack">
                <!-- 1. My Existing Subjects List (Top, with search bar) -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-list-check" style="color: var(--primary);"></i> My Handled Subjects</div>
                        <span id="subjectsCountBadge" style="background:var(--primary-soft);color:var(--primary);padding:4px 12px;border-radius:999px;font-size:0.75rem;font-weight:800;"><?= count($subjectClasses) ?> Classes by Section</span>
                    </div>

                    <!-- Search Bar for Handled Subjects -->
                    <div class="subject-search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="subjectSearchInput" class="form-control" placeholder="Maghanap ng subject code, subject title, section, schedule, o students..." oninput="filterSubjectsTable()">
                    </div>

                    <?php if (empty($subjects)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--ink-muted);">
                            <i class="fa-solid fa-folder-open" style="font-size: 2.2rem; margin-bottom: 0.5rem; opacity: 0.4;"></i>
                            <p>No subjects assigned yet. Contact the administrator to assign your subjects and class schedules.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="clean-table" id="subjectsTable">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Description / Subject Title</th>
                                        <th>Section</th>
                                        <th>Class Schedule</th>
                                        <th>Enrolled Students</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjectClasses as $s): 
                                        $scheduleLabels = array_map(static fn($schedule) => $schedule['day_of_week'] . ' ' . date('h:i A', strtotime($schedule['start_time'])) . ' - ' . date('h:i A', strtotime($schedule['end_time'])), $s['schedules']);
                                        $schedStr = implode(' | ', $scheduleLabels);
                                        $searchPayload = strtolower($s['subject_code'] . ' ' . $s['subject_name'] . ' ' . $s['section_name'] . ' ' . $schedStr . ' ' . $s['enrolled_students']);
                                    ?>
                                        <tr class="subject-row" data-search="<?= htmlspecialchars($searchPayload) ?>" style="<?= $s['id'] == $selected_subject_id && $s['section_name'] === $selected_section ? 'background: #eef6f3; font-weight: bold;' : '' ?>">
                                            <td><span style="background:var(--primary-soft);color:var(--primary);padding:4px 9px;border-radius:8px;font-size:0.78rem;font-weight:800;"><?= htmlspecialchars($s['subject_code']) ?></span></td>
                                            <td>
                                                <strong style="color:var(--ink-dark);"><?= htmlspecialchars($s['subject_name']) ?></strong>
                                            </td>
                                            <td><span class="section-pill"><?= htmlspecialchars($s['section_name'] !== '' ? $s['section_name'] : 'No section assigned') ?></span></td>
                                            <td>
                                                <?php foreach ($scheduleLabels as $scheduleLabel): ?>
                                                    <div style="font-size:0.8rem;margin-bottom:4px;"><?= htmlspecialchars($scheduleLabel) ?></div>
                                                <?php endforeach; ?>
                                                <?php if (!$scheduleLabels): ?><span style="color:var(--ink-muted);">No schedule assigned</span><?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#e0f0ff;color:#1d64c8;font-size:0.76rem;font-weight:700;">
                                                    <i class="fa-solid fa-users" style="font-size:0.7rem;margin-right:4px;"></i><?= $s['enrolled_students'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="subjects.php?subject_id=<?= (int)$s['id'] ?>&amp;section=<?= rawurlencode($s['section_name']) ?>#subject-students" class="btn btn-secondary" style="padding:5px 11px;font-size:0.76rem;">
                                                    <i class="fa-solid fa-eye"></i> View / Add Students
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="noSubjectMatchRow" style="display:none;">
                                        <td colspan="6" style="text-align:center; padding:24px; color:var(--ink-muted);">
                                            <i class="fa-solid fa-magnifying-glass" style="font-size:1.2rem; margin-bottom:6px; opacity:0.5; display:block;"></i>
                                            Walang nahanap na subject na tumutugma sa iyong paghahanap.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- View Enrolled Students Table Drawer -->
            <?php if ($selected_subject_id > 0): ?>
                <div class="card" id="subject-students" style="margin-top:20px;">
                    <?php if (!$selectedSubject): ?>
                        <div style="background:var(--red-soft);color:#9b1c1c;padding:12px;border-radius:10px;">Subject or section not found.</div>
                    <?php else: ?>
                        <div class="card-header">
                            <div class="card-title">
                                <i class="fa-solid fa-users" style="color: var(--primary);"></i> 
                                <?= htmlspecialchars($selectedSubject['subject_code']) ?> &middot; <?= htmlspecialchars($selected_section !== '' ? $selected_section : 'No section assigned') ?>
                            </div>
                            <span style="background:var(--primary-soft);color:var(--primary);padding:4px 10px;border-radius:999px;font-size:0.75rem;font-weight:800;"><?= count($enrolledStudents) ?> Students</span>
                        </div>
                        <p style="margin: -0.5rem 0 1rem; color: var(--ink-muted); font-size:0.85rem;"><?= htmlspecialchars($selectedSubject['subject_name']) ?></p>

                        <form action="subjects.php#subject-students" method="get" style="margin-bottom:18px;">
                            <input type="hidden" name="subject_id" value="<?= (int)$selected_subject_id ?>">
                            <label class="form-label" for="class-section">Section</label>
                            <select id="class-section" name="section" class="form-control" onchange="this.form.submit()">
                                <?php foreach ($selectedSectionCounts as $sectionName => $sectionCount): ?>
                                    <option value="<?= htmlspecialchars($sectionName, ENT_QUOTES) ?>" <?= $selected_section === $sectionName ? 'selected' : '' ?>><?= htmlspecialchars($sectionName !== '' ? $sectionName : 'No section assigned') ?> (<?= (int)$sectionCount ?> students)</option>
                                <?php endforeach; ?>
                            </select>
                            <noscript><button class="btn btn-secondary" type="submit">View Section</button></noscript>
                        </form>

                        <details id="add-student" style="margin-bottom:20px;border:1px solid var(--card-border);border-radius:12px;padding:16px;"<?= $error && ($_POST['action'] ?? '') === 'add_student' ? ' open' : '' ?>>
                            <summary style="cursor:pointer;color:var(--primary);font-weight:800;"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add Student to This Section</summary>
                            <p style="margin:12px 0;color:var(--ink-muted);font-size:0.85rem;">Select an existing student to enroll in <strong><?= htmlspecialchars($selectedSubject['subject_code']) ?> - <?= htmlspecialchars($selectedSubject['subject_name']) ?></strong>. Only students from <strong><?= htmlspecialchars($selected_section !== '' ? $selected_section : 'No section assigned') ?></strong> are shown.</p>
                            <?php if ($availableStudents): ?>
                            <form method="POST" action="subjects.php?subject_id=<?= (int)$selected_subject_id ?>&amp;section=<?= rawurlencode($selected_section) ?>#subject-students">
                                <input type="hidden" name="action" value="add_student">
                                <input type="hidden" name="section" value="<?= htmlspecialchars($selected_section, ENT_QUOTES) ?>">
                                <input type="hidden" name="subject_id" value="<?= (int)$selected_subject_id ?>">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['enrollment_csrf']) ?>">
                                <label class="form-label" for="student-search">Find student by name, ID, grade, or section</label>
                                <input type="search" class="form-control" id="student-search" placeholder="Search students..." style="margin-bottom:12px;">
                                <label class="form-label" for="student-id">Registered student</label>
                                <select class="form-control" name="student_id" id="student-id" required style="margin-bottom:12px;">
                                    <option value="">Choose a student</option>
                                    <?php foreach ($availableStudents as $candidate): ?>
                                    <option value="<?= (int)$candidate['id'] ?>"><?= htmlspecialchars($candidate['name'] . ' | ' . $candidate['identifier'] . ' | ' . ($candidate['grade_level'] ?: 'No grade') . ' | ' . ($candidate['course_section'] ?: 'No section')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p id="student-search-empty" role="status" hidden style="margin-bottom:12px;color:var(--ink-muted);">No students match your search.</p>
                                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add Student</button>
                            </form>
                            <?php else: ?>
                                <p style="color:var(--ink-muted);">All active students in this section are already enrolled, or no active students are available in this section. New student accounts can be created by the administrator.</p>
                            <?php endif; ?>
                        </details>

                        <?php if (empty($enrolledStudents)): ?>
                            <p style="padding: 1rem 0; color: var(--ink-muted);">No students are enrolled in this section for this subject yet.</p>
                        <?php else: ?>
                            <div style="overflow-x:auto;">
                                <table class="clean-table">
                                    <thead>
                                        <tr><th>Student ID</th><th>Name</th><th>Email</th><th>Section</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($enrolledStudents as $student): ?>
                                            <tr>
                                                <td><span style="font-weight:700;color:var(--ink-dark);"><?= htmlspecialchars($student['identifier']) ?></span></td>
                                                <td><strong><?= htmlspecialchars($student['name']) ?></strong></td>
                                                <td><?= htmlspecialchars($student['email']) ?></td>
                                                <td><?= htmlspecialchars($student['course_section'] ?: 'No section assigned') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
    document.getElementById('student-search')?.addEventListener('input', function () {
        const select = document.getElementById('student-id');
        const query = this.value.trim().toLowerCase();
        const options = [...select.options].filter(option => option.value);
        options.forEach(option => { option.hidden = !option.textContent.toLowerCase().includes(query); });
        if (select.selectedOptions[0]?.hidden) select.value = '';
        document.getElementById('student-search-empty').hidden = options.some(option => !option.hidden);
    });
    if (location.hash === '#add-student') {
        const addStudent = document.getElementById('add-student');
        if (addStudent) addStudent.open = true;
    }

    function filterSubjectsTable() {
        const input = document.getElementById('subjectSearchInput');
        if (!input) return;
        const q = input.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.subject-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const searchData = row.getAttribute('data-search') || '';
            if (!q || searchData.includes(q)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        const noMatchRow = document.getElementById('noSubjectMatchRow');
        if (noMatchRow) {
            noMatchRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
        }

        const countBadge = document.getElementById('subjectsCountBadge');
        if (countBadge) {
            countBadge.textContent = q ? `${visibleCount} of <?= count($subjectClasses) ?> Classes by Section` : `<?= count($subjectClasses) ?> Classes by Section`;
        }
    }
</script>

</body>
</html>