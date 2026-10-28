<?php
// professor/reports.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();
$accountStmt = $pdo->prepare('SELECT id, name, email, identifier FROM users WHERE id = ?');
$accountStmt->execute([$user['id']]);
$teacherAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: $user;
$prof_id = $user['id'];

// Get all subjects
$subjectsStmt = $pdo->prepare("SELECT * FROM subjects WHERE professor_id = ? ORDER BY subject_code ASC");
$subjectsStmt->execute([$prof_id]);
$subjects = $subjectsStmt->fetchAll();

// Filters
$subject_filter = intval($_GET['subject_id'] ?? 0);
$start_date     = trim($_GET['start_date'] ?? date('Y-m-01')); // Start of current month
$end_date       = trim($_GET['end_date'] ?? date('Y-m-d'));
$status_filter  = trim($_GET['status'] ?? 'all');

// Build Query
$query = "
    SELECT a.*, u.name as student_name, u.identifier as student_id_number, u.course_section,
           s.subject_code, s.subject_name
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    JOIN subjects s ON a.subject_id = s.id
    WHERE COALESCE(a.scanned_by, s.professor_id) = :prof_id
      AND a.date BETWEEN :start_date AND :end_date
";
$params = [
    ':prof_id'    => $prof_id,
    ':start_date' => $start_date,
    ':end_date'   => $end_date
];

if ($subject_filter > 0) {
    $query .= " AND a.subject_id = :subject_id";
    $params[':subject_id'] = $subject_filter;
}

if (in_array($status_filter, ['Present', 'Late', 'Absent', 'Excused'])) {
    $query .= " AND a.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY a.date DESC, a.time_in DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$attendanceRecords = $stmt->fetchAll();

// Export to CSV feature
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_report_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['#', 'Student ID', 'Student Name', 'Grade & Section', 'Subject Code', 'Subject Name', 'Date', 'Time In', 'Status']);
    
    $i = 1;
    foreach ($attendanceRecords as $row) {
        fputcsv($output, [
            $i++,
            $row['student_id_number'],
            $row['student_name'],
            $row['course_section'] ?? 'No section assigned',
            $row['subject_code'],
            $row['subject_name'],
            $row['date'],
            school_format_time($row['time_in']),
            $row['status']
        ]);
    }
    fclose($output);
    exit;
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
    <title>Attendance Reports - Teacher Portal</title>
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

        .form-group { margin-bottom: 10px; }
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

        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 11px; border-radius: 999px; font-size: 0.75rem; font-weight: 700;
        }
        .status-badge.present { background: var(--green-soft); color: var(--green); }
        .status-badge.late { background: var(--amber-soft); color: var(--amber); }
        .status-badge.absent { background: var(--red-soft); color: var(--red); }
        .status-badge.excused { background: #e0f0ff; color: #1d64c8; }

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
            <a href="dashboard.php" class="nav-link">
                <i class="fa-solid fa-qrcode"></i>
                <span>Dashboard &amp; QR Code</span>
            </a>
            <a href="manual_attendance.php" class="nav-link">
                <i class="fa-solid fa-clipboard-user"></i>
                <span>Student Attendance</span>
            </a>
            <a href="subjects.php" class="nav-link">
                <i class="fa-solid fa-book-open"></i>
                <span>My Subjects &amp; Students</span>
            </a>
            <a href="reports.php" class="nav-link active">
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
                <i class="fa-solid fa-chart-line" style="color:var(--primary);"></i>
                <span>Attendance Reports &amp; Analytics</span>
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
            <!-- Filter Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-filter" style="color: var(--primary);"></i> Filter Attendance Logs</div>
                </div>

                <form action="reports.php" method="GET">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;align-items:flex-end;">
                        <div class="form-group">
                            <label class="form-label" for="subject_id">Subject</label>
                            <select id="subject_id" name="subject_id" class="form-control">
                                <option value="0">All Subjects</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $subject_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['subject_code']) ?> — <?= htmlspecialchars($s['subject_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="start_date">From Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="end_date">To Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="status">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="Present" <?= $status_filter === 'Present' ? 'selected' : '' ?>>Present</option>
                                <option value="Late" <?= $status_filter === 'Late' ? 'selected' : '' ?>>Late</option>
                                <option value="Absent" <?= $status_filter === 'Absent' ? 'selected' : '' ?>>Absent</option>
                                <option value="Excused" <?= $status_filter === 'Excused' ? 'selected' : '' ?>>Excused</option>
                            </select>
                        </div>

                        <div style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary" style="height:40px;">
                                <i class="fa-solid fa-magnifying-glass"></i> Filter
                            </button>
                            <a href="reports.php" class="btn btn-secondary" style="height:40px;">
                                <i class="fa-solid fa-arrows-rotate"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Attendance Logs Table Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fa-solid fa-table-list" style="color: var(--primary);"></i> 
                        Attendance Records Found (<?= count($attendanceRecords) ?>)
                    </div>
                    <?php if (!empty($attendanceRecords)): ?>
                        <div style="display:flex;gap:8px;">
                            <a href="reports.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary" style="padding:6px 12px;font-size:0.78rem;">
                                <i class="fa-solid fa-file-csv"></i> Export CSV
                            </a>
                            <button onclick="window.print()" class="btn btn-secondary" style="padding:6px 12px;font-size:0.78rem;">
                                <i class="fa-solid fa-print"></i> Print
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($attendanceRecords)): ?>
                    <div style="text-align: center; padding: 2.5rem; color: var(--ink-muted);">
                        <i class="fa-solid fa-clipboard-question" style="font-size: 2.2rem; margin-bottom: 0.5rem; opacity: 0.4;"></i>
                        <p>No attendance records match the selected date and filters.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="clean-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Section</th>
                                    <th>Subject</th>
                                    <th>Time Scanned</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceRecords as $row):
                                    $stLower = strtolower($row['status']);
                                    $stBadge = $stLower === 'present' ? 'present' : ($stLower === 'late' ? 'late' : ($stLower === 'absent' ? 'absent' : 'excused'));
                                ?>
                                    <tr>
                                        <td><strong><?= date('M d, Y', strtotime($row['date'])) ?></strong></td>
                                        <td><?= htmlspecialchars($row['student_id_number']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['student_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['course_section'] ?: 'No section assigned') ?></td>
                                        <td>
                                            <span style="font-weight:700;color:var(--primary);"><?= htmlspecialchars($row['subject_code']) ?></span>
                                            <div style="font-size:0.75rem;color:var(--ink-muted);"><?= htmlspecialchars($row['subject_name']) ?></div>
                                        </td>
                                        <td><?= school_format_time($row['time_in']) ?></td>
                                        <td>
                                            <span class="status-badge <?= $stBadge ?>">
                                                <i class="fa-solid <?= $stBadge === 'present' ? 'fa-check' : ($stBadge === 'late' ? 'fa-clock' : 'fa-xmark') ?>"></i>
                                                <?= htmlspecialchars($row['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

</body>
</html>