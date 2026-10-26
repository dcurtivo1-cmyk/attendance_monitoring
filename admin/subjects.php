<?php
// admin/subjects.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

$error = '';
$success = '';

// Handle Reassign / Add Subject if submitted by Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_subject') {
    $subject_code = trim($_POST['subject_code'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $professor_id = intval($_POST['professor_id'] ?? 0);
    $day_of_week  = trim($_POST['day_of_week'] ?? 'Monday');
    $start_time   = trim($_POST['start_time'] ?? '08:00');
    $end_time     = trim($_POST['end_time'] ?? '11:00');
    $late_thresh  = intval($_POST['late_threshold'] ?? 15);

    if (empty($subject_code) || empty($subject_name) || $professor_id <= 0) {
        $error = "Subject Code, Name, and an assigned Professor are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, professor_id) VALUES (?, ?, ?)");
            $stmt->execute([$subject_code, $subject_name, $professor_id]);
            $subId = $pdo->lastInsertId();

            $sched = $pdo->prepare("INSERT INTO schedules (subject_id, day_of_week, start_time, end_time, late_threshold_mins) VALUES (?, ?, ?, ?, ?)");
            $sched->execute([$subId, $day_of_week, $start_time, $end_time, $late_thresh]);

            $success = "Subject <strong>$subject_code</strong> successfully created and assigned!";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Fetch all professors for assignment dropdown
$profsStmt = $pdo->query("SELECT id, name, identifier FROM users WHERE role = 'professor' ORDER BY name ASC");
$professors = $profsStmt->fetchAll();

// Fetch all subjects with professor info and schedule
$subjectsStmt = $pdo->query("
    SELECT s.*, u.name as professor_name, u.identifier as prof_identifier,
           sc.day_of_week, sc.start_time, sc.end_time, sc.late_threshold_mins,
           (SELECT COUNT(*) FROM student_subjects ss WHERE ss.subject_id = s.id) as enrolled_count
    FROM subjects s
    JOIN users u ON s.professor_id = u.id
    LEFT JOIN schedules sc ON s.id = sc.subject_id
    ORDER BY s.subject_code ASC
");
$subjects = $subjectsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Subjects & Classes - Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- Admin Navbar -->
<nav class="navbar">
    <div class="brand">
        <div class="brand-icon" style="background: linear-gradient(135deg, #dc2626, #f59e0b);">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <span>Admin Portal</span>
    </div>
    <ul class="nav-links">
        <li><a href="dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li><a href="professors.php"><i class="fa-solid fa-chalkboard-user"></i> Manage Professors</a></li>
        <li><a href="subjects.php" class="active"><i class="fa-solid fa-book-open"></i> All Subjects</a></li>
        <li><a href="students.php"><i class="fa-solid fa-user-graduate"></i> Students</a></li>
        <li>
            <div class="user-badge">
                <span class="role-pill admin">Admin</span>
                <span><?= htmlspecialchars($user['name']) ?></span>
            </div>
        </li>
        <li><a href="../logout.php" style="color: var(--danger);"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
    </ul>
</nav>

<div class="container">
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <span><?= $success ?></span>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <!-- Add Subject Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fa-solid fa-folder-plus" style="color: var(--primary);"></i> Create & Assign Subject
                </div>
            </div>

            <form action="subjects.php" method="POST">
                <input type="hidden" name="action" value="add_subject">

                <div class="form-group">
                    <label class="form-label" for="subject_code">Subject Code *</label>
                    <input type="text" id="subject_code" name="subject_code" class="form-control" placeholder="e.g. IT301, CS204" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="subject_name">Subject Name *</label>
                    <input type="text" id="subject_name" name="subject_name" class="form-control" placeholder="e.g. Mobile Application Development" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="professor_id">Assign Professor *</label>
                    <select id="professor_id" name="professor_id" class="form-control" required>
                        <option value="">-- Choose Assigned Professor --</option>
                        <?php foreach ($professors as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['identifier']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid-2" style="gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="day_of_week">Class Day</label>
                        <select id="day_of_week" name="day_of_week" class="form-control">
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="late_threshold">Late Grace Period</label>
                        <select id="late_threshold" name="late_threshold" class="form-control">
                            <option value="10">10 Mins</option>
                            <option value="15" selected>15 Mins</option>
                            <option value="20">20 Mins</option>
                            <option value="30">30 Mins</option>
                        </select>
                    </div>
                </div>

                <div class="grid-2" style="gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="start_time">Start Time</label>
                        <input type="time" id="start_time" name="start_time" class="form-control" value="08:00" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_time">End Time</label>
                        <input type="time" id="end_time" name="end_time" class="form-control" value="11:00" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-plus"></i> Save Subject
                </button>
            </form>
        </div>

        <!-- Subjects List Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fa-solid fa-list-check" style="color: var(--secondary);"></i> All System Subjects
                </div>
                <span class="badge badge-primary"><?= count($subjects) ?> Subjects</span>
            </div>

            <?php if (empty($subjects)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                    No subjects in the system yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Assigned Professor</th>
                                <th>Schedule</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $s): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($s['subject_code']) ?></strong><br>
                                        <small style="color: var(--gray-500);"><?= htmlspecialchars($s['subject_name']) ?></small><br>
                                        <small class="badge badge-info"><?= (int)$s['enrolled_count'] ?> Students</small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($s['professor_name']) ?></strong><br>
                                        <small style="color: var(--gray-500); font-family: monospace;"><?= htmlspecialchars($s['prof_identifier']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($s['day_of_week']): ?>
                                            <div><strong><?= htmlspecialchars($s['day_of_week']) ?></strong></div>
                                            <small style="color: var(--gray-500);">
                                                <?= date('h:i A', strtotime($s['start_time'])) ?> - <?= date('h:i A', strtotime($s['end_time'])) ?>
                                            </small>
                                        <?php else: ?>
                                            <span style="color: var(--gray-500);">TBA</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
