<?php
// professor/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();

$prof_id = (int)$user['id'];
$today = date('Y-m-d');

// Refresh full professor record from users
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$uStmt->execute([$prof_id]);
$profUser = $uStmt->fetch() ?: $user;

// Ensure professor has a QR token
$profQrToken = trim($profUser['qr_token'] ?? '');
if ($profQrToken === '') {
    $profQrToken = 'PROF_QR_' . md5(($profUser['identifier'] ?? $prof_id) . '_attendance');
    $pdo->prepare("UPDATE users SET qr_token = ? WHERE id = ?")->execute([$profQrToken, $prof_id]);
}

// 1. Fetch Professor Subjects
$subjectsStmt = $pdo->prepare("
    SELECT s.*, gl.grade_name,
           sc.day_of_week, sc.start_time, sc.end_time
    FROM subjects s
    LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
    LEFT JOIN schedules sc ON sc.subject_id = s.id
    WHERE s.professor_id = ?
    GROUP BY s.id
    ORDER BY s.subject_code ASC
");
$subjectsStmt->execute([$prof_id]);
$subjects = $subjectsStmt->fetchAll();

// Fallback if no subjects directly assigned to this user_id yet: link from teachers table or take first available subject
if (empty($subjects)) {
    $tStmt = $pdo->prepare("SELECT subject_id FROM teachers WHERE user_id = ? OR email = ? LIMIT 1");
    $tStmt->execute([$prof_id, $profUser['email'] ?? '']);
    $tSubId = (int)$tStmt->fetchColumn();
    if ($tSubId > 0) {
        $pdo->prepare("UPDATE subjects SET professor_id = ? WHERE id = ?")->execute([$prof_id, $tSubId]);
        $subjectsStmt->execute([$prof_id]);
        $subjects = $subjectsStmt->fetchAll();
    }
}

// Build array of handled subjects with their sections
$handledSubjectsWithSections = [];
$defaultSecMap = [
    'Grade 7'  => 'Section A - Rizal',
    'Grade 8'  => 'Section A - Bonifacio',
    'Grade 9'  => 'Section A - Mabini',
    'Grade 10' => 'Section A - Luna',
];

foreach ($subjects as $s) {
    $sId = (int)$s['id'];
    $secStmt = $pdo->prepare("
        SELECT DISTINCT u.course_section
        FROM student_subjects ss
        JOIN users u ON u.id = ss.student_id
        WHERE ss.subject_id = ? AND u.course_section IS NOT NULL AND TRIM(u.course_section) != ''
        ORDER BY u.course_section ASC
    ");
    $secStmt->execute([$sId]);
    $foundSections = $secStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($foundSections)) {
        $gName = $s['grade_name'] ?? 'Grade 8';
        $foundSections = [$defaultSecMap[$gName] ?? 'Section A - Bonifacio'];
    }

    $handledSubjectsWithSections[$sId] = [
        'subject'  => $s,
        'sections' => $foundSections
    ];
}

$selected_subject_id = intval($_GET['subject_id'] ?? ($subjects[0]['id'] ?? 0));
$selected_section    = trim($_GET['section'] ?? '');
$selected_date       = trim($_GET['date'] ?? $today);

// Active subject info for QR Generator
$activeSubject = null;
foreach ($subjects as $s) {
    if ((int)$s['id'] === $selected_subject_id) {
        $activeSubject = $s;
        break;
    }
}
if (!$activeSubject && !empty($subjects)) {
    $activeSubject = $subjects[0];
    $selected_subject_id = (int)$activeSubject['id'];
}

// Default section if empty
if ($selected_section === '' && isset($handledSubjectsWithSections[$selected_subject_id])) {
    $selected_section = 'ALL';
}

// AJAX polling endpoint for real-time student attendance updates when students scan the professor's QR code
if (isset($_GET['ajax_poll']) && $_GET['ajax_poll'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $pollSubId = intval($_GET['subject_id'] ?? $selected_subject_id);
    $pollDate  = trim($_GET['date'] ?? $today);
    $pollSec   = trim($_GET['section'] ?? $selected_section);

    $rosterQuery = "
        SELECT u.id AS student_id,
               u.identifier,
               u.name,
               u.email,
               u.course_section,
               u.grade_level,
               s.id AS subject_id,
               s.subject_code,
               s.subject_name,
               a.id AS attendance_id,
               a.time_in,
               a.status AS attendance_status,
               CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END AS is_scanned
        FROM student_subjects ss
        JOIN users u ON ss.student_id = u.id
        JOIN subjects s ON ss.subject_id = s.id
        LEFT JOIN attendance a ON a.student_id = u.id AND a.subject_id = s.id AND a.date = :att_date
        WHERE s.professor_id = :prof_id
    ";
    $params = [':att_date' => $pollDate, ':prof_id' => $prof_id];
    if ($pollSubId > 0) {
        $rosterQuery .= " AND s.id = :sub_id";
        $params[':sub_id'] = $pollSubId;
    }
    if ($pollSec !== '' && $pollSec !== 'ALL') {
        $rosterQuery .= " AND u.course_section = :sec";
        $params[':sec'] = $pollSec;
    }
    $rosterQuery .= " ORDER BY is_scanned DESC, a.time_in DESC, u.name ASC";

    $rosterStmt = $pdo->prepare($rosterQuery);
    $rosterStmt->execute($params);
    $rows = $rosterStmt->fetchAll();

    $scannedCount   = 0;
    $present        = 0;
    $late           = 0;
    $absent         = 0;
    $unscannedCount = 0;

    foreach ($rows as &$r) {
        $r['is_scanned'] = (int)$r['is_scanned'];
        $r['formatted_time'] = !empty($r['time_in']) ? date('h:i A', strtotime($r['time_in'])) : '—';
        if ($r['is_scanned'] === 1) {
            $scannedCount++;
            if ($r['attendance_status'] === 'Present') $present++;
            elseif ($r['attendance_status'] === 'Late') $late++;
            elseif ($r['attendance_status'] === 'Absent') $absent++;
        } else {
            $unscannedCount++;
            $r['attendance_status'] = 'Pending';
        }
    }
    unset($r);

    echo json_encode([
        'status'         => 'success',
        'total'          => count($rows),
        'scanned'        => $scannedCount,
        'present'        => $present,
        'late'           => $late,
        'absent'         => $absent,
        'unscanned'      => $unscannedCount,
        'students'       => $rows
    ]);
    exit;
}

// Fetch complete list of students assigned to this professor (with today's attendance status if scanned)
$studentsQuery = "
    SELECT u.id AS student_id,
           u.identifier,
           u.name,
           u.email,
           u.course_section,
           u.grade_level,
           s.id AS subject_id,
           s.subject_code,
           s.subject_name,
           a.id AS attendance_id,
           a.time_in,
           a.status AS attendance_status,
           CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END AS is_scanned
    FROM student_subjects ss
    JOIN users u ON ss.student_id = u.id
    JOIN subjects s ON ss.subject_id = s.id
    LEFT JOIN attendance a ON a.student_id = u.id AND a.subject_id = s.id AND a.date = :att_date
    WHERE s.professor_id = :prof_id
";
$queryParams = [':att_date' => $selected_date, ':prof_id' => $prof_id];
if ($selected_subject_id > 0) {
    $studentsQuery .= " AND s.id = :sub_id";
    $queryParams[':sub_id'] = $selected_subject_id;
}
if ($selected_section !== '' && $selected_section !== 'ALL') {
    $studentsQuery .= " AND u.course_section = :sec";
    $queryParams[':sec'] = $selected_section;
}
$studentsQuery .= " ORDER BY is_scanned DESC, a.time_in DESC, u.name ASC";

$stuStmt = $pdo->prepare($studentsQuery);
$stuStmt->execute($queryParams);
$assignedStudents = $stuStmt->fetchAll();

$totalAssigned   = count($assignedStudents);
$scannedCount    = 0;
$presentCount    = 0;
$lateCount       = 0;
$absentCount     = 0;
$unscannedCount  = 0;

foreach ($assignedStudents as &$stRow) {
    $stRow['is_scanned'] = (int)$stRow['is_scanned'];
    if ($stRow['is_scanned'] === 1) {
        $scannedCount++;
        if ($stRow['attendance_status'] === 'Present') $presentCount++;
        elseif ($stRow['attendance_status'] === 'Late') $lateCount++;
        elseif ($stRow['attendance_status'] === 'Absent') $absentCount++;
    } else {
        $unscannedCount++;
        $stRow['attendance_status'] = 'Pending';
    }
}
unset($stRow);

// QR Payload format encoded in the Professor's QR Code: PROF_ATTENDANCE|{profQrToken}|{subject_id}|{date}|{section}
$sectionPart = ($selected_section !== '' && $selected_section !== 'ALL') ? '|' . $selected_section : '';
$qrPayloadString = 'PROF_ATTENDANCE|' . $profQrToken . '|' . $selected_subject_id . '|' . $selected_date . $sectionPart;

$profDisplayName = preg_replace('/^Prof\.?\s+/i', 'Teacher ', trim($profUser['name'] ?? 'Teacher'));
$profParts = preg_split('/\s+/', trim(str_replace(['Teacher', 'Prof.', 'Mr.', 'Ms.', 'Mrs.', "Ma'am", 'Sir'], '', $profDisplayName)));
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
    <title>Teacher Dashboard - QR Code Generator &amp; Student Attendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- QR Code Generator Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--page-bg);
            color: var(--ink-main);
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .layout-shell {
            display: grid;
            grid-template-columns: 255px minmax(0, 1fr);
            min-height: 100vh;
        }

        /* Sidebar */
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

        .brand-title {
            font-size: 0.9rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-sub {
            display: block;
            font-size: 0.64rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.65);
            margin-top: 3px;
        }

        .nav-section-title {
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.11em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.55);
            margin: 20px 10px 10px;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 12px;
            font-size: 0.9rem !important;
            font-weight: 600 !important;
            color: rgba(255, 255, 255, 0.82);
            transition: all 0.16s ease;
        }

        .nav-link i {
            width: 20px !important;
            min-width: 20px !important;
            text-align: center !important;
            font-size: 0.95rem !important;
            flex-shrink: 0 !important;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            color: #ffffff;
        }

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
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .prof-card {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 13px;
            padding: 14px;
        }

        .prof-card small {
            display: block;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: rgba(255, 255, 255, 0.65);
            font-weight: 700;
        }

        .prof-card strong {
            display: block;
            font-size: 0.94rem;
            font-weight: 800;
            margin-top: 4px;
            color: #ffffff;
        }

        /* Main Area */
        .main-panel {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .top-header {
            height: 66px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--card-border);
            padding: 0 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .top-header-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--ink-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-logout {
            height: 36px;
            padding: 0 14px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            color: var(--ink-main);
            font-size: 0.83rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.15s ease;
        }

        .btn-logout:hover {
            background: #fde8e8;
            color: #c53030;
            border-color: #f8b4b4;
        }

        .profile-pill {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-left: 6px;
        }

        .avatar-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #c8e6dc;
            color: #0c5141;
            font-size: 0.8rem;
            font-weight: 800;
            display: grid;
            place-items: center;
        }

        .profile-meta strong {
            display: block;
            font-size: 0.84rem;
            color: var(--ink-dark);
        }

        .profile-meta small {
            font-size: 0.72rem;
            color: var(--ink-muted);
        }

        .content-container {
            padding: 22px 28px 36px;
            max-width: 1520px;
            width: 100%;
            margin: 0 auto;
        }

        /* Hero Banner (Unified with Student & Admin Portal Design) */
        .hero-banner {
            background: linear-gradient(135deg, #0b4d3e 0%, #0d6852 100%);
            border-radius: 18px;
            padding: 22px 26px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 22px;
            box-shadow: 0 10px 26px rgba(11, 77, 62, 0.16);
        }

        .hero-banner h1 {
            font-size: 1.55rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #ffffff;
        }

        .hero-banner p {
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.85);
            margin-top: 4px;
            max-width: 720px;
        }

        .hero-badges {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .hero-badge {
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #ffffff;
        }

        .filter-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 8px 12px;
            border-radius: 14px;
        }

        .select-input, .date-input {
            height: 40px;
            padding: 0 13px;
            border-radius: 11px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: #ffffff;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--ink-dark);
            outline: none;
        }

        /* 4 Summary Metrics */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 17px 19px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 8px rgba(11, 57, 48, 0.03);
        }

        .kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .kpi-icon.blue { background: #e0f0ff; color: #1d64c8; }
        .kpi-icon.teal { background: #dff4ec; color: #0d6852; }
        .kpi-icon.green { background: #def7ec; color: #12825c; }
        .kpi-icon.amber { background: #fef0d9; color: #c87412; }
        .kpi-icon.red { background: #fde8e8; color: #c83737; }

        .kpi-val {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--ink-dark);
            line-height: 1.1;
        }

        .kpi-label {
            font-size: 0.8rem;
            color: var(--ink-muted);
            font-weight: 600;
            margin-top: 3px;
        }

        /* Main Grid: Left QR Generator + Right Student Attendance Roster */
        .prof-workspace-grid {
            display: grid;
            grid-template-columns: 390px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 20px 22px;
            box-shadow: 0 2px 10px rgba(11, 57, 48, 0.03);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding-bottom: 14px;
            margin-bottom: 16px;
            border-bottom: 1px solid #edf3f0;
            flex-wrap: wrap;
        }

        .card-title {
            font-size: 1.02rem;
            font-weight: 800;
            color: var(--ink-dark);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .card-title i {
            color: var(--primary);
        }

        /* QR Generator Card Styling */
        .qr-generator-card {
            background: linear-gradient(180deg, #0b4d3e 0%, #07362b 100%);
            color: #ffffff;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 12px 28px rgba(7, 56, 46, 0.18);
            text-align: center;
        }

        .qr-generator-card h2 {
            font-size: 1.12rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .qr-generator-card p {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.78);
            margin-top: 5px;
        }

        .qr-dropdown-wrap {
            margin: 14px 0 16px;
            text-align: left;
            background: rgba(0, 0, 0, 0.22);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 14px;
            padding: 12px 14px;
        }

        .qr-dropdown-label {
            display: block;
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #8be8cb;
            margin-bottom: 7px;
        }

        .qr-select-styled {
            position: relative;
            width: 100%;
        }

        .qr-select-element {
            width: 100%;
            height: 42px;
            padding: 0 36px 0 13px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: #ffffff;
            color: var(--ink-dark);
            font-family: inherit;
            font-size: 0.86rem;
            font-weight: 700;
            outline: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
            appearance: none;
            -webkit-appearance: none;
        }

        .qr-select-element:focus {
            box-shadow: 0 0 0 3px rgba(139, 232, 203, 0.4);
        }

        .qr-select-styled .qr-select-caret {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            pointer-events: none;
            font-size: 0.82rem;
        }

        .qr-frame {
            background: #ffffff;
            padding: 18px;
            border-radius: 16px;
            width: 248px;
            height: 248px;
            margin: 16px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
        }

        .qr-subject-meta {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 12px;
            padding: 11px 14px;
            text-align: left;
            margin-bottom: 14px;
        }

        .qr-subject-meta small {
            display: block;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #8be8cb;
            font-weight: 700;
        }

        .qr-subject-meta strong {
            display: block;
            font-size: 0.92rem;
            color: #ffffff;
            margin-top: 2px;
        }

        .qr-token-copy {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            background: rgba(0, 0, 0, 0.22);
            padding: 8px 11px;
            border-radius: 10px;
            font-size: 0.74rem;
            font-family: monospace;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 14px;
            overflow: hidden;
        }

        .qr-actions-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .btn-qr-light {
            padding: 9px 12px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: background 0.15s ease;
        }

        .btn-qr-light:hover {
            background: rgba(255, 255, 255, 0.24);
        }

        /* Student Roster & Attendance Table */
        .roster-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 240px;
        }

        .search-box i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-muted);
            font-size: 0.85rem;
        }

        .search-box input {
            width: 100%;
            height: 38px;
            padding: 0 12px 0 36px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            font-size: 0.84rem;
            background: #f9fcfa;
        }

        .status-filter-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .filter-pill {
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--card-border);
            background: #f4f9f7;
            color: var(--ink-main);
            font-size: 0.76rem;
            font-weight: 700;
            cursor: pointer;
        }

        .filter-pill.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        .clean-table {
            width: 100%;
            border-collapse: collapse;
        }

        .clean-table thead th {
            text-align: left;
            padding: 12px 14px;
            font-size: 0.73rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #46665e;
            background: #f4f9f7;
            border-bottom: 1px solid var(--card-border);
        }

        .clean-table tbody tr {
            border-bottom: 1px solid #edf3f0;
            transition: background 0.14s ease;
        }

        .clean-table tbody tr:hover {
            background: #f8fcfa;
        }

        .clean-table td {
            padding: 12px 14px;
            font-size: 0.85rem;
            vertical-align: middle;
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stu-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #0d5b49;
            color: #ffffff;
            font-size: 0.74rem;
            font-weight: 800;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .status-badge.present { background: var(--green-soft); color: var(--green); }
        .status-badge.late { background: var(--amber-soft); color: var(--amber); }
        .status-badge.absent { background: var(--red-soft); color: var(--red); }
        .status-badge.excused { background: #e0f0ff; color: #1d64c8; }
        .status-badge.unscanned { background: #eef3f0; color: #58716b; border: 1px solid #d4e2dc; }

        .quick-status-btns {
            display: inline-flex;
            gap: 4px;
        }

        .q-btn {
            padding: 4px 9px;
            border-radius: 7px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.14s ease;
        }

        .q-btn:hover {
            background: #eef5f2;
        }

        .q-btn.present.active { background: var(--green); color: #fff; border-color: var(--green); }
        .q-btn.late.active { background: var(--amber); color: #fff; border-color: var(--amber); }
        .q-btn.absent.active { background: var(--red); color: #fff; border-color: var(--red); }

        @media (max-width: 1180px) {
            .prof-workspace-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 860px) {
            .layout-shell { grid-template-columns: 1fr; }
            .sidebar { width: 100% !important; min-width: 100% !important; max-width: 100% !important; position: relative; height: auto; }
        }
    </style>
</head>
<body>

<div class="layout-shell">
    <!-- LEFT SIDEBAR NAVIGATION (Clean, concise, without QR Scanner) -->
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
            <a href="dashboard.php" class="nav-link active">
                <i class="fa-solid fa-qrcode"></i>
                <span>Dashboard &amp; QR Code</span>
            </a>
            <a href="manual_attendance.php?subject_id=<?= $selected_subject_id ?>&date=<?= urlencode($selected_date) ?>" class="nav-link">
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
        </nav>

        <div class="sidebar-footer">
            <div class="prof-card">
                <small>Teacher Account</small>
                <strong><?= htmlspecialchars($profDisplayName) ?></strong>
                <div style="font-size:0.76rem;color:rgba(255,255,255,0.72);margin-top:3px;">ID: <?= htmlspecialchars($profUser['identifier']) ?></div>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="main-panel">
        <header class="top-header">
            <div class="top-header-title">
                <i class="fa-solid fa-gauge-high" style="color:var(--primary);"></i>
                <span>Teacher Dashboard</span>
            </div>
            <div class="top-header-actions">
                <a href="../logout.php" class="btn-logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
                <div class="profile-pill">
                    <div class="avatar-circle"><?= htmlspecialchars($profInitials) ?></div>
                    <div class="profile-meta">
                        <strong><?= htmlspecialchars($profDisplayName) ?></strong>
                        <small>Teacher / Faculty</small>
                    </div>
                </div>
            </div>
        </header>

        <main class="content-container">
            <!-- Top Hero Banner (Unified Design) -->
            <div class="hero-banner">
                <div>
                    <h1>Kumusta, <?= htmlspecialchars($profDisplayName) ?>!</h1>
                    <p>Ipakita ang iyong Attendance QR Code sa klase upang ma-scan ng mga estudyante at awtomatikong maitala ang attendance.</p>
                    <div class="hero-badges">
                        <span class="hero-badge"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($profUser['identifier']) ?></span>
                        <span class="hero-badge"><i class="fa-solid fa-chalkboard-user"></i> Faculty Teacher</span>
                        <span class="hero-badge"><i class="fa-solid fa-calendar-day"></i> <?= date('M d, Y', strtotime($selected_date)) ?></span>
                    </div>
                </div>

                <form method="GET" action="dashboard.php" class="filter-controls" id="profFilterForm">
                    <select name="subject_id" id="subjectSelect" class="select-input" onchange="this.form.submit()">
                        <option value="0" <?= $selected_subject_id === 0 ? 'selected' : '' ?>>All My Subjects (<?= count($subjects) ?>)</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $selected_subject_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['subject_code']) ?> — <?= htmlspecialchars($s['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date" id="dateSelect" class="date-input" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()">
                </form>
            </div>

            <!-- 4 Summary Metrics -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiTotalStudents"><?= $totalAssigned ?></div>
                        <div class="kpi-label">Assigned Students</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon green"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiPresentCount"><?= $presentCount ?></div>
                        <div class="kpi-label">Present Today</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon amber"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiLateCount"><?= $lateCount ?></div>
                        <div class="kpi-label">Late Today</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon red"><i class="fa-solid fa-user-xmark"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiAbsentCount"><?= $absentCount ?></div>
                        <div class="kpi-label">Absent Today</div>
                    </div>
                </div>
            </div>

            <!-- Main Workspace Grid: Left QR Generator + Right Student Attendance Roster -->
            <div class="prof-workspace-grid">
                <!-- LEFT: PROFESSOR QR CODE GENERATOR -->
                <section class="qr-generator-card">
                    <h2><i class="fa-solid fa-qrcode"></i> Teacher QR Code Generator</h2>
                    <p>Piliin ang iyong subject at section sa ibaba upang i-generate ang attendance QR code para sa klase.</p>

                    <!-- Dedicated Dropdown for handled subjects & sections -->
                    <div class="qr-dropdown-wrap">
                        <label for="qrSubjectSectionSelect" class="qr-dropdown-label">
                            <i class="fa-solid fa-layer-group"></i> Pumili ng Hawak na Subject at Section:
                        </label>
                        <div class="qr-select-styled">
                            <select id="qrSubjectSectionSelect" class="qr-select-element" onchange="onQrSubjectSectionChange(this.value)">
                                <?php foreach ($handledSubjectsWithSections as $subId => $item): 
                                    $sub = $item['subject'];
                                    $gradeStr = !empty($sub['grade_name']) ? ' (' . htmlspecialchars($sub['grade_name']) . ')' : '';
                                ?>
                                    <optgroup label="<?= htmlspecialchars($sub['subject_code'] . ' — ' . $sub['subject_name'] . $gradeStr) ?>">
                                        <?php if (count($item['sections']) > 1): ?>
                                            <option value="<?= $subId ?>|ALL" <?= ($selected_subject_id === $subId && ($selected_section === 'ALL' || $selected_section === '')) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sub['subject_code']) ?> — All Sections
                                            </option>
                                        <?php endif; ?>
                                        <?php foreach ($item['sections'] as $sec): ?>
                                            <option value="<?= $subId ?>|<?= htmlspecialchars($sec) ?>" <?= ($selected_subject_id === $subId && $selected_section === $sec) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sub['subject_code']) ?> — <?= htmlspecialchars($sec) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <i class="fa-solid fa-chevron-down qr-select-caret"></i>
                        </div>
                    </div>

                    <div class="qr-frame">
                        <div id="professorQrCodeCanvas" aria-label="Professor Attendance QR Code"></div>
                    </div>

                    <div class="qr-subject-meta">
                        <small><i class="fa-solid fa-chalkboard"></i> Active Class &amp; Section</small>
                        <strong id="qrActiveSubjectTitle">
                            <?= $activeSubject ? htmlspecialchars($activeSubject['subject_code'] . ' — ' . $activeSubject['subject_name']) : 'All Assigned Subjects' ?>
                        </strong>
                        <div id="qrActiveSectionBadge" style="margin-top:5px; display:inline-block; background:rgba(255,255,255,0.18); border:1px solid rgba(255,255,255,0.25); border-radius:6px; padding:3px 9px; font-size:0.78rem; font-weight:700; color:#e0fff3;">
                            <i class="fa-solid fa-users-rectangle"></i> <span id="qrActiveSectionText"><?= ($selected_section && $selected_section !== 'ALL') ? htmlspecialchars($selected_section) : 'All Sections' ?></span>
                            <?= !empty($activeSubject['grade_name']) ? ' (' . htmlspecialchars($activeSubject['grade_name']) . ')' : '' ?>
                        </div>
                        <div style="font-size:0.75rem;color:rgba(255,255,255,0.78);margin-top:5px;">
                            Teacher: <?= htmlspecialchars($profDisplayName) ?> • Date: <?= date('M d, Y', strtotime($selected_date)) ?>
                        </div>
                    </div>

                    <div class="qr-token-copy" title="Teacher Attendance Token (Students can also enter this code manually)">
                        <span id="qrPayloadText" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($qrPayloadString) ?></span>
                        <button type="button" onclick="copyQrToken()" style="background:none;border:none;color:#8be8cb;cursor:pointer;font-weight:700;font-size:0.75rem;flex-shrink:0;">
                            <i class="fa-regular fa-copy"></i> Copy Code
                        </button>
                    </div>

                    <div class="qr-actions-row">
                        <button type="button" class="btn-qr-light" onclick="regenerateSessionQr()">
                            <i class="fa-solid fa-arrows-rotate"></i> Refresh QR
                        </button>
                        <button type="button" class="btn-qr-light" onclick="downloadProfessorQr()">
                            <i class="fa-solid fa-download"></i> Download QR
                        </button>
                    </div>
                </section>

                <!-- RIGHT: LIVE ATTENDANCE RECORDS (STUDENTS WHO SCANNED) -->
                <section class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-clipboard-user"></i>
                            <span>Live Attendance Records</span>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span style="font-size:0.76rem;color:var(--green);font-weight:700;">
                                <i class="fa-solid fa-circle" style="font-size:0.5rem;vertical-align:middle;"></i> Auto-Updating Live
                            </span>
                            <a href="manual_attendance.php?subject_id=<?= $selected_subject_id ?>&date=<?= urlencode($selected_date) ?>" class="q-btn" style="padding:6px 12px;">
                                <i class="fa-solid fa-sliders"></i> Full Attendance Sheet
                            </a>
                        </div>
                    </div>

                    <div class="roster-toolbar">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="studentRosterSearch" placeholder="Maghanap ng pangalan ng estudyante, Student ID, o section..." oninput="filterStudentRoster()">
                        </div>

                        <div class="status-filter-pills">
                            <button type="button" class="filter-pill active" onclick="setStatusFilter('scanned', this)">
                                Scanned Records (<span id="pillScannedCount"><?= $scannedCount ?></span>)
                            </button>
                            <button type="button" class="filter-pill" onclick="setStatusFilter('unscanned', this)">
                                Not Yet Scanned (<span id="pillUnscannedCount"><?= $unscannedCount ?></span>)
                            </button>
                            <button type="button" class="filter-pill" onclick="setStatusFilter('all', this)">
                                All Enrolled (<?= $totalAssigned ?>)
                            </button>
                            <button type="button" class="filter-pill" onclick="setStatusFilter('Present', this)">
                                Present (<span id="pillPresentCount"><?= $presentCount ?></span>)
                            </button>
                            <button type="button" class="filter-pill" onclick="setStatusFilter('Late', this)">
                                Late (<span id="pillLateCount"><?= $lateCount ?></span>)
                            </button>
                            <button type="button" class="filter-pill" onclick="setStatusFilter('Absent', this)">
                                Absent (<span id="pillAbsentCount"><?= $absentCount ?></span>)
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="clean-table" id="profStudentsTable">
                            <thead>
                                <tr>
                                    <th>Student Name &amp; ID</th>
                                    <th>Grade &amp; Section</th>
                                    <th>Subject</th>
                                    <th>Time Scanned</th>
                                    <th>Attendance Status</th>
                                    <th>Quick Mark</th>
                                </tr>
                            </thead>
                            <tbody id="profStudentsTbody">
                                <?php if (empty($assignedStudents)): ?>
                                    <tr>
                                        <td colspan="6" style="padding:28px;text-align:center;color:var(--ink-muted);">
                                            Wala pang estudyanteng naka-assign sa subject na ito.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($assignedStudents as $stu):
                                        $sId = (int)$stu['student_id'];
                                        $subId = (int)$stu['subject_id'];
                                        $isScanned = !empty($stu['is_scanned']);
                                        $stStatus = $isScanned ? $stu['attendance_status'] : 'Pending';
                                        $stClass = strtolower($stStatus);
                                        $sParts = preg_split('/\s+/', trim($stu['name']));
                                        $sInit = count($sParts) >= 2
                                            ? strtoupper(substr($sParts[0], 0, 1) . substr($sParts[1], 0, 1))
                                            : strtoupper(substr($stu['name'], 0, 2));
                                    ?>
                                        <tr class="prof-student-row"
                                            id="prof-stu-row-<?= $sId ?>-<?= $subId ?>"
                                            data-search="<?= strtolower(htmlspecialchars($stu['name'] . ' ' . $stu['identifier'] . ' ' . ($stu['course_section'] ?? '') . ' ' . $stu['subject_code'])) ?>"
                                            data-status="<?= htmlspecialchars($stStatus) ?>"
                                            data-scanned="<?= $isScanned ? '1' : '0' ?>"
                                            style="<?= !$isScanned ? 'display:none;' : '' ?>">
                                            <td>
                                                <div class="student-cell">
                                                    <div class="stu-avatar"><?= htmlspecialchars($sInit) ?></div>
                                                    <div>
                                                        <strong style="display:block;color:var(--ink-dark);"><?= htmlspecialchars($stu['name']) ?></strong>
                                                        <small style="color:var(--ink-muted);"><?= htmlspecialchars($stu['identifier']) ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($stu['grade_level'] ?: 'Grade 8') ?></strong>
                                                <div style="font-size:0.75rem;color:var(--ink-muted);"><?= htmlspecialchars($stu['course_section'] ?: 'Section A') ?></div>
                                            </td>
                                            <td>
                                                <span style="font-weight:700;color:var(--primary);"><?= htmlspecialchars($stu['subject_code']) ?></span>
                                                <div style="font-size:0.75rem;color:var(--ink-muted);"><?= htmlspecialchars($stu['subject_name']) ?></div>
                                            </td>
                                            <td class="cell-time">
                                                <?= ($isScanned && !empty($stu['time_in'])) ? date('h:i A', strtotime($stu['time_in'])) : '—' ?>
                                            </td>
                                            <td class="cell-status">
                                                <?php if ($isScanned): ?>
                                                    <span class="status-badge <?= $stClass ?>">
                                                        <i class="fa-solid <?= $stClass === 'present' ? 'fa-check' : ($stClass === 'late' ? 'fa-clock' : 'fa-xmark') ?>"></i>
                                                        <span class="status-label"><?= htmlspecialchars($stStatus) ?></span>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge unscanned">
                                                        <i class="fa-regular fa-clock"></i>
                                                        <span class="status-label">Hindi Pa Nag-scan</span>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="quick-status-btns">
                                                    <button type="button" class="q-btn present <?= ($isScanned && $stStatus === 'Present') ? 'active' : '' ?>"
                                                            onclick="quickMarkStudent(<?= $sId ?>, <?= $subId ?>, 'Present', this)" title="Mark Present">P</button>
                                                    <button type="button" class="q-btn late <?= ($isScanned && $stStatus === 'Late') ? 'active' : '' ?>"
                                                            onclick="quickMarkStudent(<?= $sId ?>, <?= $subId ?>, 'Late', this)" title="Mark Late">L</button>
                                                    <button type="button" class="q-btn absent <?= ($isScanned && $stStatus === 'Absent') ? 'active' : '' ?>"
                                                            onclick="quickMarkStudent(<?= $sId ?>, <?= $subId ?>, 'Absent', this)" title="Mark Absent">A</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="tableFilterEmptyState" style="<?= $scannedCount === 0 ? '' : 'display:none;' ?>text-align:center;padding:36px 16px;">
                        <div style="width:54px;height:54px;border-radius:14px;background:#eef6f3;color:var(--primary);font-size:1.4rem;display:grid;place-items:center;margin:0 auto 12px;">
                            <i class="fa-solid fa-qrcode"></i>
                        </div>
                        <strong style="display:block;font-size:0.98rem;color:var(--ink-dark);" id="emptyFilterTitle">Wala pang attendance record ngayong araw</strong>
                        <p style="font-size:0.83rem;color:var(--ink-muted);max-width:440px;margin:6px auto 0;line-height:1.5;" id="emptyFilterMsg">
                            Ipa-scan sa mga estudyante ang Teacher QR Code sa kaliwa. Kapag nag-scan ang estudyante gamit ang kanilang Student Portal, awtomatikong lalabas dito ang kanilang attendance record.
                        </p>
                    </div>
                </section>
            </div>
        </main>
    </div>
</div>

<script>
    let currentSubjectId = <?= (int)$selected_subject_id ?>;
    let currentSection = <?= json_encode($selected_section) ?>;
    const selectedDate = <?= json_encode($selected_date) ?>;
    const profQrToken = <?= json_encode($profQrToken) ?>;
    const subjectsMeta = <?= json_encode(array_column($subjects, null, 'id')) ?>;
    let currentQrPayload = <?= json_encode($qrPayloadString) ?>;
    let activeStatusFilter = 'scanned';

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderProfessorQr(payloadText) {
        const container = document.getElementById('professorQrCodeCanvas');
        if (!container) return;
        container.innerHTML = '';
        if (window.QRCode) {
            new QRCode(container, {
                text: payloadText,
                width: 212,
                height: 212,
                colorDark: "#07362b",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            container.textContent = payloadText;
        }
        const txt = document.getElementById('qrPayloadText');
        if (txt) txt.textContent = payloadText;
    }

    function onQrSubjectSectionChange(val) {
        if (!val) return;
        const parts = val.split('|');
        const newSubId = parseInt(parts[0], 10);
        const newSec = parts[1] || 'ALL';

        currentSubjectId = newSubId;
        currentSection = newSec;

        // Build new QR payload
        const sectionSuffix = (newSec && newSec !== 'ALL') ? `|${newSec}` : '';
        currentQrPayload = `PROF_ATTENDANCE|${profQrToken}|${newSubId}|${selectedDate}${sectionSuffix}`;

        // Redraw QR code immediately
        renderProfessorQr(currentQrPayload);

        // Update active class & section label in the QR card
        const subInfo = subjectsMeta[newSubId];
        const titleEl = document.getElementById('qrActiveSubjectTitle');
        const secTextEl = document.getElementById('qrActiveSectionText');

        if (subInfo && titleEl) {
            titleEl.textContent = `${subInfo.subject_code} — ${subInfo.subject_name}`;
        }
        if (secTextEl) {
            secTextEl.textContent = newSec === 'ALL' ? 'All Sections' : newSec;
        }

        // Keep URL in sync
        const url = new URL(window.location.href);
        url.searchParams.set('subject_id', newSubId);
        if (newSec && newSec !== 'ALL') {
            url.searchParams.set('section', newSec);
        } else {
            url.searchParams.delete('section');
        }
        window.history.replaceState({}, '', url.toString());

        // Keep Hero filter select in sync
        const heroSelect = document.getElementById('subjectSelect');
        if (heroSelect) {
            heroSelect.value = newSubId;
        }

        // Fetch and refresh roster immediately for the selected subject and section
        pollLiveStudentAttendance();
    }

    function regenerateSessionQr() {
        const sectionSuffix = (currentSection && currentSection !== 'ALL') ? `|${currentSection}` : '';
        currentQrPayload = `PROF_ATTENDANCE|${profQrToken}|${currentSubjectId}|${selectedDate}${sectionSuffix}`;
        renderProfessorQr(currentQrPayload);
    }

    function copyQrToken() {
        navigator.clipboard?.writeText(currentQrPayload);
        alert('Nakopya na ang Attendance QR Code token: ' + currentQrPayload);
    }

    function downloadProfessorQr() {
        const img = document.querySelector('#professorQrCodeCanvas img') || document.querySelector('#professorQrCodeCanvas canvas');
        if (!img) return;
        const subInfo = subjectsMeta[currentSubjectId];
        const subCode = subInfo ? subInfo.subject_code : 'Subject';
        const secTag = (currentSection && currentSection !== 'ALL') ? `_${currentSection.replace(/[^a-zA-Z0-9]/g, '_')}` : '';

        const link = document.createElement('a');
        link.download = `QR_${subCode}${secTag}_${selectedDate}.png`;
        link.href = img.src || img.toDataURL('image/png');
        link.click();
    }

    function filterStudentRoster() {
        const q = (document.getElementById('studentRosterSearch')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.prof-student-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const searchStr = row.getAttribute('data-search') || '';
            const rowSt = row.getAttribute('data-status') || '';
            const isScanned = row.getAttribute('data-scanned') === '1';

            const matchesSearch = !q || searchStr.includes(q);
            let matchesFilter = false;

            if (activeStatusFilter === 'scanned') {
                matchesFilter = isScanned;
            } else if (activeStatusFilter === 'unscanned') {
                matchesFilter = !isScanned;
            } else if (activeStatusFilter === 'all') {
                matchesFilter = true;
            } else {
                matchesFilter = isScanned && (rowSt === activeStatusFilter);
            }

            if (matchesSearch && matchesFilter) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        const emptyBox = document.getElementById('tableFilterEmptyState');
        const emptyTitle = document.getElementById('emptyFilterTitle');
        const emptyMsg = document.getElementById('emptyFilterMsg');

        if (emptyBox) {
            if (visibleCount === 0) {
                emptyBox.style.display = '';
                if (activeStatusFilter === 'scanned') {
                    if (emptyTitle) emptyTitle.textContent = 'Wala pang attendance record ngayong araw';
                    if (emptyMsg) emptyMsg.textContent = 'Ipa-scan sa mga estudyante ang Teacher QR Code sa kaliwa. Kapag nag-scan ang estudyante gamit ang kanilang Student Portal, awtomatikong lalabas dito ang kanilang attendance record.';
                } else if (activeStatusFilter === 'unscanned') {
                    if (emptyTitle) emptyTitle.textContent = 'Lahat ng estudyante ay nakapag-scan na!';
                    if (emptyMsg) emptyMsg.textContent = 'Wala nang estudyante na pending ang scan para sa klase na ito.';
                } else {
                    if (emptyTitle) emptyTitle.textContent = 'Walang nakitang resulta';
                    if (emptyMsg) emptyMsg.textContent = 'Walang estudyante na tumugma sa iyong paghahanap o filter.';
                }
            } else {
                emptyBox.style.display = 'none';
            }
        }
    }

    function setStatusFilter(status, btn) {
        activeStatusFilter = status;
        document.querySelectorAll('.status-filter-pills .filter-pill').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        filterStudentRoster();
    }

    function quickMarkStudent(studentId, subjectId, status, btnEl) {
        fetch('../api/manual_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'set_status',
                subject_id: subjectId,
                student_id: studentId,
                date: selectedDate,
                status: status
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                pollLiveStudentAttendance();
            }
        });
    }

    function renderStudentRosterTable(students) {
        const tbody = document.getElementById('profStudentsTbody');
        if (!tbody) return;

        if (!Array.isArray(students) || students.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="padding:28px;text-align:center;color:var(--ink-muted);">
                        <i class="fa-solid fa-user-slash" style="font-size:1.5rem;opacity:0.4;display:block;margin-bottom:8px;"></i>
                        Wala pang estudyanteng naka-assign sa napiling subject o section.
                    </td>
                </tr>
            `;
            filterStudentRoster();
            return;
        }

        let html = '';
        students.forEach(stu => {
            const sId = parseInt(stu.student_id, 10);
            const subId = parseInt(stu.subject_id, 10);
            const isScanned = parseInt(stu.is_scanned, 10) === 1;
            const stStatus = isScanned ? (stu.attendance_status || 'Present') : 'Pending';
            const stLower = stStatus.toLowerCase();
            const parts = (stu.name || '').trim().split(/\s+/);
            const sInit = parts.length >= 2 
                ? (parts[0][0] + parts[1][0]).toUpperCase()
                : (stu.name || 'ST').substring(0, 2).toUpperCase();

            const searchPayload = `${stu.name} ${stu.identifier} ${stu.course_section || ''} ${stu.subject_code}`.toLowerCase();
            const icon = stLower === 'present' ? 'fa-check' : (stLower === 'late' ? 'fa-clock' : 'fa-xmark');

            html += `
                <tr class="prof-student-row"
                    id="prof-stu-row-${sId}-${subId}"
                    data-search="${escapeHtml(searchPayload)}"
                    data-status="${escapeHtml(stStatus)}"
                    data-scanned="${isScanned ? '1' : '0'}">
                    <td>
                        <div class="student-cell">
                            <div class="stu-avatar">${escapeHtml(sInit)}</div>
                            <div>
                                <strong style="display:block;color:var(--ink-dark);">${escapeHtml(stu.name)}</strong>
                                <small style="color:var(--ink-muted);">${escapeHtml(stu.identifier)}</small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <strong>${escapeHtml(stu.grade_level || 'Grade 8')}</strong>
                        <div style="font-size:0.75rem;color:var(--ink-muted);">${escapeHtml(stu.course_section || 'Section A')}</div>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--primary);">${escapeHtml(stu.subject_code)}</span>
                        <div style="font-size:0.75rem;color:var(--ink-muted);">${escapeHtml(stu.subject_name)}</div>
                    </td>
                    <td class="cell-time">
                        ${isScanned ? escapeHtml(stu.formatted_time || '—') : '—'}
                    </td>
                    <td class="cell-status">
                        ${isScanned ? `
                            <span class="status-badge ${stLower}">
                                <i class="fa-solid ${icon}"></i>
                                <span class="status-label">${escapeHtml(stStatus)}</span>
                            </span>
                        ` : `
                            <span class="status-badge unscanned">
                                <i class="fa-regular fa-clock"></i>
                                <span class="status-label">Hindi Pa Nag-scan</span>
                            </span>
                        `}
                    </td>
                    <td>
                        <div class="quick-status-btns">
                            <button type="button" class="q-btn present ${(isScanned && stStatus === 'Present') ? 'active' : ''}"
                                    onclick="quickMarkStudent(${sId}, ${subId}, 'Present', this)" title="Mark Present">P</button>
                            <button type="button" class="q-btn late ${(isScanned && stStatus === 'Late') ? 'active' : ''}"
                                    onclick="quickMarkStudent(${sId}, ${subId}, 'Late', this)" title="Mark Late">L</button>
                            <button type="button" class="q-btn absent ${(isScanned && stStatus === 'Absent') ? 'active' : ''}"
                                    onclick="quickMarkStudent(${sId}, ${subId}, 'Absent', this)" title="Mark Absent">A</button>
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        filterStudentRoster();
    }

    function pollLiveStudentAttendance() {
        const secParam = (currentSection && currentSection !== 'ALL') ? `&section=${encodeURIComponent(currentSection)}` : '';
        fetch(`dashboard.php?ajax_poll=1&subject_id=${currentSubjectId}&date=${encodeURIComponent(selectedDate)}${secParam}`, {
            cache: 'no-store'
        })
        .then(res => res.json())
        .then(data => {
            if (data.status !== 'success') return;
            const totalEl = document.getElementById('kpiTotalStudents');
            const presEl = document.getElementById('kpiPresentCount');
            const lateEl = document.getElementById('kpiLateCount');
            const absEl = document.getElementById('kpiAbsentCount');

            if (totalEl) totalEl.textContent = data.total;
            if (presEl) presEl.textContent = data.present;
            if (lateEl) lateEl.textContent = data.late;
            if (absEl) absEl.textContent = data.absent;

            const pillScn = document.getElementById('pillScannedCount');
            const pillUnscn = document.getElementById('pillUnscannedCount');
            const pillPres = document.getElementById('pillPresentCount');
            const pillLate = document.getElementById('pillLateCount');
            const pillAbs = document.getElementById('pillAbsentCount');

            if (pillScn) pillScn.textContent = data.scanned;
            if (pillUnscn) pillUnscn.textContent = data.unscanned;
            if (pillPres) pillPres.textContent = data.present;
            if (pillLate) pillLate.textContent = data.late;
            if (pillAbs) pillAbs.textContent = data.absent;

            renderStudentRosterTable(data.students);
        })
        .catch(() => {});
    }

    window.addEventListener('DOMContentLoaded', () => {
        renderProfessorQr(currentQrPayload);
        filterStudentRoster();
        setInterval(pollLiveStudentAttendance, 5000);
    });
</script>

</body>
</html>
