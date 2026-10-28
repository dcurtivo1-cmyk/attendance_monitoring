<?php
// admin/reports.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

// Report Type: student | teacher | grade | subject
$report_type = trim($_GET['report_type'] ?? 'student');
if (!in_array($report_type, ['student', 'teacher', 'grade', 'subject'])) {
    $report_type = 'student';
}

// Filters
$start_date     = trim($_GET['start_date'] ?? date('Y-m-01'));
$end_date       = trim($_GET['end_date'] ?? date('Y-m-d'));
$grade_filter   = trim($_GET['grade_level'] ?? '');
$subject_filter = trim($_GET['subject'] ?? '');
$teacher_filter = intval($_GET['teacher_id'] ?? 0);
$status_filter  = trim($_GET['status'] ?? '');

// Fetch Dropdown Options
$gradeLevels = $pdo->query("SELECT * FROM grade_levels ORDER BY grade_number ASC")->fetchAll();
$allTeachers = $pdo->query("SELECT id, teacher_id, full_name, subject_name, grade_level_name FROM teachers ORDER BY full_name ASC")->fetchAll();
$coreSubjectsList = [
    'English',
    'Mathematics',
    'Science',
    'Filipino',
    'Araling Panlipunan',
    'MAPEH',
    'TLE',
    'Computer'
];

// 1. STUDENT ATTENDANCE REPORT QUERY
$studentRecords = [];
if ($report_type === 'student') {
    $sql = "
        SELECT a.date, a.time_in, a.status,
               u.identifier AS student_id, u.name AS student_name, u.course_section,
               s.subject_code, s.subject_name,
               gl.grade_name,
               prof.name AS teacher_name
        FROM attendance a
        JOIN users u ON u.id = a.student_id
        JOIN subjects s ON s.id = a.subject_id
        LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
        LEFT JOIN users prof ON prof.id = COALESCE(a.scanned_by, s.professor_id)
        WHERE a.date BETWEEN :start_date AND :end_date
    ";
    $params = [':start_date' => $start_date, ':end_date' => $end_date];

    if ($grade_filter !== '') {
        $sql .= " AND (gl.grade_name = :grade OR u.course_section LIKE :grade_like)";
        $params[':grade'] = $grade_filter;
        $params[':grade_like'] = "%$grade_filter%";
    }
    if ($subject_filter !== '') {
        $sql .= " AND s.subject_name = :subject";
        $params[':subject'] = $subject_filter;
    }
    if ($teacher_filter > 0) {
        $tRow = $pdo->prepare("SELECT user_id FROM teachers WHERE id = ?");
        $tRow->execute([$teacher_filter]);
        $linkedUserId = (int)$tRow->fetchColumn();
        if ($linkedUserId > 0) {
            $sql .= " AND a.scanned_by = :scanned_by";
            $params[':scanned_by'] = $linkedUserId;
        }
    }
    if ($status_filter !== '') {
        $sql .= " AND a.status = :status";
        $params[':status'] = $status_filter;
    }

    $sql .= " ORDER BY a.date DESC, a.time_in DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $studentRecords = $stmt->fetchAll();
}

// 2. TEACHER ATTENDANCE REPORT QUERY
$teacherRecords = [];
if ($report_type === 'teacher') {
    $sql = "
        SELECT ta.*, t.teacher_id AS teacher_code, t.full_name, t.email, t.contact_number
        FROM teacher_attendance ta
        JOIN teachers t ON t.id = ta.teacher_id
        WHERE ta.date BETWEEN :start_date AND :end_date
    ";
    $params = [':start_date' => $start_date, ':end_date' => $end_date];

    if ($grade_filter !== '') {
        $sql .= " AND ta.grade_level_name = :grade";
        $params[':grade'] = $grade_filter;
    }
    if ($subject_filter !== '') {
        $sql .= " AND ta.subject_name = :subject";
        $params[':subject'] = $subject_filter;
    }
    if ($teacher_filter > 0) {
        $sql .= " AND ta.teacher_id = :t_id";
        $params[':t_id'] = $teacher_filter;
    }
    if ($status_filter !== '') {
        $sql .= " AND ta.status = :status";
        $params[':status'] = $status_filter;
    }

    $sql .= " ORDER BY ta.date DESC, t.full_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $teacherRecords = $stmt->fetchAll();
}

// 3. GRADE LEVEL ATTENDANCE REPORT QUERY
$gradeRecords = [];
if ($report_type === 'grade') {
    foreach ($gradeLevels as $gl) {
        if ($grade_filter !== '' && $gl['grade_name'] !== $grade_filter) {
            continue;
        }

        // Teacher attendance for this grade
        $tSql = "
            SELECT 
                COUNT(*) AS total_teacher_logs,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS t_present,
                SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) AS t_late,
                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS t_absent
            FROM teacher_attendance
            WHERE grade_level_name = :gname AND date BETWEEN :sdate AND :edate
        ";
        $tParams = [':gname' => $gl['grade_name'], ':sdate' => $start_date, ':edate' => $end_date];
        if ($subject_filter !== '') {
            $tSql .= " AND subject_name = :subj";
            $tParams[':subj'] = $subject_filter;
        }
        if ($status_filter !== '') {
            $tSql .= " AND status = :st";
            $tParams[':st'] = $status_filter;
        }
        $tSt = $pdo->prepare($tSql);
        $tSt->execute($tParams);
        $tAgg = $tSt->fetch();

        // Assigned teachers count
        $assignedT = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE grade_level_name = ?");
        $assignedT->execute([$gl['grade_name']]);
        $tCount = (int)$assignedT->fetchColumn();

        // Assigned subjects list
        $subSt = $pdo->prepare("SELECT DISTINCT subject_name FROM subjects WHERE grade_level_id = ?");
        $subSt->execute([$gl['id']]);
        $subNames = $subSt->fetchAll(PDO::FETCH_COLUMN);

        $totLogs = (int)($tAgg['total_teacher_logs'] ?? 0);
        $presLogs = (int)($tAgg['t_present'] ?? 0) + (int)($tAgg['t_late'] ?? 0);
        $rate = $totLogs > 0 ? round(($presLogs / $totLogs) * 100) : 100;

        $gradeRecords[] = [
            'grade_name'     => $gl['grade_name'],
            'subjects_count' => count($subNames),
            'subjects_list'  => implode(', ', $subNames),
            'teachers_count' => $tCount,
            'total_logs'     => $totLogs,
            'present'        => (int)($tAgg['t_present'] ?? 0),
            'late'           => (int)($tAgg['t_late'] ?? 0),
            'absent'         => (int)($tAgg['t_absent'] ?? 0),
            'rate'           => $rate
        ];
    }
}

// 4. SUBJECT ATTENDANCE REPORT QUERY
$subjectRecords = [];
if ($report_type === 'subject') {
    $sql = "
        SELECT 
            ta.subject_name,
            ta.grade_level_name,
            t.full_name AS teacher_name,
            COUNT(ta.id) AS total_logs,
            SUM(CASE WHEN ta.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ta.status = 'Late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN ta.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count
        FROM teacher_attendance ta
        JOIN teachers t ON t.id = ta.teacher_id
        WHERE ta.date BETWEEN :start_date AND :end_date
    ";
    $params = [':start_date' => $start_date, ':end_date' => $end_date];

    if ($grade_filter !== '') {
        $sql .= " AND ta.grade_level_name = :grade";
        $params[':grade'] = $grade_filter;
    }
    if ($subject_filter !== '') {
        $sql .= " AND ta.subject_name = :subject";
        $params[':subject'] = $subject_filter;
    }
    if ($teacher_filter > 0) {
        $sql .= " AND ta.teacher_id = :t_id";
        $params[':t_id'] = $teacher_filter;
    }
    if ($status_filter !== '') {
        $sql .= " AND ta.status = :status";
        $params[':status'] = $status_filter;
    }

    $sql .= " GROUP BY ta.subject_name, ta.grade_level_name, t.full_name ORDER BY ta.grade_level_name ASC, ta.subject_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $subjectRecords = $stmt->fetchAll();
}

// Overall KPI Summary for Top Cards
$stuTotalCount = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE date BETWEEN '$start_date' AND '$end_date'")->fetchColumn();
$tchTotalStmt  = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present_cnt,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) AS late_cnt,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent_cnt
    FROM teacher_attendance
    WHERE date BETWEEN ? AND ?
");
$tchTotalStmt->execute([$start_date, $end_date]);
$tchSummary = $tchTotalStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
    </style>
</head>
<body>
<div class="app-shell">
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
            <a class="nav-item" href="students.php">
                <i class="fa-solid fa-user-group"></i>
                <span>Students</span>
            </a>
            <a class="nav-item" href="teachers.php">
                <i class="fa-solid fa-chalkboard-user"></i>
                <span>Teachers</span>
            </a>
            <a class="nav-item" href="subject_editing.php"><i class="fa-solid fa-book-open"></i><span>Subjects &amp; Sections</span></a>
            <a class="nav-item" href="attendance.php">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>Attendance</span>
            </a>
            <a class="nav-item active" href="reports.php">
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

    <main class="main-panel">
        <header class="topbar">
            <div class="topbar-title">Attendance Reports</div>
            <div class="topbar-tools">
                <button class="icon-btn" aria-label="Notifications"><i class="fa-regular fa-bell"></i></button>
                <a class="logout-btn" href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                <div class="user-mini">
                    <div class="avatar">EM</div>
                    <div class="meta">
                        <span><?= htmlspecialchars($user['name']) ?></span>
                        <small>Administrator</small>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrap">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Attendance reports</h1>
                    <div class="page-subtitle">Generate and filter Student, Teacher, Grade Level, and Subject attendance reports.</div>
                </div>
                <button type="button" onclick="window.print()" class="secondary-btn">
                    <i class="fa-solid fa-print"></i> Print Report
                </button>
            </div>

            <!-- Report Category Selector Tabs -->
            <div class="report-tabs">
                <a href="reports.php?report_type=student&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                   class="report-tab <?= $report_type === 'student' ? 'active' : '' ?>">
                    <i class="fa-solid fa-user-graduate"></i> Student Attendance
                </a>
                <a href="reports.php?report_type=teacher&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                   class="report-tab <?= $report_type === 'teacher' ? 'active' : '' ?>">
                    <i class="fa-solid fa-chalkboard-user"></i> Teacher Attendance
                </a>
                <a href="reports.php?report_type=grade&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                   class="report-tab <?= $report_type === 'grade' ? 'active' : '' ?>">
                    <i class="fa-solid fa-layer-group"></i> Grade Level Attendance
                </a>
                <a href="reports.php?report_type=subject&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                   class="report-tab <?= $report_type === 'subject' ? 'active' : '' ?>">
                    <i class="fa-solid fa-book-open"></i> Subject Attendance
                </a>
            </div>

            <!-- Multi-Criteria Filter Bar: Date, Grade Level, Subject, Teacher, Attendance Status -->
            <div class="table-panel" style="padding:20px;margin-bottom:20px;">
                <form method="GET" action="reports.php" style="display:grid;grid-template-columns:repeat(6, minmax(0, 1fr));gap:12px;align-items:flex-end;">
                    <input type="hidden" name="report_type" value="<?= htmlspecialchars($report_type) ?>">

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="start_date">From Date</label>
                        <input class="form-control" type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    </div>

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="end_date">To Date</label>
                        <input class="form-control" type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    </div>

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="grade_level">Grade Level</label>
                        <select class="form-control" id="grade_level" name="grade_level">
                            <option value="">All Grades</option>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= htmlspecialchars($gl['grade_name']) ?>" <?= $grade_filter === $gl['grade_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gl['grade_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="subject">Subject</label>
                        <select class="form-control" id="subject" name="subject">
                            <option value="">All Subjects</option>
                            <?php foreach ($coreSubjectsList as $sItem): ?>
                                <option value="<?= htmlspecialchars($sItem) ?>" <?= $subject_filter === $sItem ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sItem) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="teacher_id">Teacher</label>
                        <select class="form-control" id="teacher_id" name="teacher_id">
                            <option value="0">All Teachers</option>
                            <?php foreach ($allTeachers as $tOpt): ?>
                                <option value="<?= (int)$tOpt['id'] ?>" <?= $teacher_filter === (int)$tOpt['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(professor_display_name($tOpt['full_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="status">Attendance Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="Present" <?= $status_filter === 'Present' ? 'selected' : '' ?>>Present</option>
                            <option value="Late" <?= $status_filter === 'Late' ? 'selected' : '' ?>>Late</option>
                            <option value="Absent" <?= $status_filter === 'Absent' ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </div>

                    <div style="grid-column: span 6; display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px;">
                        <a href="reports.php?report_type=<?= urlencode($report_type) ?>" class="secondary-btn" style="padding:0.55rem 1rem;">Reset Filters</a>
                        <button class="primary-btn" type="submit" style="padding:0.55rem 1.2rem;">
                            <i class="fa-solid fa-filter"></i> Generate Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- Summary Metric Cards -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fa-solid fa-user-graduate"></i></div>
                    <div>
                        <div class="metric-number"><?= $stuTotalCount ?></div>
                        <div class="metric-meta">Student Attendance Logs</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-green"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div class="metric-number"><?= (int)($tchSummary['present_cnt'] ?? 0) ?></div>
                        <div class="metric-meta">Teachers Present Logs</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-amber"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <div class="metric-number"><?= (int)($tchSummary['late_cnt'] ?? 0) ?></div>
                        <div class="metric-meta">Teachers Late Logs</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-red"><i class="fa-solid fa-user-xmark"></i></div>
                    <div>
                        <div class="metric-number"><?= (int)($tchSummary['absent_cnt'] ?? 0) ?></div>
                        <div class="metric-meta">Teachers Absent Logs</div>
                    </div>
                </div>
            </div>

            <!-- REPORT TABLE 1: STUDENT ATTENDANCE -->
            <?php if ($report_type === 'student'): ?>
                <div class="table-wrap" style="margin-top:20px;">
                    <div style="padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
                        <div class="panel-title">Student Attendance Report</div>
                        <span class="badge badge-primary"><?= count($studentRecords) ?> Records</span>
                    </div>
                    <table class="tight-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student ID &amp; Name</th>
                                <th>Grade / Section</th>
                                <th>Subject</th>
                                <th>Teacher</th>
                                <th>Time In</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($studentRecords)): ?>
                                <tr><td colspan="7" style="padding:24px;text-align:center;color:var(--ink-muted);">No student attendance records found for the selected filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($studentRecords as $row):
                                    $stBadge = strtolower($row['status']) === 'present' ? 'present' : (strtolower($row['status']) === 'late' ? 'late' : 'absent');
                                ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($row['date'])) ?></td>
                                        <td><strong><?= htmlspecialchars($row['student_name']) ?></strong><br><small><?= htmlspecialchars($row['student_id']) ?></small></td>
                                        <td><?= htmlspecialchars($row['grade_name'] ?? ($row['course_section'] ?: 'Grade 7')) ?></td>
                                        <td><strong><?= htmlspecialchars($row['subject_name']) ?></strong> <small>(<?= htmlspecialchars($row['subject_code']) ?>)</small></td>
                                        <td><?= htmlspecialchars(professor_display_name($row['teacher_name'] ?? 'N/A')) ?></td>
                                        <td><?= school_format_time($row['time_in']) ?></td>
                                        <td><span class="status-pill <?= $stBadge ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- REPORT TABLE 2: TEACHER ATTENDANCE -->
            <?php elseif ($report_type === 'teacher'): ?>
                <div class="table-wrap" style="margin-top:20px;">
                    <div style="padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
                        <div class="panel-title">Teacher Attendance Report</div>
                        <span class="badge badge-primary"><?= count($teacherRecords) ?> Records</span>
                    </div>
                    <table class="tight-table">
                        <thead>
                            <tr>
                                <th>Date / Day</th>
                                <th>Teacher Name</th>
                                <th>Subject</th>
                                <th>Grade Level</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Attendance Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($teacherRecords)): ?>
                                <tr><td colspan="7" style="padding:24px;text-align:center;color:var(--ink-muted);">No teacher attendance records found for the selected filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($teacherRecords as $row):
                                    $stBadge = strtolower($row['status']) === 'present' ? 'present' : (strtolower($row['status']) === 'late' ? 'late' : 'absent');
                                ?>
                                    <tr>
                                        <td><strong><?= date('M d, Y', strtotime($row['date'])) ?></strong><br><small><?= date('l', strtotime($row['date'])) ?></small></td>
                                        <td><strong><?= htmlspecialchars(professor_display_name($row['full_name'])) ?></strong></td>
                                        <td><strong><?= htmlspecialchars($row['subject_name']) ?></strong></td>
                                        <td><span class="student-pill"><?= htmlspecialchars($row['grade_level_name']) ?></span></td>
                                        <td><?= !empty($row['time_in']) ? school_format_time($row['time_in']) : '—' ?></td>
                                        <td><?= !empty($row['time_out']) ? date('h:i A', strtotime($row['time_out'])) : '—' ?></td>
                                        <td><span class="status-pill <?= $stBadge ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- REPORT TABLE 3: GRADE LEVEL ATTENDANCE -->
            <?php elseif ($report_type === 'grade'): ?>
                <div class="table-wrap" style="margin-top:20px;">
                    <div style="padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
                        <div class="panel-title">Grade Level Attendance Summary Report</div>
                        <span class="badge badge-primary"><?= count($gradeRecords) ?> Grade Levels</span>
                    </div>
                    <table class="tight-table">
                        <thead>
                            <tr>
                                <th>Grade Level</th>
                                <th>Assigned Subjects</th>
                                <th>Teachers</th>
                                <th>Total Logs</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradeRecords as $gRow): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($gRow['grade_name']) ?></strong></td>
                                    <td style="max-width:320px;"><small><?= htmlspecialchars($gRow['subjects_list']) ?></small></td>
                                    <td><?= $gRow['teachers_count'] ?></td>
                                    <td><?= $gRow['total_logs'] ?></td>
                                    <td><span class="status-pill present"><?= $gRow['present'] ?></span></td>
                                    <td><span class="status-pill late"><?= $gRow['late'] ?></span></td>
                                    <td><span class="status-pill absent"><?= $gRow['absent'] ?></span></td>
                                    <td><strong><?= $gRow['rate'] ?>%</strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <!-- REPORT TABLE 4: SUBJECT ATTENDANCE -->
            <?php elseif ($report_type === 'subject'): ?>
                <div class="table-wrap" style="margin-top:20px;">
                    <div style="padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
                        <div class="panel-title">Subject Attendance Summary Report</div>
                        <span class="badge badge-primary"><?= count($subjectRecords) ?> Subjects</span>
                    </div>
                    <table class="tight-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Grade Level</th>
                                <th>Assigned Teacher</th>
                                <th>Total Sessions</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($subjectRecords)): ?>
                                <tr><td colspan="7" style="padding:24px;text-align:center;color:var(--ink-muted);">No subject attendance records match your filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ($subjectRecords as $sRow): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($sRow['subject_name']) ?></strong></td>
                                        <td><span class="student-pill"><?= htmlspecialchars($sRow['grade_level_name']) ?></span></td>
                                        <td><?= htmlspecialchars(professor_display_name($sRow['teacher_name'])) ?></td>
                                        <td><?= (int)$sRow['total_logs'] ?></td>
                                        <td><span class="status-pill present"><?= (int)$sRow['present_count'] ?></span></td>
                                        <td><span class="status-pill late"><?= (int)$sRow['late_count'] ?></span></td>
                                        <td><span class="status-pill absent"><?= (int)$sRow['absent_count'] ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>