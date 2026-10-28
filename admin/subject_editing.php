<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/student_sections.php';
require_once __DIR__ . '/../config/subject_settings.php';
require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();
$_SESSION['subject_editor_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$success = '';
function editor_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$sections = [];
foreach (get_student_sections_by_grade($pdo) as $grade => $names) {
    foreach ($names as $name) $sections[] = ['grade_name' => $grade, 'section_name' => $name];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['subject_editor_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your form expired. Please refresh and try again.');
        }
        if (($_POST['action'] ?? '') === 'edit_subject') {
            save_subject_settings($pdo, (string)($_SESSION['user_role'] ?? ''), $_POST, $_SESSION['subject_editor_csrf']);
            $success = 'Subject, assigned teacher, and schedule updated successfully.';
        } elseif (($_POST['action'] ?? '') === 'edit_section') {
            $pdo->beginTransaction();
            $grade = (string)($_POST['grade_name'] ?? '');
            $old = (string)($_POST['old_section'] ?? '');
            $new = trim($_POST['section_name'] ?? '');
            $exists = false;
            foreach ($sections as $section) {
                if ($section['grade_name'] === $grade && $section['section_name'] === $old) $exists = true;
                if ($section['grade_name'] === $grade && strcasecmp($section['section_name'], $new) === 0 && strcasecmp($old, $new) !== 0) {
                    throw new InvalidArgumentException('That section already exists in this grade. Choose another name.');
                }
            }
            if (!$exists || $new === '' || strlen($new) > 50) throw new InvalidArgumentException('Select an existing section and enter a name up to 50 bytes.');
            $pdo->prepare("UPDATE users SET course_section = ? WHERE grade_level = ? AND course_section = ?")->execute([$new, $grade, $old]);
            $pdo->prepare('UPDATE teachers SET section_name = ? WHERE grade_level_name = ? AND section_name = ?')->execute([$new, $grade, $old]);
            $pdo->prepare('UPDATE teacher_assignments ta JOIN grade_levels gl ON gl.id = ta.grade_level_id SET ta.section_name = ? WHERE gl.grade_name = ? AND ta.section_name = ?')->execute([$new, $grade, $old]);
            $pdo->prepare('UPDATE school_sections ss JOIN grade_levels gl ON gl.id = ss.grade_level_id SET ss.section_name = ? WHERE gl.grade_name = ? AND ss.section_name = ?')->execute([$new, $grade, $old]);
            $pdo->prepare('UPDATE attendance_records SET section_name = ? WHERE grade_level = ? AND section_name = ?')->execute([$new, $grade, $old]);
            $success = 'Section updated for its students and teaching assignments.';
        } else {
            throw new InvalidArgumentException('Invalid action.');
        }
        if ($pdo->inTransaction()) $pdo->commit();
        $_SESSION['subject_editor_success'] = $success;
        header('Location: subject_editing.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save changes. Please try again.';
        if (!($e instanceof InvalidArgumentException)) error_log('Subject editor: ' . $e->getMessage());
    }
}
$success = $_SESSION['subject_editor_success'] ?? '';
unset($_SESSION['subject_editor_success']);
$subjects = $pdo->query('SELECT s.*, gl.grade_name, u.name AS professor_name FROM subjects s LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id LEFT JOIN users u ON u.id = s.professor_id ORDER BY gl.grade_name, s.subject_name, s.id DESC')->fetchAll();
$professors = $pdo->query("SELECT id, name, status FROM users WHERE role = 'professor' AND (status = 'Active' OR id IN (SELECT professor_id FROM subjects)) ORDER BY name")->fetchAll();
$schedulesBySubject = [];
foreach ($pdo->query("SELECT * FROM schedules ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), start_time, id")->fetchAll() as $schedule) {
    $schedulesBySubject[(int)$schedule['subject_id']][] = $schedule;
}
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$grades = array_unique(array_merge(array_map(function ($s) { return $s['grade_name'] ?: 'No grade assigned'; }, $subjects), array_column($sections, 'grade_name')));
natsort($grades);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects &amp; Sections - Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .editor-layout { display:flex; min-height:100vh; }
        .editor-main { flex:1; min-width:0; padding:32px; }
        .editor-main h1 { margin-bottom:8px; }
        .editor-intro { color:var(--gray-500); margin-bottom:24px; }
        .editor-form { display:grid; grid-template-columns:minmax(100px,1fr) minmax(160px,2fr); gap:12px; align-items:end; }
        .editor-form .form-group { margin:0; }
        .editor-row { padding:18px 0; border-bottom:1px solid var(--gray-200,#e5e7eb); }
        .editor-row:last-child { border-bottom:0; }
        .editor-caption { margin-bottom:10px; color:var(--gray-500); font-size:.85rem; }
        .section-form { grid-template-columns:1fr; }
        .editor-toolbar { display:flex; flex-wrap:wrap; gap:16px; align-items:end; margin-bottom:24px; }
        .editor-tabs { display:flex; gap:8px; }
        .editor-tabs button[aria-selected="true"] { background:var(--primary,#0d695f); color:white; }
        .editor-panels { display:block; }
        [hidden] { display:none !important; }
        .editor-row summary { display:flex; gap:16px; align-items:center; justify-content:space-between; cursor:pointer; list-style:none; }
        .editor-row summary::-webkit-details-marker { display:none; }
        .record-heading { display:block; font-weight:700; margin-bottom:4px; }
        .record-meta { display:block; color:var(--gray-500); font-size:.88rem; }
        .edit-label { flex-shrink:0; color:var(--primary,#0d695f); font-weight:600; }
        .editor-row[open] { background:#f6faf8; padding:18px; border-radius:10px; margin-top:10px; }
        .editor-row[open] summary { margin-bottom:18px; }
        .edit-context, .change-preview { grid-column:1/-1; padding:12px; border-radius:8px; background:#eaf4ef; font-size:.9rem; }
        .editor-actions { grid-column:1/-1; display:flex; flex-wrap:wrap; gap:10px; }
        .panel-help { color:var(--gray-500); margin-bottom:16px; }
        .empty-filter { padding:20px 0; color:var(--gray-500); }
        @media(max-width:900px) { .editor-layout { flex-direction:column; } .editor-main { padding:20px; } .editor-form { grid-template-columns:1fr; } }
        .teacher-field, .schedule-fields { grid-column:1/-1; }
        .schedule-fields { border:1px solid #dce7e2;padding:14px;border-radius:10px;min-width:0; }
        .schedule-fields legend { padding:0 6px;font-weight:700; }
        .schedule-row { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:12px; }
        .schedule-row:last-child { margin-bottom:0; }
        .schedule-row input,.schedule-row select { min-width:0;width:100%; }
        @media(max-width:650px) { .schedule-row { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media(max-width:420px) { .schedule-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="editor-layout">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo"><img src="../assets/images/logo.png" alt="School logo"></div>
            <div class="sidebar-brand-text"><span>ODB Montessori</span><small>ADMIN PORTAL</small></div>
        </div>
        <div class="sidebar-section-label">WORKSPACE</div>
        <nav class="sidebar-nav">
            <a class="nav-item" href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
            <a class="nav-item" href="students.php"><i class="fa-solid fa-user-group"></i> Students</a>
            <a class="nav-item" href="teachers.php"><i class="fa-solid fa-chalkboard-user"></i> Teachers</a>
            <a class="nav-item active" href="subject_editing.php"><i class="fa-solid fa-book-open"></i> Subjects &amp; Sections</a>
            <a class="nav-item" href="attendance.php"><i class="fa-solid fa-clipboard-check"></i> Attendance</a>
            <a class="nav-item" href="reports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
            <a class="nav-item" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
        </nav>
    </aside>
    <main class="editor-main">
        <h1>Subjects &amp; Sections</h1>
        <p class="editor-intro">Choose a grade level, select Subjects or Sections, then click Edit on the record you want to change.</p>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= editor_escape($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success" role="status"><?= editor_escape($success) ?></div><?php endif; ?>
        <div class="editor-toolbar" id="editor-toolbar" hidden>
            <div class="form-group" style="margin:0;min-width:200px;">
                <label class="form-label" for="grade-filter">1. Grade level</label>
                <select class="form-control" id="grade-filter">
                    <option value="">All grade levels</option>
                    <?php foreach ($grades as $grade): ?><option value="<?= editor_escape($grade) ?>"><?= editor_escape($grade) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><div class="form-label">2. What would you like to edit?</div>
                <div class="editor-tabs" role="tablist" aria-label="Record type">
                    <button class="btn" id="subjects-tab" type="button" role="tab" aria-controls="subjects-panel" aria-selected="true" data-panel="subjects-panel">Subjects</button>
                    <button class="btn" id="sections-tab" type="button" role="tab" aria-controls="sections-panel" aria-selected="false" data-panel="sections-panel">Sections</button>
                </div>
            </div>
        </div>
        <div class="editor-panels">
            <section class="card" id="subjects-panel" role="tabpanel" aria-labelledby="subjects-tab">
                <div class="card-header"><h2 class="card-title">Subjects</h2><a href="subjects.php#create-subject" class="btn btn-primary">Add Subject &amp; Schedule</a></div>
                <p class="panel-help">Edit the subject, assigned teacher, and class schedule. Check the grade and subject before saving. The assignment and schedule apply to all sections of this subject.</p>
                <p class="empty-filter" hidden>No subjects found for this grade level.</p>
                <?php if (!$subjects): ?><p>No subjects available yet.</p><?php endif; ?>
                <?php foreach ($subjects as $subject):
                    $subjectSchedules = $schedulesBySubject[(int)$subject['id']] ?? [['id' => 0, 'day_of_week' => 'Monday', 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'late_threshold_mins' => 15]];
                    $retry = $error !== '' && ($_POST['action'] ?? '') === 'edit_subject' && (int)($_POST['subject_id'] ?? 0) === (int)$subject['id'];
                    $editValues = $subject;
                    if ($retry) foreach (['subject_code', 'subject_name', 'professor_id'] as $field) {
                        if (isset($_POST[$field]) && is_string($_POST[$field])) $editValues[$field] = $_POST[$field];
                    }
                ?>
                <details class="editor-row" data-grade="<?= editor_escape($subject['grade_name'] ?: 'No grade assigned') ?>"<?= $retry ? ' open' : '' ?>>
                    <summary>
                        <span><span class="record-heading"><?= editor_escape($subject['subject_name']) ?></span><span class="record-meta"><?= editor_escape($subject['subject_code']) ?> &middot; <?= editor_escape($subject['grade_name'] ?: 'No grade assigned') ?> &middot; Teacher: <?= editor_escape(professor_display_name($subject['professor_name'] ?: 'Unassigned')) ?></span></span>
                        <span class="edit-label">Edit subject <i class="fa-solid fa-pen" aria-hidden="true"></i></span>
                    </summary>
                    <form method="post" class="editor-form" data-kind="subject" data-retry="<?= $retry ? '1' : '0' ?>">
                        <div class="edit-context"><strong>Editing:</strong> <?= editor_escape($subject['subject_code']) ?> &mdash; <?= editor_escape($subject['subject_name']) ?> (<?= editor_escape($subject['grade_name'] ?: 'No grade assigned') ?>). Teacher and schedule changes apply to every section of this subject.</div>
                        <input type="hidden" name="csrf" value="<?= editor_escape($_SESSION['subject_editor_csrf']) ?>">
                        <input type="hidden" name="action" value="edit_subject">
                        <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                        <div class="form-group"><label class="form-label" for="code-<?= (int)$subject['id'] ?>">Subject code</label><input class="form-control" id="code-<?= (int)$subject['id'] ?>" name="subject_code" maxlength="20" required value="<?= editor_escape($editValues['subject_code']) ?>"></div>
                        <div class="form-group"><label class="form-label" for="name-<?= (int)$subject['id'] ?>">Subject name</label><input class="form-control" id="name-<?= (int)$subject['id'] ?>" name="subject_name" maxlength="100" required value="<?= editor_escape($editValues['subject_name']) ?>"></div>
                        <div class="form-group teacher-field">
                            <label class="form-label" for="teacher-<?= (int)$subject['id'] ?>">Assigned teacher</label>
                            <select class="form-control" id="teacher-<?= (int)$subject['id'] ?>" name="professor_id" required>
                                <option value="">Choose a teacher</option>
                                <?php foreach ($professors as $professor): ?>
                                <option value="<?= (int)$professor['id'] ?>" <?= (int)$editValues['professor_id'] === (int)$professor['id'] ? 'selected' : '' ?> <?= $professor['status'] !== 'Active' && (int)$subject['professor_id'] !== (int)$professor['id'] ? 'disabled' : '' ?>><?= editor_escape(professor_display_name($professor['name'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <fieldset class="schedule-fields">
                            <legend>Class schedule</legend>
                            <?php foreach ($subjectSchedules as $scheduleIndex => $schedule):
                                $scheduleId = (int)$schedule['id'];
                                if ($retry && isset($_POST['schedules'][$scheduleId]) && is_array($_POST['schedules'][$scheduleId])) {
                                    foreach (['day_of_week','start_time','end_time','late_threshold_mins'] as $field) {
                                        if (is_string($_POST['schedules'][$scheduleId][$field] ?? null)) $schedule[$field] = $_POST['schedules'][$scheduleId][$field];
                                    }
                                }
                                $fieldId = (int)$subject['id'] . '-' . $scheduleId;
                            ?>
                            <div class="schedule-row">
                                <div class="form-group"><label class="form-label" for="day-<?= $fieldId ?>">Day <?= count($subjectSchedules) > 1 ? $scheduleIndex + 1 : '' ?></label><select class="form-control" id="day-<?= $fieldId ?>" name="schedules[<?= $scheduleId ?>][day_of_week]" required><?php foreach ($days as $day): ?><option value="<?= $day ?>" <?= $schedule['day_of_week'] === $day ? 'selected' : '' ?>><?= $day ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label class="form-label" for="start-<?= $fieldId ?>">Start time</label><input type="time" step="1" class="form-control" id="start-<?= $fieldId ?>" name="schedules[<?= $scheduleId ?>][start_time]" value="<?= editor_escape($schedule['start_time']) ?>" required></div>
                                <div class="form-group"><label class="form-label" for="end-<?= $fieldId ?>">End time</label><input type="time" step="1" class="form-control" id="end-<?= $fieldId ?>" name="schedules[<?= $scheduleId ?>][end_time]" value="<?= editor_escape($schedule['end_time']) ?>" required></div>
                                <div class="form-group"><label class="form-label" for="late-<?= $fieldId ?>">Late grace (minutes)</label><input type="number" class="form-control" min="0" max="60" id="late-<?= $fieldId ?>" name="schedules[<?= $scheduleId ?>][late_threshold_mins]" value="<?= editor_escape($schedule['late_threshold_mins']) ?>" required></div>
                            </div>
                            <?php endforeach; ?>
                        </fieldset>
                        <div class="change-preview" aria-live="polite" hidden></div>
                        <div class="editor-actions"><button class="btn btn-primary" type="submit">Save subject changes</button><button class="btn cancel-edit" type="button" hidden>Cancel</button></div>
                    </form>
                </details>
                <?php endforeach; ?>
            </section>
            <section class="card" id="sections-panel" role="tabpanel" aria-labelledby="sections-tab">
                <div class="card-header"><h2 class="card-title">Sections</h2></div>
                <p class="panel-help">Rename an existing section within its grade level. Students and teaching assignments in that section will use the new name. To move an individual student, use the Students page.</p>
                <p class="empty-filter" hidden>No sections found for this grade level.</p>
                <?php if (!$sections): ?><p>No sections available yet. Assign a section when adding a student or teacher.</p><?php endif; ?>
                <?php foreach ($sections as $index => $section): ?>
                <details class="editor-row" data-grade="<?= editor_escape($section['grade_name']) ?>">
                    <summary><span><span class="record-heading"><?= editor_escape($section['section_name']) ?></span><span class="record-meta"><?= editor_escape($section['grade_name']) ?></span></span><span class="edit-label">Edit section <i class="fa-solid fa-pen" aria-hidden="true"></i></span></summary>
                    <form method="post" class="editor-form section-form" data-kind="section">
                        <div class="edit-context"><strong>Renaming:</strong> <?= editor_escape($section['section_name']) ?> in <?= editor_escape($section['grade_name']) ?>. All students and teaching assignments in this section and grade will be updated.</div>
                        <input type="hidden" name="csrf" value="<?= editor_escape($_SESSION['subject_editor_csrf']) ?>">
                        <input type="hidden" name="action" value="edit_section">
                        <input type="hidden" name="grade_name" value="<?= editor_escape($section['grade_name']) ?>">
                        <input type="hidden" name="old_section" value="<?= editor_escape($section['section_name']) ?>">
                        <div class="form-group"><label class="form-label" for="section-<?= $index ?>">Section name</label><input class="form-control" id="section-<?= $index ?>" name="section_name" maxlength="50" required value="<?= editor_escape($section['section_name']) ?>"></div>
                        <div class="change-preview" aria-live="polite" hidden></div>
                        <div class="editor-actions"><button class="btn btn-primary" type="submit">Save section name</button><button class="btn cancel-edit" type="button" hidden>Cancel</button></div>
                    </form>
                </details>
                <?php endforeach; ?>
            </section>
        </div>
    </main>
</div>
<script>
(() => {
    const panels = [...document.querySelectorAll('[role="tabpanel"]')];
    const tabs = [...document.querySelectorAll('[role="tab"]')];
    const gradeFilter = document.getElementById('grade-filter');
    document.getElementById('editor-toolbar').hidden = false;
    const filterRows = () => {
        panels.forEach(panel => {
            const rows = [...panel.querySelectorAll('.editor-row')];
            rows.forEach(row => { row.hidden = !!gradeFilter.value && row.dataset.grade !== gradeFilter.value; });
            panel.querySelector('.empty-filter').hidden = rows.length === 0 || rows.some(row => !row.hidden);
        });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            tabs.forEach(item => { item.setAttribute('aria-selected', String(item === tab)); item.tabIndex = item === tab ? 0 : -1; });
            panels.forEach(panel => { panel.hidden = panel.id !== tab.dataset.panel; });
        });
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const target = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs[tabs.length - 1] : tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            target.click(); target.focus();
        });
    });
    gradeFilter.addEventListener('change', filterRows);
    document.querySelectorAll('.editor-form').forEach(form => {
        const fields = [...form.querySelectorAll('input:not([type="hidden"]), select')];
        const preview = form.querySelector('.change-preview');
        const save = form.querySelector('[type="submit"]');
        const cancel = form.querySelector('.cancel-edit');
        const original = field => field.tagName === 'SELECT'
            ? ([...field.options].find(option => option.defaultSelected)?.value ?? field.options[0]?.value ?? '')
            : field.defaultValue;
        const label = (field, value) => field.tagName === 'SELECT'
            ? ([...field.options].find(option => option.value === value)?.textContent ?? value)
            : value;
        const refresh = () => {
            const changes = fields.filter(input => input.value.trim() !== original(input));
            save.disabled = changes.length === 0 && form.dataset.retry !== '1';
            preview.hidden = changes.length === 0;
            preview.textContent = changes.map(input => `${input.labels[0].textContent}: "${label(input, original(input))}" to "${label(input, input.value.trim())}"`).join("; ");
        };
        fields.forEach(input => { input.addEventListener('input', refresh); input.addEventListener('change', refresh); });
        cancel.hidden = false;
        cancel.addEventListener('click', () => { form.reset(); refresh(); form.closest('details').open = false; });
        refresh();
    });
    tabs[0].click();
    filterRows();
})();
</script>
</body>
</html>
