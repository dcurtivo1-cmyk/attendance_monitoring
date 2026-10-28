<?php
// Run from CLI once: php scripts/standardize_sections.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=attendance_db;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE IF NOT EXISTS school_sections (
 id INT AUTO_INCREMENT PRIMARY KEY, grade_level_id INT NOT NULL, section_name VARCHAR(50) NOT NULL,
 UNIQUE KEY unique_grade_section (grade_level_id, section_name),
 FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS system_migrations (migration_key VARCHAR(100) PRIMARY KEY, applied_at DATETIME NULL) ENGINE=InnoDB");
$key = 'standardize_sections_20261028';
$pdo->prepare('INSERT IGNORE INTO system_migrations (migration_key) VALUES (?)')->execute([$key]);
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT applied_at FROM system_migrations WHERE migration_key = ? FOR UPDATE');
    $lock->execute([$key]);
    if ($lock->fetchColumn()) { $pdo->rollBack(); echo "Section migration already applied; no changes.\n"; exit; }
    $grades = $pdo->query('SELECT id, grade_name FROM grade_levels ORDER BY id')->fetchAll();
    $gradeIds = array_column($grades, 'id', 'grade_name');
    $gradeNames = array_column($grades, 'grade_name', 'id');
    $users = $pdo->query('SELECT id, identifier, role, grade_level, course_section FROM users ORDER BY id FOR UPDATE')->fetchAll();
    $teachers = $pdo->query('SELECT id, user_id, grade_level_id, grade_level_name, section_name FROM teachers ORDER BY id FOR UPDATE')->fetchAll();
    $assignments = $pdo->query('SELECT id, teacher_id, grade_level_id, section_name FROM teacher_assignments ORDER BY id FOR UPDATE')->fetchAll();
    $history = $pdo->query('SELECT id, student_code, grade_level, section_name FROM attendance_records ORDER BY id FOR UPDATE')->fetchAll();
    foreach ($users as $u) {
        if ($u['role'] === 'student' && !isset($gradeIds[$u['grade_level']])) throw new RuntimeException('A student has an unknown grade. Migration stopped without changing data.');
    }
    $backup = ['created_at' => date(DATE_ATOM), 'users' => $users, 'teachers' => $teachers, 'teacher_assignments' => $assignments, 'attendance_records' => $history, 'school_sections' => $pdo->query('SELECT * FROM school_sections')->fetchAll()];
    $backupPath = __DIR__ . '/../storage/section-backup-' . date('Ymd-His') . '.json';
    if (file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Backup could not be saved.');
    $oldNames = [];
    foreach ($grades as $g) $oldNames[$g['grade_name']] = [];
    foreach ($users as $u) if ($u['role'] === 'student') $oldNames[$u['grade_level']][] = trim($u['course_section'] ?? '');
    foreach ($teachers as &$t) {
        $t['grade_level_name'] = $gradeNames[$t['grade_level_id']] ?? $t['grade_level_name'];
        if (!isset($gradeIds[$t['grade_level_name']])) throw new RuntimeException('Teacher has unknown grade.');
        $t['grade_level_id'] = $gradeIds[$t['grade_level_name']];
        $oldNames[$t['grade_level_name']][] = trim($t['section_name'] ?? '');
    }
    unset($t);
    $teachersById = array_column($teachers, null, 'id');
    foreach ($assignments as &$a) {
        $a['grade_level_id'] = $a['grade_level_id'] ?: ($teachersById[$a['teacher_id']]['grade_level_id'] ?? null);
        if (!isset($gradeNames[$a['grade_level_id']])) throw new RuntimeException('Assignment has unknown grade.');
        $oldNames[$gradeNames[$a['grade_level_id']]][] = trim($a['section_name'] ?? '');
    }
    unset($a);
    $mapping = []; $catalog = [];
    foreach ($oldNames as $grade => $names) {
        $names = array_values(array_unique($names)); sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $mapping[$grade] = []; $used = ['A' => true, 'B' => true]; $named = [];
        // Reserve section letters first. Equivalent short labels share their named section.
        foreach ($names as $old) {
            if (preg_match('/^(?:Grade\s+\d+\s*-\s*)?Section\s+([A-Z])(?:\s*-\s*(.+))?$/i', $old, $m)) {
                $letter = strtoupper($m[1]); $used[$letter] = true;
                if (empty($m[2]) || !isset($named[$letter])) {
                    $mapping[$grade][$old] = $grade . ' - Section ' . $letter;
                    if (!empty($m[2])) $named[$letter] = $old;
                }
            }
        }
        foreach ($names as $old) {
            if (isset($mapping[$grade][$old])) continue;
            if ($old === '') { $mapping[$grade][$old] = $grade . ' - Section A'; continue; }
            $letter = null;
            foreach (range('A', 'Z') as $candidate) if (!isset($used[$candidate])) { $letter = $candidate; break; }
            if ($letter === null) throw new RuntimeException('Too many distinct sections for one grade.');
            $used[$letter] = true;
            $mapping[$grade][$old] = $grade . ' - Section ' . $letter;
        }
        $catalog[$grade] = array_values(array_unique(array_merge([$grade . ' - Section A', $grade . ' - Section B'], array_values($mapping[$grade]))));
        sort($catalog[$grade], SORT_NATURAL);
    }
    $insertSection = $pdo->prepare('INSERT IGNORE INTO school_sections (grade_level_id, section_name) VALUES (?, ?)');
    foreach ($catalog as $grade => $sections) foreach ($sections as $section) $insertSection->execute([$gradeIds[$grade], $section]);
    $updateUser = $pdo->prepare('UPDATE users SET grade_level = ?, course_section = ? WHERE id = ?');
    $studentSections = []; $userCount = 0;
    foreach ($users as $u) {
        if ($u['role'] === 'student') {
            $section = $mapping[$u['grade_level']][trim($u['course_section'] ?? '')];
            $updateUser->execute([$u['grade_level'], $section, $u['id']]);
            $studentSections[$u['identifier']] = ['grade' => $u['grade_level'], 'section' => $section];
            $userCount++;
        } elseif ($u['role'] === 'admin') {
            $updateUser->execute([null, null, $u['id']]);
        }
    }
    $primaryTeachers = [];
    $updateTeacher = $pdo->prepare('UPDATE teachers SET grade_level_id = ?, grade_level_name = ?, section_name = ? WHERE id = ?');
    foreach ($teachers as $t) {
        $section = $mapping[$t['grade_level_name']][trim($t['section_name'] ?? '')];
        $updateTeacher->execute([$t['grade_level_id'], $t['grade_level_name'], $section, $t['id']]);
        if ($t['user_id'] && !isset($primaryTeachers[$t['user_id']])) $primaryTeachers[$t['user_id']] = ['grade' => $t['grade_level_name'], 'section' => $section];
    }
    foreach ($users as $u) {
        if ($u['role'] !== 'professor') continue;
        $primary = $primaryTeachers[$u['id']] ?? ['grade' => isset($gradeIds[$u['grade_level']]) ? $u['grade_level'] : 'Grade 7'];
        $updateUser->execute([$primary['grade'], $primary['section'] ?? $primary['grade'] . ' - Section A', $u['id']]);
    }
    $updateAssignment = $pdo->prepare('UPDATE teacher_assignments SET grade_level_id = ?, section_name = ? WHERE id = ?');
    foreach ($assignments as $a) {
        $grade = $gradeNames[$a['grade_level_id']];
        $updateAssignment->execute([$a['grade_level_id'], $mapping[$grade][trim($a['section_name'] ?? '')], $a['id']]);
    }
    // Align report snapshots with the same student's grade/section where available.
    $updateHistory = $pdo->prepare('UPDATE attendance_records SET section_name = ? WHERE id = ?');
    foreach ($history as $h) {
        $grade = $h['grade_level'];
        if (!isset($gradeIds[$grade])) throw new RuntimeException('Attendance record has unknown grade.');
        $student = $studentSections[$h['student_code']] ?? null;
        $section = $student && $student['grade'] === $grade ? $student['section'] : ($mapping[$grade][trim($h['section_name'] ?? '')] ?? $grade . ' - Section A');
        $updateHistory->execute([$section, $h['id']]);
    }
    $pdo->prepare('UPDATE system_migrations SET applied_at = NOW() WHERE migration_key = ?')->execute([$key]);
    $pdo->commit();
    echo json_encode(['students_updated' => $userCount, 'professors_updated' => count(array_filter($users, fn($u) => $u['role'] === 'professor')), 'teachers_updated' => count($teachers), 'assignments_updated' => count($assignments), 'report_rows_updated' => count($history), 'sections' => $catalog, 'backup' => basename($backupPath)], JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
}
