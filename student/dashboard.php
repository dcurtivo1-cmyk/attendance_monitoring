<?php
// student/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['student']);
$user = current_user();
$pdo = get_db_connection();

$activeView = $_GET['view'] ?? 'dashboard';
if (!in_array($activeView, ['dashboard', 'scanner', 'subjects', 'teachers', 'attendance'], true)) {
    $activeView = 'dashboard';
}

$viewTitles = [
    'dashboard'  => 'Student Dashboard',
    'scanner'    => 'QR Attendance Scanner',
    'subjects'   => 'My Assigned Subjects',
    'teachers'   => 'My Teachers Directory',
    'attendance' => 'My Attendance Records'
];

// Refresh student info from DB
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$student = $stmt->fetch();

$studentGrade = !empty($student['grade_level']) ? $student['grade_level'] : 'Grade 8';
$rawSection = trim($student['course_section'] ?? '');
if ($rawSection === '' || stripos($rawSection, 'bsit') !== false || stripos($rawSection, 'it-') === 0) {
    $highSchoolSections = [
        'Grade 7'  => 'Section A - Rizal',
        'Grade 8'  => 'Section A - Bonifacio',
        'Grade 9'  => 'Section A - Mabini',
        'Grade 10' => 'Section A - Luna',
    ];
    $studentSection = $highSchoolSections[$studentGrade] ?? 'Section A - Bonifacio';
} else {
    $studentSection = $rawSection;
}

// Ensure student has assigned high school subjects for their grade level
$checkEnrolled = $pdo->prepare("
    SELECT COUNT(*)
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    WHERE ss.student_id = ? AND s.grade_level_id IS NOT NULL
");
$checkEnrolled->execute([$user['id']]);
if ((int)$checkEnrolled->fetchColumn() === 0) {
    $pdo->prepare("
        INSERT IGNORE INTO student_subjects (student_id, subject_id)
        SELECT ?, s.id
        FROM subjects s
        JOIN grade_levels gl ON gl.id = s.grade_level_id
        WHERE gl.grade_name = ?
    ")->execute([$user['id'], $studentGrade]);
}

function format_teacher_title(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '' || strcasecmp($trimmed, 'Assigned Professor') === 0 || strcasecmp($trimmed, 'Professor') === 0) {
        return 'Teacher Juan Dela Cruz';
    }
    $trimmed = preg_replace('/^Prof\.?\s+/i', 'Teacher ', $trimmed);
    return $trimmed;
}

// Fetch all subjects assigned to this student along with the full name of the teacher
$subjectsStmt = $pdo->prepare("
    SELECT s.id AS subject_id,
           s.subject_code,
           s.subject_name,
           gl.grade_name,
           COALESCE(t.full_name, p.name, 'Teacher Juan Dela Cruz') AS teacher_full_name,
           COALESCE(t.teacher_id, p.identifier, 'TCH-2026-001') AS teacher_identifier,
           COALESCE(t.email, p.email, '') AS teacher_email,
           COALESCE(t.contact_number, '') AS teacher_contact,
           COALESCE(t.department_code, 'FACULTY') AS department_code,
           sc.day_of_week,
           sc.start_time,
           sc.end_time
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
    LEFT JOIN users p ON p.id = s.professor_id
    LEFT JOIN teachers t ON (t.subject_id = s.id OR t.user_id = s.professor_id OR (t.grade_level_id = s.grade_level_id AND t.subject_name = s.subject_name))
    LEFT JOIN schedules sc ON sc.subject_id = s.id
    WHERE ss.student_id = ? AND s.grade_level_id IS NOT NULL
    GROUP BY s.id
    ORDER BY s.subject_code ASC
");
$subjectsStmt->execute([$user['id']]);
$enrolledSubjects = $subjectsStmt->fetchAll();

$defaultTimeSlots = [
    ['07:30:00', '08:30:00'],
    ['08:30:00', '09:30:00'],
    ['09:45:00', '10:45:00'],
    ['10:45:00', '11:45:00'],
    ['13:00:00', '14:00:00'],
    ['14:00:00', '15:00:00'],
    ['15:00:00', '16:00:00'],
    ['16:00:00', '17:00:00'],
];
$defaultWeekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

foreach ($enrolledSubjects as $idx => &$subRow) {
    $subRow['teacher_full_name'] = format_teacher_title((string)$subRow['teacher_full_name']);
    $subRow['teacher_identifier'] = preg_replace('/^PROF-/i', 'TCH-', (string)$subRow['teacher_identifier']);
    $subRow['teacher_email'] = preg_replace('/^prof\./i', 'teacher.', (string)$subRow['teacher_email']);

    $slot = $defaultTimeSlots[$idx % count($defaultTimeSlots)];
    $startTime = !empty($subRow['start_time']) ? $subRow['start_time'] : $slot[0];
    $endTime   = !empty($subRow['end_time']) ? $subRow['end_time'] : $slot[1];

    $rawDay = trim((string)($subRow['day_of_week'] ?? ''));
    if ($rawDay !== '' && preg_match('/\d{4}/', $rawDay)) {
        $dayOfWeek = date('l', strtotime($rawDay));
    } elseif ($rawDay !== '' && in_array(ucfirst(strtolower($rawDay)), ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])) {
        $dayOfWeek = ucfirst(strtolower($rawDay));
    } else {
        $dayOfWeek = $defaultWeekDays[$idx % count($defaultWeekDays)];
    }
    $subRow['schedule_day'] = $dayOfWeek;
    $subRow['schedule_date'] = $dayOfWeek;
    $subRow['schedule_time'] = date('h:i A', strtotime($startTime)) . ' - ' . date('h:i A', strtotime($endTime));
    $subRow['schedule_datetime'] = $dayOfWeek . ' • ' . $subRow['schedule_time'];
}
unset($subRow);

// Build unique list of all teachers teaching this student
$myTeachersMap = [];
foreach ($enrolledSubjects as $sub) {
    $tKey = strtolower(trim($sub['teacher_full_name']));
    if (!isset($myTeachersMap[$tKey])) {
        $parts = preg_split('/\s+/', trim(str_replace(['Teacher', 'Mr.', 'Ms.', 'Mrs.', 'Prof.', "Ma'am", 'Sir'], '', $sub['teacher_full_name'])));
        $parts = array_values(array_filter($parts));
        $initials = count($parts) >= 2
            ? strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1))
            : strtoupper(substr($parts[0] ?? 'TC', 0, 2));

        $myTeachersMap[$tKey] = [
            'full_name'       => $sub['teacher_full_name'],
            'identifier'      => $sub['teacher_identifier'],
            'email'           => $sub['teacher_email'],
            'contact'         => $sub['teacher_contact'],
            'department_code' => $sub['department_code'],
            'initials'        => $initials,
            'subjects'        => []
        ];
    }
    $myTeachersMap[$tKey]['subjects'][] = [
        'code'     => $sub['subject_code'],
        'name'     => $sub['subject_name'],
        'schedule' => $sub['schedule_datetime']
    ];
}
$myTeachers = array_values($myTeachersMap);

// Attendance statistics
$statStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_attendance,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent_count
    FROM attendance
    WHERE student_id = ?
");
$statStmt->execute([$user['id']]);
$stats = $statStmt->fetch();

// Recent Attendance Logs
$logsStmt = $pdo->prepare("
    SELECT a.*,
           s.subject_code,
           s.subject_name,
           COALESCE(t.full_name, p.name, 'Teacher') AS professor_name
    FROM attendance a
    JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN users p ON a.scanned_by = p.id
    LEFT JOIN teachers t ON t.user_id = a.scanned_by OR t.subject_id = s.id
    WHERE a.student_id = ?
    GROUP BY a.id
    ORDER BY a.date DESC, a.time_in DESC
");
$logsStmt->execute([$user['id']]);
$recentLogs = $logsStmt->fetchAll();
foreach ($recentLogs as &$rLog) {
    $rLog['professor_name'] = format_teacher_title((string)$rLog['professor_name']);
}
unset($rLog);

// Student Avatar Initials
$stuNameParts = preg_split('/\s+/', trim($student['name']));
$stuInitials = count($stuNameParts) >= 2
    ? strtoupper(substr($stuNameParts[0], 0, 1) . substr($stuNameParts[1], 0, 1))
    : strtoupper(substr($student['name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($viewTitles[$activeView]) ?> - Student Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- HTML5 QR Code Scanner Library for Student Scanning Professor's QR -->
    <script src="https://unpkg.com/html5-qrcode"></script>
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

        .student-id-card {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 13px;
            padding: 14px;
        }

        .student-id-card small {
            display: block;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: rgba(255, 255, 255, 0.65);
            font-weight: 700;
        }

        .student-id-card strong {
            display: block;
            font-size: 0.96rem;
            font-weight: 800;
            margin-top: 4px;
            color: #ffffff;
        }

        .student-id-card span {
            display: inline-block;
            margin-top: 6px;
            font-size: 0.75rem;
            background: rgba(139, 232, 203, 0.18);
            color: #8be8cb;
            padding: 3px 9px;
            border-radius: 999px;
            font-weight: 700;
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
            padding: 24px 28px 36px;
            max-width: 1480px;
            width: 100%;
            margin: 0 auto;
        }

        /* Hero Banner */
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
        }

        .hero-banner p {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.84);
            margin-top: 4px;
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
        }

        .btn-scan-cta {
            background: #ffffff;
            color: #094336;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            border: none;
            cursor: pointer;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.14);
            transition: transform 0.15s ease;
        }

        .btn-scan-cta:hover {
            transform: translateY(-2px);
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 8px rgba(11, 57, 48, 0.03);
        }

        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .kpi-icon.teal { background: #dff4ec; color: #0d6852; }
        .kpi-icon.blue { background: #e0f0ff; color: #1d64c8; }
        .kpi-icon.green { background: #def7ec; color: #12825c; }
        .kpi-icon.amber { background: #fef0d9; color: #c87412; }

        .kpi-val {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--ink-dark);
            line-height: 1.1;
        }

        .kpi-label {
            font-size: 0.82rem;
            color: var(--ink-muted);
            font-weight: 600;
            margin-top: 3px;
        }

        /* Workspace Grid */
        .workspace-grid {
            display: grid;
            grid-template-columns: 1.1fr 1.4fr;
            gap: 20px;
            margin-bottom: 22px;
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
            gap: 12px;
            padding-bottom: 14px;
            margin-bottom: 16px;
            border-bottom: 1px solid #edf3f0;
            flex-wrap: wrap;
        }

        .card-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--ink-dark);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .card-title i {
            color: var(--primary);
        }

        .pill-count {
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 0.76rem;
            font-weight: 800;
            padding: 4px 11px;
            border-radius: 999px;
        }

        /* QR Scanner Box */
        #studentQrReader {
            width: 100% !important;
            max-width: 440px;
            margin: 0 auto;
            border-radius: 14px;
            overflow: hidden;
            border: 2px dashed #14855e;
            background: #071f1a;
            min-height: 240px;
        }

        .scanner-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 14px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 11px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #f2f7f5;
            color: var(--ink-main);
            border-color: var(--card-border);
        }

        .btn-secondary:hover {
            background: #e4efeb;
        }

        .scan-feedback {
            margin-top: 14px;
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 0.86rem;
            font-weight: 600;
            display: none;
            align-items: center;
            gap: 10px;
        }

        .scan-feedback.success {
            display: flex;
            background: var(--green-soft);
            color: #0c5e40;
            border: 1px solid #b5ebd3;
        }

        .scan-feedback.warning {
            display: flex;
            background: var(--amber-soft);
            color: #965309;
            border: 1px solid #f7d69c;
        }

        .scan-feedback.danger {
            display: flex;
            background: var(--red-soft);
            color: #9b1c1c;
            border: 1px solid #f8b4b4;
        }

        .manual-token-box {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px dashed var(--card-border);
        }

        .manual-token-box label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--ink-muted);
            margin-bottom: 6px;
        }

        .input-row {
            display: flex;
            gap: 8px;
        }

        .form-input {
            flex: 1;
            height: 40px;
            padding: 0 13px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            font-size: 0.85rem;
            color: var(--ink-main);
            background: #f9fcfa;
        }

        /* Subject & Teacher Cards / Tables */
        .clean-table {
            width: 100%;
            border-collapse: collapse;
        }

        .clean-table thead th {
            text-align: left;
            padding: 12px 14px;
            font-size: 0.74rem;
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

        .clean-table tbody tr:last-child {
            border-bottom: none;
        }

        .clean-table tbody tr:hover {
            background: #f8fcfa;
        }

        .clean-table td {
            padding: 13px 14px;
            font-size: 0.86rem;
            vertical-align: middle;
        }

        .subject-code-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 8px;
            background: #e3f2ed;
            color: #0c5846;
            font-weight: 800;
            font-size: 0.78rem;
        }

        .teacher-chip {
            display: inline-flex;
            align-items: center;
            gap: 9px;
        }

        .teacher-chip-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #0d5b49;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }

        /* Teacher Directory Grid */
        .teachers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 16px;
        }

        .teacher-card {
            background: #f9fcfa;
            border: 1px solid var(--card-border);
            border-radius: 15px;
            padding: 16px 18px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .teacher-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(11, 57, 48, 0.06);
            background: #ffffff;
        }

        .teacher-card-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .teacher-avatar-lg {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0d5b49, #158568);
            color: #ffffff;
            font-weight: 800;
            font-size: 0.92rem;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }

        .teacher-card-name {
            font-size: 0.96rem;
            font-weight: 800;
            color: var(--ink-dark);
        }

        .teacher-card-id {
            font-size: 0.75rem;
            color: var(--ink-muted);
            font-weight: 600;
        }

        .teacher-subject-list {
            list-style: none;
            border-top: 1px solid #e6efec;
            padding-top: 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .teacher-subject-list li {
            font-size: 0.8rem;
            color: var(--ink-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
        }

        .status-badge.present { background: var(--green-soft); color: var(--green); }
        .status-badge.late { background: var(--amber-soft); color: var(--amber); }
        .status-badge.absent { background: var(--red-soft); color: var(--red); }

        @media (max-width: 1120px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .workspace-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 860px) {
            .layout-shell { grid-template-columns: 1fr; }
            .sidebar { width: 100% !important; min-width: 100% !important; max-width: 100% !important; position: relative; height: auto; }
        }
    </style>
</head>
<body>

<div class="layout-shell">
    <!-- LEFT SIDEBAR -->
    <aside class="sidebar">
        <div class="brand-box">
            <div class="brand-icon" style="background:#ffffff;padding:2px;"><img src="../assets/images/logo.png" alt="School Logo" style="width:100%;height:100%;object-fit:contain;border-radius:50%;"></div>
            <div>
                <div class="brand-title">ODB Montessori School of Novaliches</div>
                <span class="brand-sub">Student Portal</span>
            </div>
        </div>

        <div class="nav-section-title">Menu</div>
        <nav class="sidebar-nav">
            <a href="dashboard.php?view=dashboard" class="nav-link <?= $activeView === 'dashboard' ? 'active' : '' ?>">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
            <a href="dashboard.php?view=scanner" class="nav-link <?= $activeView === 'scanner' ? 'active' : '' ?>">
                <i class="fa-solid fa-camera"></i>
                <span>QR Scanner</span>
            </a>
            <a href="dashboard.php?view=subjects" class="nav-link <?= $activeView === 'subjects' ? 'active' : '' ?>">
                <i class="fa-solid fa-book-open"></i>
                <span>My Subjects</span>
            </a>
            <a href="dashboard.php?view=teachers" class="nav-link <?= $activeView === 'teachers' ? 'active' : '' ?>">
                <i class="fa-solid fa-chalkboard-user"></i>
                <span>My Teachers</span>
            </a>
            <a href="dashboard.php?view=attendance" class="nav-link <?= $activeView === 'attendance' ? 'active' : '' ?>">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>Attendance Records</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="student-id-card">
                <small>Student Account</small>
                <strong><?= htmlspecialchars($student['name']) ?></strong>
                <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);margin-top:2px;">ID: <?= htmlspecialchars($student['identifier']) ?></div>
                <span><?= htmlspecialchars($studentGrade) ?> • <?= htmlspecialchars($studentSection) ?></span>
            </div>
        </div>
    </aside>

    <!-- MAIN PANEL -->
    <div class="main-panel">
        <header class="top-header">
            <div class="top-header-title">
                <i class="fa-solid fa-layer-group" style="color:var(--primary);"></i>
                <span><?= htmlspecialchars($viewTitles[$activeView]) ?></span>
            </div>
            <div class="top-header-actions">
                <a href="../logout.php" class="btn-logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
                <div class="profile-pill">
                    <div class="avatar-circle"><?= htmlspecialchars($stuInitials) ?></div>
                    <div class="profile-meta">
                        <strong><?= htmlspecialchars($student['name']) ?></strong>
                        <small><?= htmlspecialchars($student['identifier']) ?></small>
                    </div>
                </div>
            </div>
        </header>

        <main class="content-container">
            <?php if ($activeView === 'dashboard'): ?>
                <!-- Welcome Banner (Dashboard only) -->
                <div class="hero-banner">
                    <div>
                        <h1>Kumusta, <?= htmlspecialchars($student['name']) ?>!</h1>
                        <p>I-scan ang QR Code ng iyong Teacher para sa attendance, at tingnan ang iyong mga subjects at guro.</p>
                        <div class="hero-badges">
                            <span class="hero-badge"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($student['identifier']) ?></span>
                            <span class="hero-badge"><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($studentGrade) ?></span>
                            <span class="hero-badge"><i class="fa-solid fa-users-rectangle"></i> <?= htmlspecialchars($studentSection) ?></span>
                        </div>
                    </div>
                    <a href="dashboard.php?view=scanner" class="btn-scan-cta">
                        <i class="fa-solid fa-camera"></i> Buksan ang QR Scanner
                    </a>
                </div>

                <!-- 4 Summary Metrics (Dashboard only) -->
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-icon teal"><i class="fa-solid fa-book-open"></i></div>
                        <div>
                            <div class="kpi-val"><?= count($enrolledSubjects) ?></div>
                            <div class="kpi-label">Assigned Subjects</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon blue"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <div>
                            <div class="kpi-val"><?= count($myTeachers) ?></div>
                            <div class="kpi-label">Assigned Teachers</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="kpi-val" id="stuPresentCount"><?= (int)($stats['present_count'] ?? 0) ?></div>
                            <div class="kpi-label">Present</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon amber"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <div class="kpi-val" id="stuLateCount"><?= (int)($stats['late_count'] ?? 0) ?></div>
                            <div class="kpi-label">Late</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- QR SCANNER VIEW -->
            <?php if ($activeView === 'scanner'): ?>
                <div class="workspace-grid" style="grid-template-columns: 1fr;">
                    <!-- QR SCANNER CARD (Student scans Teacher's QR code) -->
                    <section class="card" id="qr-scanner-section">
                        <div class="card-header">
                            <div class="card-title">
                                <i class="fa-solid fa-camera"></i>
                                <span>QR Attendance Scanner</span>
                            </div>
                            <span class="pill-count">Scan Teacher QR</span>
                        </div>

                        <p style="font-size:0.85rem;color:var(--ink-muted);margin-bottom:14px;">
                            Itutok ang camera sa <strong>QR Code ng iyong Teacher</strong> upang awtomatikong maitala ang iyong attendance sa klase.
                        </p>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:0.78rem;font-weight:700;color:var(--ink-main);margin-bottom:5px;">
                                Piliin ang Subject (Opsyonal — awtomatikong made-detect mula sa QR ng Teacher):
                            </label>
                            <select id="scannerSubjectSelect" class="form-input" style="width:100%;">
                                <option value="0">-- Auto-Detect Subject mula sa QR Code ng Teacher --</option>
                                <?php foreach ($enrolledSubjects as $sub): ?>
                                    <option value="<?= (int)$sub['subject_id'] ?>">
                                        <?= htmlspecialchars($sub['subject_code']) ?> — <?= htmlspecialchars($sub['subject_name']) ?> (<?= htmlspecialchars($sub['teacher_full_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="studentQrReader"></div>

                        <div class="scanner-controls">
                            <button type="button" id="btnStartScan" class="btn btn-primary" onclick="startStudentScanner()">
                                <i class="fa-solid fa-video"></i> Buksan ang Camera Scanner
                            </button>
                            <button type="button" id="btnStopScan" class="btn btn-secondary" onclick="stopStudentScanner()" style="display:none;">
                                <i class="fa-solid fa-stop"></i> Isara ang Camera
                            </button>
                            <button type="button" id="btnFlipScan" class="btn btn-secondary" onclick="flipStudentCamera()" style="display:none;">
                                <i class="fa-solid fa-camera-rotate"></i> Flip Camera
                            </button>
                            <label class="btn btn-secondary" style="margin:0;">
                                <i class="fa-solid fa-image"></i> Upload QR Image
                                <input type="file" id="qrImageInput" accept="image/*" style="display:none;" onchange="scanUploadedQrImage(this)">
                            </label>
                        </div>

                        <div id="scanFeedbackBox" class="scan-feedback"></div>

                        <div class="manual-token-box">
                            <label for="manualQrInput">Walang camera? I-type o i-paste ang Attendance QR Code / Token ng Teacher:</label>
                            <div class="input-row">
                                <input type="text" id="manualQrInput" class="form-input" placeholder="Hal. PROF_ATTENDANCE|... o Teacher Code">
                                <button type="button" class="btn btn-primary" onclick="submitManualTeacherCode()">
                                    <i class="fa-solid fa-paper-plane"></i> Ipasa
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            <?php endif; ?>

            <!-- FULL SUBJECTS VIEW -->
            <?php if ($activeView === 'subjects'): ?>
                <section class="card" style="margin-bottom:22px;">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-book-open"></i>
                            <span>Lahat ng Subjects na Naka-assign sa Iyo</span>
                        </div>
                        <span class="pill-count"><?= count($enrolledSubjects) ?> Subjects</span>
                    </div>

                    <?php if (empty($enrolledSubjects)): ?>
                        <div style="padding:28px;text-align:center;color:var(--ink-muted);">Walang naka-assign na subjects.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="clean-table">
                                <thead>
                                    <tr>
                                        <th>Subject Code</th>
                                        <th>Subject Description</th>
                                        <th>Grade / Section</th>
                                        <th>Buong Pangalan ng Teacher</th>
                                        <th>Class Schedule</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolledSubjects as $sub): ?>
                                        <tr>
                                            <td><span class="subject-code-badge"><?= htmlspecialchars($sub['subject_code']) ?></span></td>
                                            <td><strong style="color:var(--ink-dark);"><?= htmlspecialchars($sub['subject_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($sub['grade_name'] ?: $studentGrade) ?> • <?= htmlspecialchars($studentSection) ?></td>
                                            <td>
                                                <strong style="color:var(--primary);"><?= htmlspecialchars($sub['teacher_full_name']) ?></strong>
                                                <?php if (!empty($sub['teacher_email'])): ?>
                                                    <div style="font-size:0.75rem;color:var(--ink-muted);"><?= htmlspecialchars($sub['teacher_email']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong style="color:var(--ink-dark);"><?= htmlspecialchars($sub['schedule_date']) ?></strong>
                                                <span style="color:var(--ink-muted);"> • <?= htmlspecialchars($sub['schedule_time']) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- FULL TEACHERS LIST VIEW -->
            <?php if ($activeView === 'teachers'): ?>
                <section class="card" style="margin-bottom:22px;">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-chalkboard-user"></i>
                            <span>Mga Teachers na Nagtuturo sa Iyo (Full Name &amp; Subjects)</span>
                        </div>
                        <span class="pill-count"><?= count($myTeachers) ?> Teachers</span>
                    </div>

                    <?php if (empty($myTeachers)): ?>
                        <div style="padding:24px;text-align:center;color:var(--ink-muted);">Wala pang nakatalagang guro.</div>
                    <?php else: ?>
                        <div class="teachers-grid">
                            <?php foreach ($myTeachers as $t): ?>
                                <div class="teacher-card">
                                    <div class="teacher-card-top">
                                        <div class="teacher-avatar-lg"><?= htmlspecialchars($t['initials']) ?></div>
                                        <div>
                                            <div class="teacher-card-name"><?= htmlspecialchars($t['full_name']) ?></div>
                                            <div class="teacher-card-id">
                                                <?= htmlspecialchars($t['identifier']) ?>
                                                <?php if (!empty($t['email'])): ?>
                                                    • <?= htmlspecialchars($t['email']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <ul class="teacher-subject-list">
                                        <?php foreach ($t['subjects'] as $ts): ?>
                                            <li>
                                                <span><strong style="color:var(--primary);"><?= htmlspecialchars($ts['code']) ?></strong> — <?= htmlspecialchars($ts['name']) ?></span>
                                                <small style="color:var(--ink-muted);"><?= htmlspecialchars($ts['schedule']) ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- ATTENDANCE HISTORY TABLE (Shown on Dashboard and Attendance view) -->
            <?php if ($activeView === 'dashboard' || $activeView === 'attendance'): ?>
                <section class="card" id="attendance-records">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-clipboard-check"></i>
                            <span>Kasaysayan ng Iyong Attendance (Attendance History)</span>
                        </div>
                        <span class="pill-count" id="totalLogsBadge"><?= count($recentLogs) ?> Records</span>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="clean-table" id="studentAttendanceTable">
                            <thead>
                                <tr>
                                    <th>Petsa (Date)</th>
                                    <th>Subject</th>
                                    <th>Teacher</th>
                                    <th>Oras ng Pag-scan (Time In)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="studentAttendanceTbody">
                                <?php if (empty($recentLogs)): ?>
                                    <tr id="emptyAttendanceRow">
                                        <td colspan="5" style="padding:24px;text-align:center;color:var(--ink-muted);">
                                            Wala pang attendance record. Gamitin ang QR Scanner upang mag-scan sa QR Code ng iyong Teacher.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentLogs as $log):
                                        $stLower = strtolower($log['status']);
                                        $badgeCl = $stLower === 'late' ? 'late' : ($stLower === 'absent' ? 'absent' : 'present');
                                    ?>
                                        <tr>
                                            <td><strong><?= date('M d, Y', strtotime($log['date'])) ?></strong></td>
                                            <td>
                                                <span class="subject-code-badge"><?= htmlspecialchars($log['subject_code']) ?></span>
                                                <span style="margin-left:6px;font-weight:700;"><?= htmlspecialchars($log['subject_name']) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($log['professor_name']) ?></td>
                                            <td><?= date('h:i A', strtotime($log['time_in'])) ?></td>
                                            <td>
                                                <span class="status-badge <?= $badgeCl ?>">
                                                    <i class="fa-solid <?= $badgeCl === 'present' ? 'fa-check' : ($badgeCl === 'late' ? 'fa-clock' : 'fa-xmark') ?>"></i>
                                                    <?= htmlspecialchars($log['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
    let studentQrScanner = null;
    let isScannerRunning = false;
    let currentCameraFacing = "environment";
    let lastDecodedToken = "";
    let lastDecodedAt = 0;

    function showScanFeedback(message, type = 'success') {
        const box = document.getElementById('scanFeedbackBox');
        if (!box) return;
        box.className = 'scan-feedback ' + type;
        const icon = type === 'success'
            ? 'fa-circle-check'
            : (type === 'warning' ? 'fa-clock' : 'fa-triangle-exclamation');
        box.innerHTML = `<i class="fa-solid ${icon}"></i> <div>${message}</div>`;
    }

    function startStudentScanner() {
        if (isScannerRunning) return;
        const readerEl = document.getElementById('studentQrReader');
        if (!readerEl || typeof Html5Qrcode === 'undefined') {
            showScanFeedback('Hindi ma-load ang QR camera module.', 'danger');
            return;
        }

        studentQrScanner = new Html5Qrcode("studentQrReader");
        studentQrScanner.start(
            { facingMode: currentCameraFacing },
            { fps: 10, qrbox: { width: 240, height: 240 } },
            (decodedText) => {
                const now = Date.now();
                if (decodedText === lastDecodedToken && (now - lastDecodedAt) < 3500) {
                    return;
                }
                lastDecodedToken = decodedText;
                lastDecodedAt = now;
                submitScannedTeacherQr(decodedText);
            },
            () => {}
        ).then(() => {
            isScannerRunning = true;
            document.getElementById('btnStartScan').style.display = 'none';
            document.getElementById('btnStopScan').style.display = 'inline-flex';
            document.getElementById('btnFlipScan').style.display = 'inline-flex';
        }).catch((err) => {
            console.error(err);
            showScanFeedback('Hindi mabuksan ang camera. Pakisiguradong pinayagan ang Camera Permission o gumamit ng Upload QR Image / Manual Code.', 'danger');
        });
    }

    function stopStudentScanner() {
        if (!isScannerRunning || !studentQrScanner) return;
        studentQrScanner.stop().then(() => {
            isScannerRunning = false;
            document.getElementById('btnStartScan').style.display = 'inline-flex';
            document.getElementById('btnStopScan').style.display = 'none';
            document.getElementById('btnFlipScan').style.display = 'none';
        }).catch(err => console.error(err));
    }

    function flipStudentCamera() {
        if (!isScannerRunning) return;
        stopStudentScanner();
        currentCameraFacing = currentCameraFacing === "environment" ? "user" : "environment";
        setTimeout(startStudentScanner, 350);
    }

    function scanUploadedQrImage(input) {
        if (!input.files || !input.files[0]) return;
        const imageFile = input.files[0];
        const qrReader = studentQrScanner || new Html5Qrcode("studentQrReader");
        qrReader.scanFile(imageFile, true)
            .then(decodedText => {
                submitScannedTeacherQr(decodedText);
                input.value = '';
            })
            .catch(() => {
                showScanFeedback('Walang nakitang valid na QR code sa napiling larawan.', 'danger');
                input.value = '';
            });
    }

    function submitManualTeacherCode() {
        const input = document.getElementById('manualQrInput');
        const val = input ? input.value.trim() : '';
        if (!val) {
            showScanFeedback('Mangyaring ilagay ang QR Code token ng iyong Teacher.', 'warning');
            return;
        }
        submitScannedTeacherQr(val);
        input.value = '';
    }

    function submitScannedTeacherQr(qrTokenString) {
        const subjectSelect = document.getElementById('scannerSubjectSelect');
        const selectedSubjectId = subjectSelect ? parseInt(subjectSelect.value, 10) || 0 : 0;

        fetch('../api/log_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                qr_token: qrTokenString,
                subject_id: selectedSubjectId
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const subCode = data.subject ? data.subject.subject_code : '';
                const subName = data.subject ? data.subject.subject_name : '';
                const profName = data.subject ? data.subject.professor_name : '';
                showScanFeedback(
                    `<strong>Attendance Recorded (${data.attendance.status})!</strong><br>` +
                    `Subject: <strong>${subCode} - ${subName}</strong> • Teacher: <strong>${profName}</strong> • Time: ${data.attendance.time_in}`,
                    data.attendance.status === 'Present' ? 'success' : 'warning'
                );
                prependStudentAttendanceRow(data);
            } else if (data.status === 'already_recorded') {
                const subName = data.subject ? `${data.subject.subject_code} - ${data.subject.subject_name}` : '';
                showScanFeedback(
                    `<strong>${data.message}</strong> (${subName} • ${data.attendance.time_in} - ${data.attendance.status})`,
                    'warning'
                );
            } else {
                showScanFeedback(data.message || 'Hindi ma-verify ang QR code.', 'danger');
            }
        })
        .catch(() => {
            showScanFeedback('Nagkaroon ng network error habang itinatala ang attendance.', 'danger');
        });
    }

    function prependStudentAttendanceRow(data) {
        const tbody = document.getElementById('studentAttendanceTbody');
        if (!tbody || !data.subject || !data.attendance) return;

        const emptyRow = document.getElementById('emptyAttendanceRow');
        if (emptyRow) emptyRow.remove();

        const st = data.attendance.status || 'Present';
        const stLower = st.toLowerCase();
        const badgeCl = stLower === 'late' ? 'late' : (stLower === 'absent' ? 'absent' : 'present');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong>${data.attendance.date}</strong></td>
            <td>
                <span class="subject-code-badge">${data.subject.subject_code}</span>
                <span style="margin-left:6px;font-weight:700;">${data.subject.subject_name}</span>
            </td>
            <td>${data.subject.professor_name}</td>
            <td>${data.attendance.time_in}</td>
            <td>
                <span class="status-badge ${badgeCl}">
                    <i class="fa-solid ${badgeCl === 'present' ? 'fa-check' : 'fa-clock'}"></i>
                    ${st}
                </span>
            </td>
        `;
        tbody.insertBefore(tr, tbody.firstChild);

        if (st === 'Present') {
            const pEl = document.getElementById('stuPresentCount');
            if (pEl) pEl.textContent = (parseInt(pEl.textContent, 10) || 0) + 1;
        } else if (st === 'Late') {
            const lEl = document.getElementById('stuLateCount');
            if (lEl) lEl.textContent = (parseInt(lEl.textContent, 10) || 0) + 1;
        }
    }

    <?php if ($activeView === 'scanner'): ?>
    window.addEventListener('DOMContentLoaded', () => {
        startStudentScanner();
    });
    <?php endif; ?>
</script>

</body>
</html>
