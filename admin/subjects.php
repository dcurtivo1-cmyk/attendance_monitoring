<?php
// admin/subjects.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/subject_creation.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

$_SESSION['create_subject_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$success = $_SESSION['subject_creation_success'] ?? '';
unset($_SESSION['subject_creation_success']);
$form = ['subject_code' => '', 'subject_name' => '', 'professor_id' => '', 'grade_level_id' => '', 'day_of_week' => 'Monday', 'start_time' => '08:00', 'end_time' => '11:00', 'late_threshold' => '15'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_subject') {
    foreach ($form as $key => $default) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) $form[$key] = $_POST[$key];
    }
    try {
        create_subject_with_schedule($pdo, (string)($_SESSION['user_role'] ?? ''), $_POST, $_SESSION['create_subject_csrf']);
        $_SESSION['subject_creation_success'] = 'Subject and schedule created and assigned successfully.';
        header('Location: subjects.php');
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to create the subject and schedule. Please try again.';
        if (!($e instanceof InvalidArgumentException)) error_log('Subject creation failed: ' . $e->getMessage());
    }
}
$gradeLevels = $pdo->query('SELECT id, grade_name FROM grade_levels ORDER BY id')->fetchAll();

// Fetch all professors for assignment dropdown
$profsStmt = $pdo->query("SELECT id, name, identifier FROM users WHERE role = 'professor' AND status = 'Active' ORDER BY name ASC");
$professors = $profsStmt->fetchAll();

// Fetch all subjects with professor info and schedule
$subjectsStmt = $pdo->query("
    SELECT s.*, u.name as professor_name, u.identifier as prof_identifier, gl.grade_name,
           sc.day_of_week, sc.start_time, sc.end_time, sc.late_threshold_mins,
           (SELECT COUNT(*) FROM student_subjects ss WHERE ss.subject_id = s.id) as enrolled_count
    FROM subjects s
    JOIN users u ON s.professor_id = u.id
    LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
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
        <li><a href="subject_editing.php"><i class="fa-solid fa-pen-to-square"></i> Edit Subjects &amp; Sections</a></li>
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
            <span><?= htmlspecialchars($success) ?></span>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <!-- Add Subject Card -->
        <div class="card" id="create-subject">
            <div class="card-header">
                <div class="card-title">
                    <i class="fa-solid fa-folder-plus" style="color: var(--primary);"></i> Add New Subject &amp; Schedule
                </div>
            </div>

            <form action="subjects.php" method="POST">
                <input type="hidden" name="action" value="add_subject">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['create_subject_csrf'], ENT_QUOTES) ?>">

                <div class="form-group">
                    <label class="form-label" for="subject_code">Subject Code *</label>
                    <input type="text" id="subject_code" name="subject_code" class="form-control" placeholder="e.g. G7-ENG1" maxlength="20" value="<?= htmlspecialchars($form['subject_code'], ENT_QUOTES) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="subject_name">Subject Name *</label>
                    <input type="text" id="subject_name" name="subject_name" class="form-control" placeholder="e.g. English, Mathematics" maxlength="100" value="<?= htmlspecialchars($form['subject_name'], ENT_QUOTES) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="professor_id">Assign Professor *</label>
                    <select id="professor_id" name="professor_id" class="form-control" required>
                        <option value="">-- Choose Assigned Professor --</option>
                        <?php foreach ($professors as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (string)$form['professor_id'] === (string)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(professor_display_name($p['name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="grade_level_id">Grade Level *</label>
                    <select id="grade_level_id" name="grade_level_id" class="form-control" required>
                        <option value="">-- Choose Grade Level --</option>
                        <?php foreach ($gradeLevels as $grade): ?>
                            <option value="<?= (int)$grade['id'] ?>" <?= (string)$form['grade_level_id'] === (string)$grade['id'] ? 'selected' : '' ?>><?= htmlspecialchars($grade['grade_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid-2" style="gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="day_of_week">Class Day</label>
                        <select id="day_of_week" name="day_of_week" class="form-control">
                            <option value="Monday" <?= $form['day_of_week'] === 'Monday' ? 'selected' : '' ?>>Monday</option>
                            <option value="Tuesday" <?= $form['day_of_week'] === 'Tuesday' ? 'selected' : '' ?>>Tuesday</option>
                            <option value="Wednesday" <?= $form['day_of_week'] === 'Wednesday' ? 'selected' : '' ?>>Wednesday</option>
                            <option value="Thursday" <?= $form['day_of_week'] === 'Thursday' ? 'selected' : '' ?>>Thursday</option>
                            <option value="Friday" <?= $form['day_of_week'] === 'Friday' ? 'selected' : '' ?>>Friday</option>
                            <option value="Saturday" <?= $form['day_of_week'] === 'Saturday' ? 'selected' : '' ?>>Saturday</option>
                            <option value="Sunday" <?= $form['day_of_week'] === 'Sunday' ? 'selected' : '' ?>>Sunday</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="late_threshold">Late Grace Period</label>
                        <input type="number" id="late_threshold" name="late_threshold" class="form-control" min="0" max="60" required value="<?= htmlspecialchars($form['late_threshold'], ENT_QUOTES) ?>">
                    </div>
                </div>

                <div class="grid-2" style="gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="start_time">Start Time</label>
                        <input type="time" id="start_time" name="start_time" class="form-control" value="<?= htmlspecialchars($form['start_time'], ENT_QUOTES) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_time">End Time</label>
                        <input type="time" id="end_time" name="end_time" class="form-control" value="<?= htmlspecialchars($form['end_time'], ENT_QUOTES) ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-plus"></i> Save Subject &amp; Schedule
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
                                        <small><?= htmlspecialchars($s['grade_name'] ?: 'No grade assigned') ?></small><br>
                                        <small class="badge badge-info"><?= (int)$s['enrolled_count'] ?> Students</small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars(professor_display_name($s['professor_name'])) ?></strong>
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
