<?php
// admin/attendance.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/student_sections.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();
$studentSectionsByGrade = get_student_sections_by_grade($pdo);

// Helper function to extract initials from teacher name
function get_teacher_initials(string $name): string {
    $clean = professor_display_name($name);
    $parts = preg_split('/\s+/', $clean);
    $parts = array_values(array_filter($parts));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($parts[0] ?? 'TC', 0, 2));
}

// =========================================================================
// AJAX / JSON API HANDLERS (VIEW, EDIT, STATUS CHANGE, DELETE)
// =========================================================================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_GET['ajax_action'];

    try {
        // 1. Get Single Teacher Attendance Record
        if ($ajaxAction === 'get_record') {
            $id = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT 
                    ta.id,
                    ta.teacher_id,
                    ta.date as record_date,
                    COALESCE(ta.time_in, '07:52:00') as record_time,
                    ta.time_out,
                    ta.status,
                    COALESCE(ta.remarks, 'On time') as remarks,
                    t.teacher_id as teacher_code,
                    t.full_name as teacher_name,
                    t.email as teacher_email,
                    t.grade_level_name as grade_level,
                    COALESCE(t.section_name, 'No section assigned') as section_name,
                    t.subject_name as subject_name
                FROM `teacher_attendance` ta
                JOIN `teachers` t ON t.id = ta.teacher_id
                WHERE ta.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Teacher attendance record not found.']);
                exit;
            }

            $initials = get_teacher_initials($row['teacher_name']);

            echo json_encode([
                'status' => 'success',
                'record' => [
                    'id'              => (int)$row['id'],
                    'record_date'     => $row['record_date'],
                    'formatted_date'  => date('M j, Y', strtotime($row['record_date'])),
                    'day_short'       => date('D', strtotime($row['record_date'])),
                    'record_time'     => substr($row['record_time'], 0, 5),
                    'formatted_time'  => date('h:i A', strtotime($row['record_time'])),
                    'teacher_name'    => professor_display_name($row['teacher_name']),
                    'teacher_code'    => $row['teacher_code'],
                    'student_name'    => professor_display_name($row['teacher_name']),
                    'student_code'    => $row['teacher_code'],
                    'avatar_initials' => $initials,
                    'grade_level'     => $row['grade_level'],
                    'section_name'    => $row['section_name'],
                    'subject_name'    => $row['subject_name'],
                    'status'          => $row['status'],
                    'remarks'         => $row['remarks']
                ]
            ]);
            exit;
        }

        // 2. Quick Status Badge Update (Present / Absent / Late)
        if ($ajaxAction === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = intval($input['id'] ?? 0);
            $newStatus = trim($input['status'] ?? 'Present');
            if (!in_array($newStatus, ['Present', 'Absent', 'Late'], true)) {
                $newStatus = 'Present';
            }

            $remarks = $newStatus === 'Present' ? 'On time' : ($newStatus === 'Late' ? 'Late arrival' : 'Recorded absent');
            $upd = $pdo->prepare("UPDATE `teacher_attendance` SET `status` = ?, `remarks` = ? WHERE `id` = ?");
            $upd->execute([$newStatus, $remarks, $id]);

            echo json_encode([
                'status'     => 'success',
                'message'    => "Teacher attendance status updated to $newStatus.",
                'id'         => $id,
                'new_status' => $newStatus,
                'remarks'    => $remarks
            ]);
            exit;
        }

        // 3. Edit Teacher Attendance Record via Modal
        if ($ajaxAction === 'update_record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id           = intval($input['id'] ?? 0);
            $record_date  = trim($input['record_date'] ?? date('Y-m-d'));
            $record_time  = trim($input['record_time'] ?? '07:52');
            $status       = trim($input['status'] ?? 'Present');
            $remarks      = trim($input['remarks'] ?? 'On time');

            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid record ID.']);
                exit;
            }

            $upd = $pdo->prepare("
                UPDATE `teacher_attendance`
                SET `date` = ?, `time_in` = ?, `status` = ?, `remarks` = ?
                WHERE `id` = ?
            ");
            $upd->execute([
                $record_date,
                strlen($record_time) === 5 ? $record_time . ':00' : $record_time,
                in_array($status, ['Present', 'Absent', 'Late'], true) ? $status : 'Present',
                $remarks !== '' ? $remarks : 'On time',
                $id
            ]);

            // Fetch updated row
            $stmt = $pdo->prepare("
                SELECT 
                    ta.id, ta.date as record_date, COALESCE(ta.time_in, '07:52:00') as record_time, ta.status, COALESCE(ta.remarks, 'On time') as remarks,
                    t.teacher_id as teacher_code, t.full_name as teacher_name, t.grade_level_name as grade_level, COALESCE(t.section_name, 'No section assigned') as section_name, t.subject_name
                FROM `teacher_attendance` ta
                JOIN `teachers` t ON t.id = ta.teacher_id
                WHERE ta.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $updated = $stmt->fetch();
            $initials = $updated ? get_teacher_initials($updated['teacher_name']) : 'TC';

            echo json_encode([
                'status'  => 'success',
                'message' => "Teacher attendance record updated.",
                'record'  => [
                    'id'              => $id,
                    'record_date'     => $record_date,
                    'formatted_date'  => date('M j, Y', strtotime($record_date)),
                    'day_short'       => date('D', strtotime($record_date)),
                    'record_time'     => substr($record_time, 0, 5),
                    'formatted_time'  => date('h:i A', strtotime($record_time)),
                    'teacher_name'    => professor_display_name($updated['teacher_name'] ?? ''),
                    'teacher_code'    => $updated['teacher_code'] ?? '',
                    'student_name'    => professor_display_name($updated['teacher_name'] ?? ''),
                    'student_code'    => $updated['teacher_code'] ?? '',
                    'avatar_initials' => $initials,
                    'grade_level'     => $updated['grade_level'] ?? 'Grade 8',
                    'section_name'    => $updated['section_name'] ?? 'No section assigned',
                    'subject_name'    => $updated['subject_name'] ?? 'General',
                    'status'          => $status,
                    'remarks'         => $remarks !== '' ? $remarks : 'On time'
                ]
            ]);
            exit;
        }

        // 4. Delete Teacher Attendance Record
        if ($ajaxAction === 'delete_record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = intval($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid record ID.']);
                exit;
            }
            $pdo->prepare("DELETE FROM `teacher_attendance` WHERE `id` = ?")->execute([$id]);
            echo json_encode([
                'status'  => 'success',
                'message' => 'Teacher attendance record deleted.'
            ]);
            exit;
        }
            $record_date  = trim($input['record_date'] ?? '2026-09-29');
            $record_time  = trim($input['record_time'] ?? '07:15');
            $student_name = trim($input['student_name'] ?? '');
            $student_code = trim($input['student_code'] ?? '');
            $grade_level  = trim($input['grade_level'] ?? 'Grade 7');
            $section_name = trim($input['section_name'] ?? '');
            $subject_name = trim($input['subject_name'] ?? 'Mathematics');
            $status       = trim($input['status'] ?? 'Present');
            $remarks      = trim($input['remarks'] ?? '-');

        // 5. Bulk Delete Teacher Attendance Records
        if ($ajaxAction === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $ids = $input['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['status' => 'error', 'message' => 'No records selected for deletion.']);
                exit;
            }
            $cleanIds = array_map('intval', $ids);
            $cleanIds = array_filter($cleanIds, fn($id) => $id > 0);
            if (empty($cleanIds)) {
                echo json_encode(['status' => 'error', 'message' => 'No valid IDs provided.']);
                exit;
            }
            $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
            $delStmt = $pdo->prepare("DELETE FROM `teacher_attendance` WHERE `id` IN ($inClause)");
            $delStmt->execute(array_values($cleanIds));

            echo json_encode([
                'status'  => 'success',
                'message' => count($cleanIds) . ' teacher attendance record(s) deleted successfully.'
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

// Fetch all Teacher Attendance records
$recordsStmt = $pdo->query("
    SELECT 
        ta.id,
        ta.teacher_id,
        ta.date as record_date,
        COALESCE(ta.time_in, '07:52:00') as record_time,
        ta.time_out,
        ta.status,
        COALESCE(ta.remarks, 'On time') as remarks,
        t.teacher_id as teacher_code,
        t.full_name as teacher_name,
        t.email as teacher_email,
        t.grade_level_name as grade_level,
        COALESCE(t.section_name, 'No section assigned') as section_name,
        t.subject_name as subject_name
    FROM `teacher_attendance` ta
    JOIN `teachers` t ON t.id = ta.teacher_id
    ORDER BY ta.date DESC, ta.time_in DESC
");
$records = $recordsStmt->fetchAll();

// Calculate initials for each record
$totalTeachersCount = count(array_unique(array_column($records, 'teacher_id')));
$presentCount = 0;
$absentCount = 0;
$lateCount = 0;

foreach ($records as &$rec) {
    $rec['avatar_initials'] = get_teacher_initials($rec['teacher_name']);
    $rec['student_name'] = professor_display_name($rec['teacher_name']);
    $rec['student_code'] = $rec['teacher_code'];

    if ($rec['status'] === 'Present') $presentCount++;
    elseif ($rec['status'] === 'Absent') $absentCount++;
    elseif ($rec['status'] === 'Late') $lateCount++;
}
unset($rec);

$totalRecordsCount = count($records);
$presentPct = $totalRecordsCount > 0 ? round(($presentCount / $totalRecordsCount) * 100, 1) : 0;
$absentPct  = $totalRecordsCount > 0 ? round(($absentCount / $totalRecordsCount) * 100, 1) : 0;
$latePct    = $totalRecordsCount > 0 ? round(($lateCount / $totalRecordsCount) * 100, 1) : 0;

function get_att_grade_class(string $grade): string {
    $g = strtolower(trim($grade));
    if (strpos($g, '7') !== false) return 'att-grade-7';
    if (strpos($g, '8') !== false) return 'att-grade-8';
    if (strpos($g, '9') !== false) return 'att-grade-9';
    if (strpos($g, '10') !== false) return 'att-grade-10';
    return 'att-grade-7';
}

function get_att_section_class(string $section): string {
    $s = strtolower(trim($section));
    if (strpos($s, 'rizal') !== false || strpos($s, 'jupiter') !== false) return 'att-sec-jupiter';
    if (strpos($s, 'bonifacio') !== false || strpos($s, 'mercury') !== false) return 'att-sec-mercury';
    if (strpos($s, 'mabini') !== false || strpos($s, 'neptune') !== false) return 'att-sec-neptune';
    if (strpos($s, 'luna') !== false || strpos($s, 'saturn') !== false) return 'att-sec-saturn';
    return 'att-sec-jupiter';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Attendance Records - ODB Montessori School of Novaliches Attendance System</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --att-sidebar-bg: #0d5b49;
            --att-bg: #eef4f1;
            --att-card: #ffffff;
            --att-border: #dce7e2;
            --att-ink: #112822;
            --att-muted: #537169;
            --att-green: #0d5b49;
            --att-red: #e03e3e;
            --att-amber: #df8b16;
        }

        body {
            background: var(--att-bg);
            color: var(--att-ink);
        }

        /* Sidebar exact match */
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

        /* Topbar exact match */
        .att-topbar {
            height: 68px;
            background: #f8fbfa;
            border-bottom: 1px solid var(--att-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }
        .att-topbar-title {
            font-size: 0.96rem;
            font-weight: 700;
            color: #154035;
        }
        .att-topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .att-notif-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid var(--att-border);
            background: #ffffff;
            color: #164035;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .att-logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 10px;
            border: 1px solid var(--att-border);
            background: #ffffff;
            color: #163b31;
            font-size: 0.84rem;
            font-weight: 700;
            text-decoration: none;
        }
        .att-logout-btn:hover {
            background: #eef5f2;
        }
        .att-profile-badge {
            display: flex;
            align-items: center;
            gap: 9px;
            padding-left: 4px;
        }
        .att-profile-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #d2ebe1;
            color: #0d5243;
            font-size: 0.78rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .att-profile-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .att-profile-info strong {
            font-size: 0.83rem;
            color: var(--att-ink);
        }
        .att-profile-info small {
            font-size: 0.72rem;
            color: var(--att-muted);
        }

        /* Main Content Wrap */
        .att-content {
            padding: 24px 30px 36px;
        }
        .att-page-title {
            font-size: 1.95rem;
            font-weight: 800;
            color: #0f392f;
            letter-spacing: -0.02em;
            margin: 0 0 4px 0;
        }
        .att-page-sub {
            font-size: 0.9rem;
            color: #54726b;
            margin: 0 0 20px 0;
        }

        /* 4 Summary Metric Cards with Progress Bars */
        .att-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 18px;
        }
        .att-kpi-card {
            background: #ffffff;
            border: 1px solid var(--att-border);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            box-shadow: 0 2px 6px rgba(15, 45, 38, 0.025);
        }
        .att-kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .att-kpi-icon.green-soft {
            background: #e2f5ed;
            color: #0e7c55;
        }
        .att-kpi-icon.green-check {
            background: #e1f7ec;
            color: #109665;
        }
        .att-kpi-icon.red-cross {
            background: #fde8e8;
            color: #e03e3e;
        }
        .att-kpi-icon.amber-clock {
            background: #fef1dc;
            color: #df8b16;
        }
        .att-kpi-body {
            flex: 1;
            min-width: 0;
        }
        .att-kpi-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #55726a;
            margin-bottom: 2px;
        }
        .att-kpi-val {
            font-size: 1.75rem;
            font-weight: 800;
            color: #12332b;
            line-height: 1.15;
            margin-bottom: 8px;
        }
        .att-kpi-trend {
            font-size: 0.76rem;
            color: #637e77;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .att-kpi-trend strong {
            color: #0f9d68;
            font-weight: 700;
        }
        .att-kpi-bar-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .att-progress-track {
            flex: 1;
            height: 6px;
            background: #edf3f0;
            border-radius: 999px;
            overflow: hidden;
        }
        .att-progress-fill {
            height: 100%;
            border-radius: 999px;
        }
        .att-progress-fill.green {
            background: #0f8a5f;
        }
        .att-progress-fill.red {
            background: #e03e3e;
        }
        .att-progress-fill.amber {
            background: #e6961d;
        }
        .att-kpi-pct {
            font-size: 0.76rem;
            font-weight: 700;
            color: #4f6c65;
            flex-shrink: 0;
        }

        /* Filter Bar Panel */
        .att-filter-panel {
            background: #ffffff;
            border: 1px solid var(--att-border);
            border-radius: 16px;
            padding: 14px 18px;
            margin-bottom: 18px;
            display: grid;
            grid-template-columns: 1.25fr 1fr 1fr 1fr auto;
            gap: 14px;
            align-items: end;
            box-shadow: 0 2px 6px rgba(15, 45, 38, 0.02);
        }
        .att-filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .att-filter-label {
            font-size: 0.76rem;
            font-weight: 700;
            color: #2f5148;
        }
        .att-select-box {
            position: relative;
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #d8e5e0;
            border-radius: 10px;
            height: 40px;
            padding: 0 12px;
        }
        .att-select-box i.cal-icon {
            color: #164f41;
            font-size: 0.88rem;
            margin-right: 9px;
        }
        .att-select-box select {
            border: none;
            outline: none;
            width: 100%;
            background: transparent;
            font-size: 0.84rem;
            font-weight: 600;
            color: #1b3b33;
            cursor: pointer;
            appearance: none;
            padding-right: 18px;
        }
        .att-select-box i.chev {
            position: absolute;
            right: 12px;
            font-size: 0.7rem;
            color: #67827b;
            pointer-events: none;
        }
        .att-filter-btns {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-apply-filter {
            height: 40px;
            padding: 0 16px;
            border-radius: 10px;
            border: none;
            background: #0d4b3e;
            color: #ffffff;
            font-size: 0.84rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-apply-filter:hover {
            background: #08372d;
        }
        .btn-reset-filter {
            height: 40px;
            padding: 0 14px;
            border-radius: 10px;
            border: 1px solid #d5e3de;
            background: #ffffff;
            color: #22443b;
            font-size: 0.84rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-reset-filter:hover {
            background: #f1f7f5;
        }

        /* Attendance Records Main Table Card */
        .att-table-card {
            background: #ffffff;
            border: 1px solid var(--att-border);
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(15, 45, 38, 0.025);
            overflow: visible;
        }
        .att-search-bar {
            padding: 16px 20px;
            border-bottom: 1px solid var(--att-border);
        }
        .att-search-bar label {
            display: block;
            margin-bottom: 8px;
            color: #12332b;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .att-search-bar input {
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            padding: 12px 14px;
            border: 1px solid var(--att-border);
            border-radius: 10px;
            background: #fff;
            color: #12332b;
            font: inherit;
            font-size: 1rem;
        }
        .att-search-bar input:focus-visible {
            outline: 2px solid #0e7c55;
            outline-offset: 2px;
        }
        .att-table-card-header {
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #edf3f0;
            flex-wrap: wrap;
            gap: 12px;
        }
        .att-table-card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.02rem;
            font-weight: 800;
            color: #12382f;
        }
        .att-table-card-title i {
            color: #0e5847;
            font-size: 1.05rem;
        }
        .att-table-card-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-att-export {
            height: 36px;
            padding: 0 14px;
            border-radius: 9px;
            border: 1px solid #d5e3de;
            background: #ffffff;
            color: #1c3f36;
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }
        .btn-att-export:hover {
            background: #f0f6f4;
        }
        .btn-att-summary {
            height: 36px;
            padding: 0 15px;
            border-radius: 9px;
            border: none;
            background: #0d4b3e;
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }
        .btn-att-summary:hover {
            background: #08372d;
        }

        /* Table Styles */
        .att-table {
            width: 100%;
            border-collapse: collapse;
        }
        .att-table thead th {
            background: #f5f9f7;
            color: #3a5c53;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 13px 16px;
            text-align: left;
            border-bottom: 1px solid var(--att-border);
        }
        .att-table thead th:first-child {
            width: 44px;
            text-align: center;
        }
        .att-table tbody tr {
            border-bottom: 1px solid #edf3f0;
            transition: background 0.12s ease;
        }
        .att-table tbody tr:hover {
            background: #f9fcfa;
        }
        .att-table tbody td {
            padding: 13px 16px;
            vertical-align: middle;
            font-size: 0.85rem;
            color: #1f3e36;
        }
        .att-table tbody td:first-child {
            text-align: center;
        }
        .att-checkbox {
            width: 16px;
            height: 16px;
            accent-color: #0d4b3e;
            cursor: pointer;
        }

        /* Date / Time Cell */
        .att-datetime-cell {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }
        .att-datetime-top {
            font-weight: 600;
            color: #214038;
            font-size: 0.84rem;
        }
        .att-datetime-day {
            font-size: 0.76rem;
            color: #6b857e;
        }

        /* Student Cell */
        .att-student-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .att-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #0d4b3e;
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .att-student-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.25;
        }
        .att-student-name {
            font-weight: 700;
            color: #15352d;
            font-size: 0.87rem;
        }
        .att-student-code {
            font-size: 0.75rem;
            color: #6c867f;
        }

        /* Grade Level Pills with Graduation Cap Icon */
        .att-grade-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
        }
        .att-grade-7 {
            background: #e4f0ff;
            color: #2166c9;
        }
        .att-grade-8 {
            background: #dff6ec;
            color: #117d56;
        }
        .att-grade-9 {
            background: #fef0d8;
            color: #c66d11;
        }
        .att-grade-10 {
            background: #f0e6ff;
            color: #7c3aed;
        }

        /* Section Pills */
        .att-sec-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 13px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
        }
        .att-sec-jupiter {
            background: #e5f1ff;
            color: #2468c8;
        }
        .att-sec-mercury {
            background: #dff6ec;
            color: #127e57;
        }
        .att-sec-neptune {
            background: #f2e8ff;
            color: #7e3bed;
        }
        .att-sec-saturn {
            background: #fef1dc;
            color: #c87114;
        }

        /* Status Pills (Interactive) */
        .att-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.77rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: filter 0.14s ease;
        }
        .att-status-pill:hover {
            filter: brightness(0.96);
        }
        .att-status-pill.present {
            background: #dff6ec;
            color: #0e7c56;
        }
        .att-status-pill.absent {
            background: #fde8e8;
            color: #d93838;
        }
        .att-status-pill.late {
            background: #fef1dc;
            color: #c97312;
        }

        /* Row Action Buttons (Eye, Pencil, Red Trash) */
        .att-actions-row {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .att-act-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid #e1ece8;
            background: #f7faf9;
            color: #20453c;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.14s ease;
        }
        .att-act-btn:hover {
            background: #e7f2ee;
            color: #0d4b3e;
        }
        .att-act-btn.delete {
            background: #fdecec;
            border-color: #f9d2d2;
            color: #e03e3e;
        }
        .att-act-btn.delete:hover {
            background: #fbd8d8;
            color: #b91c1c;
        }

        /* Footer & Pagination */
        .att-table-footer {
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #5d7972;
            font-size: 0.82rem;
        }
        .att-pagination {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .att-page-btn {
            min-width: 30px;
            height: 30px;
            padding: 0 8px;
            border-radius: 7px;
            border: 1px solid #dce8e4;
            background: #ffffff;
            color: #36574f;
            font-size: 0.79rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .att-page-btn:hover {
            background: #eff6f3;
        }
        .att-page-btn.active {
            background: #0d4b3e;
            border-color: #0d4b3e;
            color: #ffffff;
        }

        /* Modals & Dropdowns */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 38, 32, 0.45);
            backdrop-filter: blur(3px);
            display: none !important;
            align-items: center;
            justify-content: center;
            z-index: 1200;
            padding: 20px;
        }
        .modal-backdrop.open {
            display: flex !important;
        }
        .modal-dialog {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--att-border);
            box-shadow: 0 24px 56px rgba(10, 42, 34, 0.22);
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
        }
        .action-dropdown-wrapper {
            position: relative;
            display: inline-block;
        }
        .action-dropdown-menu {
            position: absolute;
            left: 0;
            top: calc(100% + 6px);
            min-width: 145px;
            background: #ffffff;
            border: 1px solid var(--att-border);
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(15, 45, 38, 0.15);
            padding: 6px;
            z-index: 950;
            display: none !important;
        }
        .action-dropdown-menu.show {
            display: block !important;
        }

        @media (max-width: 1150px) {
            .att-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .att-filter-panel {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 640px) {
            .att-table-footer {
                flex-direction: column !important;
                align-items: center !important;
                gap: 12px !important;
                text-align: center !important;
                padding: 12px 14px !important;
            }
            .att-pagination {
                flex-wrap: wrap !important;
                justify-content: center !important;
                gap: 4px !important;
            }
            .att-filter-panel {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</head>
<body>

<div class="app-shell">
    <!-- SIDEBAR NAVIGATION -->
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
            <a class="nav-item" href="subject_editing.php">
                <i class="fa-solid fa-book-open"></i>
                <span>Subjects &amp; Sections</span>
            </a>
            <a class="nav-item active" href="attendance.php">
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
        <header class="att-topbar">
            <div class="att-topbar-title">Attendance</div>
            <div class="att-topbar-right">
                <button type="button" class="att-notif-btn" aria-label="Notifications">
                    <i class="fa-solid fa-bell"></i>
                </button>
                <a href="../logout.php" class="att-logout-btn">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                </a>
                <div class="att-profile-badge">
                    <div class="att-profile-avatar">EM</div>
                    <div class="att-profile-info">
                        <strong><?= htmlspecialchars($user['name'] ?? 'System Administrator') ?></strong>
                        <small>Administrator</small>
                    </div>
                    <i class="fa-solid fa-chevron-down" style="font-size:0.68rem;color:#607a73;margin-left:2px;"></i>
                </div>
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <div class="att-content">
            <h1 class="att-page-title">Teacher Attendance Records</h1>
            <p class="att-page-sub">View and manage daily attendance logs, status updates, and check-ins for all faculty teachers.</p>

            <!-- Dynamic AJAX Toast -->
            <div id="attAjaxAlert" class="alert" style="display:none;margin-bottom:16px;"></div>

            <!-- 4 TOP SUMMARY METRIC CARDS -->
            <div class="att-kpi-grid">
                <!-- Card 1: Total Teachers -->
                <div class="att-kpi-card">
                    <div class="att-kpi-icon green-soft">
                        <i class="fa-solid fa-chalkboard-user"></i>
                    </div>
                    <div class="att-kpi-body">
                        <div class="att-kpi-label">Total Teachers</div>
                        <div class="att-kpi-val" id="kpiTotalStudents"><?= $totalTeachersCount ?></div>
                        <div class="att-kpi-trend">
                            <i class="fa-solid fa-clipboard-list" style="color:#0f9d68;font-size:0.7rem;"></i>
                            <strong><?= $totalRecordsCount ?></strong> total logs
                        </div>
                    </div>
                </div>

                <!-- Card 2: Present -->
                <div class="att-kpi-card">
                    <div class="att-kpi-icon green-check">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <div class="att-kpi-body">
                        <div class="att-kpi-label">Present</div>
                        <div class="att-kpi-val" id="kpiPresentCount"><?= $presentCount ?></div>
                        <div class="att-kpi-bar-row">
                            <div class="att-progress-track">
                                <div class="att-progress-fill green" id="kpiPresentBar" style="width: <?= $presentPct ?>%;"></div>
                            </div>
                            <span class="att-kpi-pct" id="kpiPresentPct"><?= $presentPct ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Absent -->
                <div class="att-kpi-card">
                    <div class="att-kpi-icon red-cross">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                    <div class="att-kpi-body">
                        <div class="att-kpi-label">Absent</div>
                        <div class="att-kpi-val" id="kpiAbsentCount"><?= $absentCount ?></div>
                        <div class="att-kpi-bar-row">
                            <div class="att-progress-track">
                                <div class="att-progress-fill red" id="kpiAbsentBar" style="width: <?= $absentPct ?>%;"></div>
                            </div>
                            <span class="att-kpi-pct" id="kpiAbsentPct"><?= $absentPct ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Late -->
                <div class="att-kpi-card">
                    <div class="att-kpi-icon amber-clock">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="att-kpi-body">
                        <div class="att-kpi-label">Late</div>
                        <div class="att-kpi-val" id="kpiLateCount"><?= $lateCount ?></div>
                        <div class="att-kpi-bar-row">
                            <div class="att-progress-track">
                                <div class="att-progress-fill amber" id="kpiLateBar" style="width: <?= $latePct ?>%;"></div>
                            </div>
                            <span class="att-kpi-pct" id="kpiLatePct"><?= $latePct ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="att-filter-panel">
                <div class="att-filter-group">
                    <div class="att-select-box">
                        <i class="fa-regular fa-calendar-days cal-icon"></i>
                        <select id="filterDateRange">
                            <option value="all">Sep 1, 2026 &ndash; Sep 30, 2026</option>
                            <option value="2026-09-29">Sep 29, 2026 (Today)</option>
                            <option value="month">This Month</option>
                        </select>
                        <i class="fa-solid fa-chevron-down chev"></i>
                    </div>
                </div>

                <div class="att-filter-group">
                    <label class="att-filter-label">Grade Level</label>
                    <div class="att-select-box">
                        <select id="filterGrade">
                            <option value="">All Grades</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                        </select>
                        <i class="fa-solid fa-chevron-down chev"></i>
                    </div>
                </div>

                <div class="att-filter-group">
                    <label class="att-filter-label">Section</label>
                    <div class="att-select-box">
                        <select id="filterSection">
                            <option value="">All Sections</option>
                            <?php foreach ($studentSectionsByGrade as $sectionNames): foreach ($sectionNames as $sectionName): ?>
                            <option value="<?= htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sectionName) ?></option>
                            <?php endforeach; endforeach; ?>
                        </select>
                        <i class="fa-solid fa-chevron-down chev"></i>
                    </div>
                </div>

                <div class="att-filter-group">
                    <label class="att-filter-label">Subject</label>
                    <div class="att-select-box">
                        <select id="filterSubject">
                            <option value="">All Subjects</option>
                            <option value="Mathematics">Mathematics</option>
                            <option value="English">English</option>
                            <option value="Computer">Computer</option>
                            <option value="Science">Science</option>
                            <option value="Filipino">Filipino</option>
                            <option value="TLE">TLE</option>
                            <option value="Araling Panlipunan">Araling Panlipunan</option>
                            <option value="MAPEH">MAPEH</option>
                        </select>
                        <i class="fa-solid fa-chevron-down chev"></i>
                    </div>
                </div>

                <div class="att-filter-btns">
                    <button type="button" class="btn-apply-filter" onclick="applyAttendanceFilters()">
                        <i class="fa-solid fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn-reset-filter" onclick="resetAttendanceFilters()">
                        <i class="fa-solid fa-rotate-right"></i> Reset
                    </button>
                </div>
            </div>

            <!-- ATTENDANCE RECORDS TABLE CARD -->
            <div class="att-table-card">
                <div class="att-table-card-header">
                    <div class="att-table-card-title">
                        <i class="fa-solid fa-chalkboard-user"></i>
                        <span>Teacher Attendance Records</span>
                    </div>
                    <div class="att-table-card-actions">
                        <button type="button" class="btn-att-export" onclick="exportAttendanceCSV()">
                            <i class="fa-solid fa-download"></i> Export CSV
                        </button>
                        <button type="button" class="btn-att-summary" onclick="openAttendanceSummaryModal()">
                            <i class="fa-solid fa-chart-line"></i> View Summary
                        </button>
                    </div>
                </div>

                <div class="att-search-bar">
                    <label for="teacherAttendanceSearch">Search teacher attendance</label>
                    <input type="search" id="teacherAttendanceSearch"
                           placeholder="Search teacher name, ID, subject or section..."
                           aria-controls="attendanceTable" oninput="applyAttendanceFilters()">
                </div>

                <div style="overflow-x:auto;">
                    <table class="att-table" id="attendanceTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="att-checkbox" onclick="toggleSelectAllAtt(this)"></th>
                                <th>DATE / TIME</th>
                                <th>TEACHER</th>
                                <th>GRADE LEVEL</th>
                                <th>SECTION</th>
                                <th>SUBJECT</th>
                                <th>STATUS</th>
                                <th>REMARKS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <?php foreach ($records as $rec):
                                $rId        = (int)$rec['id'];
                                $fDate      = date('M j, Y', strtotime($rec['record_date']));
                                $fTime      = date('h:i A', strtotime($rec['record_time']));
                                $fDay       = date('D', strtotime($rec['record_date']));
                                $gClass     = get_att_grade_class($rec['grade_level']);
                                $sClass     = get_att_section_class($rec['section_name']);
                                $stVal      = $rec['status'];
                                $stClass    = strtolower($stVal);
                                $stIcon     = $stVal === 'Present' ? 'fa-solid fa-circle-check' : ($stVal === 'Absent' ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-clock');
                            ?>
                                <tr id="att-rec-row-<?= $rId ?>"
                                    class="att-data-row"
                                    data-id="<?= $rId ?>"
                                    data-date="<?= htmlspecialchars($rec['record_date'], ENT_QUOTES) ?>"
                                    data-time="<?= htmlspecialchars(substr($rec['record_time'], 0, 5), ENT_QUOTES) ?>"
                                    data-student="<?= htmlspecialchars($rec['student_name'], ENT_QUOTES) ?>"
                                    data-code="<?= htmlspecialchars($rec['student_code'], ENT_QUOTES) ?>"
                                    data-initials="<?= htmlspecialchars($rec['avatar_initials'], ENT_QUOTES) ?>"
                                    data-grade="<?= htmlspecialchars($rec['grade_level'], ENT_QUOTES) ?>"
                                    data-section="<?= htmlspecialchars($rec['section_name'], ENT_QUOTES) ?>"
                                    data-subject="<?= htmlspecialchars($rec['subject_name'], ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars($stVal, ENT_QUOTES) ?>"
                                    data-remarks="<?= htmlspecialchars($rec['remarks'], ENT_QUOTES) ?>">
                                    <td><input type="checkbox" class="att-checkbox att-row-cb" value="<?= $rId ?>"></td>
                                    <td>
                                        <div class="att-datetime-cell">
                                            <span class="att-datetime-top col-datetime"><?= htmlspecialchars($fDate) ?> &bull; <?= htmlspecialchars($fTime) ?></span>
                                            <span class="att-datetime-day col-day"><?= htmlspecialchars($fDay) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="att-student-cell">
                                            <div class="att-avatar col-initials"><?= htmlspecialchars($rec['avatar_initials']) ?></div>
                                            <div class="att-student-meta">
                                                <span class="att-student-name col-student"><?= htmlspecialchars($rec['student_name']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="col-grade">
                                        <span class="att-grade-pill <?= $gClass ?>">
                                            <i class="fa-solid fa-graduation-cap"></i>
                                            <span class="grade-label"><?= htmlspecialchars($rec['grade_level']) ?></span>
                                        </span>
                                    </td>
                                    <td class="col-section">
                                        <span class="att-sec-pill <?= $sClass ?>"><?= htmlspecialchars($rec['section_name']) ?></span>
                                    </td>
                                    <td class="col-subject"><?= htmlspecialchars($rec['subject_name']) ?></td>
                                    <td>
                                        <div class="action-dropdown-wrapper">
                                            <button type="button"
                                                    id="att-status-btn-<?= $rId ?>"
                                                    class="att-status-pill <?= $stClass ?>"
                                                    title="Click to update status"
                                                    onclick="toggleAttRowStatusDropdown(event, <?= $rId ?>)">
                                                <i id="att-status-icon-<?= $rId ?>" class="<?= $stIcon ?>"></i>
                                                <span id="att-status-text-<?= $rId ?>"><?= htmlspecialchars($stVal) ?></span>
                                            </button>
                                            <div id="att-status-dropdown-<?= $rId ?>" class="action-dropdown-menu">
                                                <button type="button" class="action-dropdown-item" onclick="quickChangeAttRecordStatus(<?= $rId ?>, 'Present')">
                                                    <span class="att-status-pill present" style="padding:3px 10px;font-size:0.74rem;"><i class="fa-solid fa-circle-check"></i> Present</span>
                                                </button>
                                                <button type="button" class="action-dropdown-item" onclick="quickChangeAttRecordStatus(<?= $rId ?>, 'Absent')">
                                                    <span class="att-status-pill absent" style="padding:3px 10px;font-size:0.74rem;"><i class="fa-solid fa-circle-xmark"></i> Absent</span>
                                                </button>
                                                <button type="button" class="action-dropdown-item" onclick="quickChangeAttRecordStatus(<?= $rId ?>, 'Late')">
                                                    <span class="att-status-pill late" style="padding:3px 10px;font-size:0.74rem;"><i class="fa-solid fa-clock"></i> Late</span>
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="col-remarks" id="att-remarks-cell-<?= $rId ?>" style="color:#526e67;"><?= htmlspecialchars($rec['remarks']) ?></td>
                                    <td>
                                        <div class="att-actions-row">
                                            <button type="button" class="att-act-btn" title="View Attendance Details" onclick="openViewAttModal(<?= $rId ?>)">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <button type="button" class="att-act-btn" title="Edit Attendance Record" onclick="openEditAttModal(<?= $rId ?>)">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <button type="button" class="att-act-btn delete" title="Delete Record" onclick="deleteAttRecord(<?= $rId ?>)">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="attSearchEmpty" hidden>
                                <td colspan="9" style="text-align:center;padding:24px;">No teacher attendance records match your search or filters.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- FOOTER & PAGINATION -->
                <div class="att-table-footer">
                    <div id="attFooterSummary" role="status" aria-live="polite">Showing 1 to <?= count($records) ?> of <?= $totalRecordsCount ?> records</div>
                    <div class="att-pagination" id="attPaginationControls"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- =========================================================================
     MODAL 1: VIEW ATTENDANCE RECORD DETAILS
     ========================================================================= -->
<div id="viewAttModal" class="modal-backdrop" onclick="if(event.target === this) closeViewAttModal()">
    <div class="modal-dialog" style="max-width: 520px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-eye" style="color:#0d4b3e;margin-right:8px;"></i> Teacher Attendance Details</div>
            <button type="button" class="modal-close" onclick="closeViewAttModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="viewAttModalBody"></div>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: EDIT ATTENDANCE RECORD MODAL
     ========================================================================= -->
<div id="editAttModal" class="modal-backdrop" onclick="if(event.target === this) closeEditAttModal()">
    <div class="modal-dialog" style="max-width: 560px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-pen" style="color:#0d4b3e;margin-right:8px;"></i> Edit Teacher Attendance Record</div>
            <button type="button" class="modal-close" onclick="closeEditAttModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="editAttForm" onsubmit="submitEditAttForm(event)">
            <input type="hidden" id="edit_att_id">
            <div class="grid-2" style="gap:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Teacher Name *</label>
                    <input type="text" id="edit_att_student" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Teacher ID *</label>
                    <input type="text" id="edit_att_code" class="form-control" required>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Date *</label>
                    <input type="date" id="edit_att_date" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Time *</label>
                    <input type="time" id="edit_att_time" class="form-control" required>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Grade Level *</label>
                    <select id="edit_att_grade" class="form-control" required data-student-grade>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Section *</label>
                    <select id="edit_att_section" class="form-control" required data-student-section></select>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Subject *</label>
                    <select id="edit_att_subject" class="form-control" required>
                        <option value="Mathematics">Mathematics</option>
                        <option value="English">English</option>
                        <option value="Computer">Computer</option>
                        <option value="Science">Science</option>
                        <option value="Filipino">Filipino</option>
                        <option value="TLE">TLE</option>
                        <option value="Araling Panlipunan">Araling Panlipunan</option>
                        <option value="MAPEH">MAPEH</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Status *</label>
                    <select id="edit_att_status" class="form-control" required>
                        <option value="Present">Present</option>
                        <option value="Absent">Absent</option>
                        <option value="Late">Late</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top:14px;margin-bottom:0;">
                <label class="form-label">Remarks</label>
                <input type="text" id="edit_att_remarks" class="form-control" placeholder="e.g. -, No excuse, 5 mins late, Sick leave">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:14px;border-top:1px solid var(--att-border);">
                <button type="button" class="secondary-btn" onclick="closeEditAttModal()">Cancel</button>
                <button type="submit" id="editAttSubmitBtn" class="primary-btn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 3: ATTENDANCE SUMMARY MODAL (Opened by "View Summary")
     ========================================================================= -->
<div id="summaryAttModal" class="modal-backdrop" onclick="if(event.target === this) closeAttendanceSummaryModal()">
    <div class="modal-dialog" style="max-width: 540px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-chart-pie" style="color:#0d4b3e;margin-right:8px;"></i> Attendance Overview Summary</div>
            <button type="button" class="modal-close" onclick="closeAttendanceSummaryModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="summaryAttModalBody"></div>
    </div>
</div>

<script>
function showAttAlert(message, type = 'success') {
    const box = document.getElementById('attAjaxAlert');
    box.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-danger');
    box.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i><span>${message}</span>`;
    box.style.display = 'flex';
    setTimeout(() => { box.style.display = 'none'; }, 4000);
}

function closeAllAttMenus() {
    document.querySelectorAll('.action-dropdown-menu.show').forEach(m => m.classList.remove('show'));
}
document.addEventListener('click', () => closeAllAttMenus());

function toggleSelectAllAtt(master) {
    document.querySelectorAll('#attendanceTableBody tr.att-data-row').forEach(row => {
        if (row.style.display !== 'none') {
            const cb = row.querySelector('.att-row-cb');
            if (cb) cb.checked = master.checked;
        }
    });
}

let currentAttPage = 1;
const attPageSize = 10;
let matchingAttRows = [];

function renderAttPagination() {
    const totalMatching = matchingAttRows.length;
    const totalPages = Math.ceil(totalMatching / attPageSize) || 1;
    if (currentAttPage > totalPages) currentAttPage = totalPages;
    if (currentAttPage < 1) currentAttPage = 1;

    const allRows = document.querySelectorAll('#attendanceTableBody tr.att-data-row');
    allRows.forEach(r => r.style.display = 'none');
    document.getElementById('attSearchEmpty').hidden = totalMatching !== 0;

    const startIndex = (currentAttPage - 1) * attPageSize;
    const endIndex = Math.min(startIndex + attPageSize, totalMatching);

    for (let i = startIndex; i < endIndex; i++) {
        if (matchingAttRows[i]) {
            matchingAttRows[i].style.display = '';
        }
    }

    const summaryEl = document.getElementById('attFooterSummary');
    if (summaryEl) {
        if (totalMatching === 0) {
            summaryEl.textContent = 'Showing 0 to 0 of 0 records';
        } else {
            summaryEl.textContent = `Showing ${startIndex + 1} to ${endIndex} of ${totalMatching} records`;
        }
    }

    const container = document.getElementById('attPaginationControls');
    if (!container) return;
    container.innerHTML = '';

    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'att-page-btn';
    prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
    prevBtn.setAttribute('aria-label', 'Previous Page');
    prevBtn.disabled = currentAttPage <= 1;
    prevBtn.onclick = () => {
        if (currentAttPage > 1) {
            currentAttPage--;
            renderAttPagination();
        }
    };
    container.appendChild(prevBtn);

    function createPageBtn(p) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'att-page-btn' + (p === currentAttPage ? ' active' : '');
        btn.textContent = p;
        btn.onclick = () => {
            currentAttPage = p;
            renderAttPagination();
        };
        return btn;
    }

    function createDots() {
        const span = document.createElement('span');
        span.className = 'att-page-dots';
        span.style.color = '#5d7972';
        span.style.padding = '0 4px';
        span.style.fontSize = '0.82rem';
        span.textContent = '...';
        return span;
    }

    if (totalPages <= 7) {
        for (let p = 1; p <= totalPages; p++) {
            container.appendChild(createPageBtn(p));
        }
    } else {
        container.appendChild(createPageBtn(1));
        if (currentAttPage > 3) {
            container.appendChild(createDots());
        }
        const start = Math.max(2, currentAttPage - 1);
        const end = Math.min(totalPages - 1, currentAttPage + 1);
        for (let p = start; p <= end; p++) {
            container.appendChild(createPageBtn(p));
        }
        if (currentAttPage < totalPages - 2) {
            container.appendChild(createDots());
        }
        container.appendChild(createPageBtn(totalPages));
    }

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'att-page-btn';
    nextBtn.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
    nextBtn.setAttribute('aria-label', 'Next Page');
    nextBtn.disabled = currentAttPage >= totalPages;
    nextBtn.onclick = () => {
        if (currentAttPage < totalPages) {
            currentAttPage++;
            renderAttPagination();
        }
    };
    container.appendChild(nextBtn);
}

// Search all attendance records before applying pagination.
function applyAttendanceFilters() {
    const grade = document.getElementById('filterGrade').value;
    const section = document.getElementById('filterSection').value;
    const subject = document.getElementById('filterSubject').value;
    const searchTerms = document.getElementById('teacherAttendanceSearch').value
        .trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);

    const rows = Array.from(document.querySelectorAll('#attendanceTableBody tr.att-data-row'));
    matchingAttRows = [];

    rows.forEach(r => {
        const matchGrade = !grade || r.dataset.grade === grade;
        const matchSec = !section || r.dataset.section === section;
        const matchSub = !subject || r.dataset.subject === subject;
        const searchableText = [r.dataset.student, r.dataset.code, r.dataset.subject,
            r.dataset.grade, r.dataset.section].join(' ').toLocaleLowerCase();
        const matchSearch = searchTerms.every(term => searchableText.includes(term));

        if (matchGrade && matchSec && matchSub && matchSearch) {
            matchingAttRows.push(r);
        }
    });

    currentAttPage = 1;
    renderAttPagination();
}

function resetAttendanceFilters() {
    document.getElementById('teacherAttendanceSearch').value = '';
    document.getElementById('filterDateRange').value = 'all';
    document.getElementById('filterGrade').value = '';
    document.getElementById('filterSection').value = '';
    document.getElementById('filterSubject').value = '';
    applyAttendanceFilters();
}

// Interactive Status Pill Dropdown
function toggleAttRowStatusDropdown(event, id) {
    event.stopPropagation();
    const menu = document.getElementById('att-status-dropdown-' + id);
    if (!menu) return;
    const isOpen = menu.classList.contains('show');
    closeAllAttMenus();
    if (!isOpen) menu.classList.add('show');
}

async function quickChangeAttRecordStatus(id, newStatus) {
    closeAllAttMenus();
    try {
        const res = await fetch('attendance.php?ajax_action=update_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, status: newStatus })
        });
        const data = await res.json();
        if (data.status === 'success') {
            const row = document.getElementById('att-rec-row-' + id);
            if (row) {
                row.dataset.status = data.new_status;
                row.dataset.remarks = data.remarks;
            }
            const btn = document.getElementById('att-status-btn-' + id);
            const icon = document.getElementById('att-status-icon-' + id);
            const txt = document.getElementById('att-status-text-' + id);
            const rem = document.getElementById('att-remarks-cell-' + id);

            const stLower = data.new_status.toLowerCase();
            if (btn) btn.className = 'att-status-pill ' + stLower;
            if (icon) {
                icon.className = data.new_status === 'Present'
                    ? 'fa-solid fa-circle-check'
                    : (data.new_status === 'Absent' ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-clock');
            }
            if (txt) txt.textContent = data.new_status;
            if (rem) rem.textContent = data.remarks;

            showAttAlert(data.message, 'success');
        } else {
            showAttAlert(data.message || 'Could not update status.', 'error');
        }
    } catch (err) {
        showAttAlert('Network error while updating status.', 'error');
    }
}

// View Modal
function openViewAttModal(id) {
    const r = document.getElementById('att-rec-row-' + id);
    if (!r) return;
    const stLower = (r.dataset.status || 'Present').toLowerCase();
    const stIcon = r.dataset.status === 'Present'
        ? 'fa-solid fa-circle-check'
        : (r.dataset.status === 'Absent' ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-clock');

    document.getElementById('viewAttModalBody').innerHTML = `
        <div style="display:flex;align-items:center;gap:14px;padding:16px;background:#f4f9f7;border:1px solid var(--att-border);border-radius:14px;margin-bottom:16px;">
            <div class="att-avatar" style="width:48px;height:48px;font-size:0.95rem;">${r.dataset.initials}</div>
            <div style="flex:1;">
                <div style="font-size:1.1rem;font-weight:800;color:#12332b;">${r.dataset.student}</div>
                <div style="font-size:0.82rem;color:#5d7972;">${r.dataset.code}</div>
            </div>
            <span class="att-status-pill ${stLower}">
                <i class="${stIcon}"></i> ${r.dataset.status}
            </span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:0.88rem;">
            <div style="padding:12px;border:1px solid var(--att-border);border-radius:10px;">
                <small style="color:#607a73;display:block;">Date &amp; Time</small>
                <strong>${r.querySelector('.col-datetime').textContent}</strong>
            </div>
            <div style="padding:12px;border:1px solid var(--att-border);border-radius:10px;">
                <small style="color:#607a73;display:block;">Grade &amp; Section</small>
                <strong>${r.dataset.grade} &bull; ${r.dataset.section}</strong>
            </div>
            <div style="padding:12px;border:1px solid var(--att-border);border-radius:10px;">
                <small style="color:#607a73;display:block;">Subject</small>
                <strong>${r.dataset.subject}</strong>
            </div>
            <div style="padding:12px;border:1px solid var(--att-border);border-radius:10px;">
                <small style="color:#607a73;display:block;">Remarks</small>
                <strong>${r.dataset.remarks}</strong>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid var(--att-border);">
            <button type="button" class="secondary-btn" onclick="closeViewAttModal(); openEditAttModal(${id});">
                <i class="fa-solid fa-pen"></i> Edit Record
            </button>
            <button type="button" class="primary-btn" onclick="closeViewAttModal()">Close</button>
        </div>
    `;
    document.getElementById('viewAttModal').classList.add('open');
}

function closeViewAttModal() {
    document.getElementById('viewAttModal').classList.remove('open');
}

// Edit Modal
function openEditAttModal(id) {
    const r = document.getElementById('att-rec-row-' + id);
    if (!r) return;

    document.getElementById('edit_att_id').value = id;
    document.getElementById('edit_att_student').value = r.dataset.student || '';
    document.getElementById('edit_att_code').value = r.dataset.code || '';
    document.getElementById('edit_att_date').value = r.dataset.date || '2026-09-29';
    document.getElementById('edit_att_time').value = r.dataset.time || '07:15';
    document.getElementById('edit_att_grade').value = r.dataset.grade || '';
    document.getElementById('edit_att_grade').dispatchEvent(new Event('change'));
    document.getElementById('edit_att_section').value = r.dataset.section || '';
    document.getElementById('edit_att_subject').value = r.dataset.subject || 'Mathematics';
    document.getElementById('edit_att_status').value = r.dataset.status || 'Present';
    document.getElementById('edit_att_remarks').value = r.dataset.remarks || '-';

    document.getElementById('editAttModal').classList.add('open');
}

function closeEditAttModal() {
    document.getElementById('editAttModal').classList.remove('open');
}

function getAttGradeClassJS(g) {
    const s = (g || '').toLowerCase();
    if (s.includes('7')) return 'att-grade-7';
    if (s.includes('8')) return 'att-grade-8';
    if (s.includes('9')) return 'att-grade-9';
    if (s.includes('10')) return 'att-grade-10';
    return 'att-grade-7';
}

function getAttSecClassJS(sec) {
    const s = (sec || '').toLowerCase();
    if (s.includes('jupiter')) return 'att-sec-jupiter';
    if (s.includes('mercury')) return 'att-sec-mercury';
    if (s.includes('neptune')) return 'att-sec-neptune';
    if (s.includes('saturn')) return 'att-sec-saturn';
    return 'att-sec-jupiter';
}

async function submitEditAttForm(e) {
    e.preventDefault();
    const btn = document.getElementById('editAttSubmitBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    const payload = {
        id: document.getElementById('edit_att_id').value,
        student_name: document.getElementById('edit_att_student').value.trim(),
        student_code: document.getElementById('edit_att_code').value.trim(),
        record_date: document.getElementById('edit_att_date').value,
        record_time: document.getElementById('edit_att_time').value,
        grade_level: document.getElementById('edit_att_grade').value,
        section_name: document.getElementById('edit_att_section').value,
        subject_name: document.getElementById('edit_att_subject').value,
        status: document.getElementById('edit_att_status').value,
        remarks: document.getElementById('edit_att_remarks').value.trim()
    };

    try {
        const res = await fetch('attendance.php?ajax_action=update_record', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.status === 'success') {
            const rec = data.record;
            const row = document.getElementById('att-rec-row-' + rec.id);
            if (row) {
                row.dataset.date = rec.record_date;
                row.dataset.time = rec.record_time;
                row.dataset.student = rec.student_name;
                row.dataset.code = rec.student_code;
                row.dataset.initials = rec.avatar_initials;
                row.dataset.grade = rec.grade_level;
                row.dataset.section = rec.section_name;
                row.dataset.subject = rec.subject_name;
                row.dataset.status = rec.status;
                row.dataset.remarks = rec.remarks;

                row.querySelector('.col-datetime').innerHTML = `${rec.formatted_date} &bull; ${rec.formatted_time}`;
                row.querySelector('.col-day').textContent = rec.day_short;
                row.querySelector('.col-initials').textContent = rec.avatar_initials;
                row.querySelector('.col-student').textContent = rec.student_name;
                // Teacher ID remains available in the edit form.

                const gPill = row.querySelector('.col-grade .att-grade-pill');
                if (gPill) {
                    gPill.className = 'att-grade-pill ' + getAttGradeClassJS(rec.grade_level);
                    gPill.querySelector('.grade-label').textContent = rec.grade_level;
                }

                const sPill = row.querySelector('.col-section .att-sec-pill');
                if (sPill) {
                    sPill.className = 'att-sec-pill ' + getAttSecClassJS(rec.section_name);
                    sPill.textContent = rec.section_name;
                }

                row.querySelector('.col-subject').textContent = rec.subject_name;
                row.querySelector('.col-remarks').textContent = rec.remarks;

                const stBtn = document.getElementById('att-status-btn-' + rec.id);
                const stIcon = document.getElementById('att-status-icon-' + rec.id);
                const stTxt = document.getElementById('att-status-text-' + rec.id);
                if (stBtn) stBtn.className = 'att-status-pill ' + rec.status.toLowerCase();
                if (stIcon) {
                    stIcon.className = rec.status === 'Present'
                        ? 'fa-solid fa-circle-check'
                        : (rec.status === 'Absent' ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-clock');
                }
                if (stTxt) stTxt.textContent = rec.status;
            }
            closeEditAttModal();
            applyAttendanceFilters();
            showAttAlert(data.message, 'success');
        } else {
            showAttAlert(data.message || 'Could not update record.', 'error');
        }
    } catch (err) {
        showAttAlert('Network error while saving record.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

// Delete Record
async function deleteAttRecord(id) {
    const r = document.getElementById('att-rec-row-' + id);
    const name = r ? r.dataset.student : 'this teacher';
    if (!confirm(`Delete attendance record for "${name}"?`)) return;

    try {
        const res = await fetch('attendance.php?ajax_action=delete_record', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await res.json();
        if (data.status === 'success') {
            if (r) r.remove();
            applyAttendanceFilters();
            showAttAlert(data.message, 'success');
        } else {
            showAttAlert(data.message || 'Could not delete record.', 'error');
        }
    } catch (err) {
        showAttAlert('Network error while deleting record.', 'error');
    }
}

// Export CSV
function exportAttendanceCSV() {
    const rows = Array.from(document.querySelectorAll('#attendanceTableBody tr.att-data-row'))
        .filter(r => r.style.display !== 'none');

    const csv = [
        ['Date', 'Time', 'Teacher Name', 'Teacher ID', 'Grade Level', 'Section', 'Subject', 'Status', 'Remarks']
    ];
    rows.forEach(r => {
        csv.push([
            r.dataset.date,
            r.dataset.time,
            r.dataset.student,
            r.dataset.code,
            r.dataset.grade,
            r.dataset.section,
            r.dataset.subject,
            r.dataset.status,
            r.dataset.remarks
        ]);
    });

    const csvStr = csv.map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csvStr], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'teacher_attendance_records_export.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// View Summary Modal
function openAttendanceSummaryModal() {
    const rows = Array.from(document.querySelectorAll('#attendanceTableBody tr.att-data-row'))
        .filter(r => r.style.display !== 'none');
    let p = 0, a = 0, l = 0;
    rows.forEach(r => {
        if (r.dataset.status === 'Present') p++;
        else if (r.dataset.status === 'Absent') a++;
        else if (r.dataset.status === 'Late') l++;
    });

    document.getElementById('summaryAttModalBody').innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;text-align:center;">
            <div style="padding:14px;background:#e1f7ec;border-radius:12px;color:#0e7c56;">
                <div style="font-size:1.6rem;font-weight:800;">${p}</div>
                <div style="font-size:0.8rem;font-weight:700;">Present (Visible)</div>
            </div>
            <div style="padding:14px;background:#fde8e8;border-radius:12px;color:#d93838;">
                <div style="font-size:1.6rem;font-weight:800;">${a}</div>
                <div style="font-size:0.8rem;font-weight:700;">Absent (Visible)</div>
            </div>
            <div style="padding:14px;background:#fef1dc;border-radius:12px;color:#c97312;">
                <div style="font-size:1.6rem;font-weight:800;">${l}</div>
                <div style="font-size:0.8rem;font-weight:700;">Late (Visible)</div>
            </div>
        </div>
        <div style="padding:14px;background:#f5f9f7;border:1px solid var(--att-border);border-radius:12px;font-size:0.86rem;color:#27473f;">
            <strong>Faculty Teacher Attendance Summary:</strong><br>
            Total Teachers: <strong><?= $totalTeachersCount ?></strong> &bull; Total Logs: <strong><?= $totalRecordsCount ?></strong> &bull; Present: <strong><?= $presentCount ?> (<?= $presentPct ?>%)</strong> &bull; Absent: <strong><?= $absentCount ?> (<?= $absentPct ?>%)</strong> &bull; Late: <strong><?= $lateCount ?> (<?= $latePct ?>%)</strong>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:18px;">
            <button type="button" class="primary-btn" onclick="closeAttendanceSummaryModal()">Close</button>
        </div>
    `;
    document.getElementById('summaryAttModal').classList.add('open');
}

function closeAttendanceSummaryModal() {
    document.getElementById('summaryAttModal').classList.remove('open');
}

window.addEventListener('DOMContentLoaded', () => {
    applyAttendanceFilters();
});
</script>

<script type="application/json" id="student-sections-data"><?= json_encode((object)$studentSectionsByGrade, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../assets/js/student-sections.js"></script>
</body>
</html>
