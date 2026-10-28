<?php
// Preview: php scripts/name_grade_sections.php
// Apply:   php scripts/name_grade_sections.php --apply
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/student_sections.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=attendance_db;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$key = 'named_grade_sections_20261028';
$applied = $pdo->prepare('SELECT applied_at FROM system_migrations WHERE migration_key = ?');
$applied->execute([$key]);
if ($applied->fetchColumn()) { echo "Named sections already applied; no changes.\n"; exit; }
$apply = in_array('--apply', $argv, true);
$pdo->beginTransaction();
try {
    $catalog = $pdo->query('SELECT ss.id, ss.grade_level_id, gl.grade_name, ss.section_name FROM school_sections ss JOIN grade_levels gl ON gl.id = ss.grade_level_id ORDER BY gl.id, ss.section_name FOR UPDATE')->fetchAll();
    // Recheck after the catalog lock in case another process completed the migration.
    $applied->execute([$key]);
    if ($applied->fetchColumn()) { $pdo->rollBack(); echo "Named sections already applied; no changes.\n"; exit; }
    $counts = []; $changes = [];
    foreach ($catalog as $section) {
        $grade = $section['grade_name'];
        $index = $counts[$grade] ?? 0;
        $names = default_grade_section_names($grade);
        if (!isset($names[$index])) throw new RuntimeException('No generated section name available for ' . $grade . '.');
        $changes[] = $section + ['new_name' => $names[$index]];
        $counts[$grade] = $index + 1;
    }
    if (!$apply) {
        $pdo->rollBack();
        echo json_encode(array_map(static fn($row) => ['grade' => $row['grade_name'], 'from' => $row['section_name'], 'to' => $row['new_name']], $changes), JSON_PRETTY_PRINT), "\n";
        exit;
    }
    $backup = ['created_at' => date(DATE_ATOM), 'mapping' => $changes];
    $backupQueries = [
        'users' => 'SELECT id, grade_level, course_section FROM users FOR UPDATE',
        'teachers' => 'SELECT id, grade_level_id, grade_level_name, section_name FROM teachers FOR UPDATE',
        'teacher_assignments' => 'SELECT id, grade_level_id, section_name FROM teacher_assignments FOR UPDATE',
        'attendance_records' => 'SELECT id, grade_level, section_name FROM attendance_records FOR UPDATE',
    ];
    foreach ($backupQueries as $table => $sql) $backup[$table] = $pdo->query($sql)->fetchAll();
    $path = __DIR__ . '/../storage/named-section-backup-' . date('Ymd-His') . '.json';
    if (file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Could not write section backup.');

    $updates = [
        'users' => $pdo->prepare('UPDATE users SET course_section = ? WHERE grade_level = ? AND course_section = ?'),
        'teachers' => $pdo->prepare('UPDATE teachers SET section_name = ? WHERE grade_level_id = ? AND section_name = ?'),
        'teacher_assignments' => $pdo->prepare('UPDATE teacher_assignments SET section_name = ? WHERE grade_level_id = ? AND section_name = ?'),
        'attendance_records' => $pdo->prepare('UPDATE attendance_records SET section_name = ? WHERE grade_level = ? AND section_name = ?'),
    ];
    $totals = array_fill_keys(array_keys($updates), 0);
    foreach ($changes as $row) {
        foreach ($updates as $table => $stmt) {
            $grade = in_array($table, ['users', 'attendance_records'], true) ? $row['grade_name'] : $row['grade_level_id'];
            $stmt->execute([$row['new_name'], $grade, $row['section_name']]);
            $totals[$table] += $stmt->rowCount();
        }
        $pdo->prepare('UPDATE school_sections SET section_name = ? WHERE id = ?')->execute([$row['new_name'], $row['id']]);
    }
    $invalid = [
        "SELECT COUNT(*) FROM users u LEFT JOIN grade_levels g ON g.grade_name=u.grade_level LEFT JOIN school_sections s ON s.grade_level_id=g.id AND s.section_name=u.course_section WHERE u.role IN ('student','professor') AND s.id IS NULL",
        'SELECT COUNT(*) FROM teachers t LEFT JOIN school_sections s ON s.grade_level_id=t.grade_level_id AND s.section_name=t.section_name WHERE s.id IS NULL',
        'SELECT COUNT(*) FROM teacher_assignments a LEFT JOIN school_sections s ON s.grade_level_id=a.grade_level_id AND s.section_name=a.section_name WHERE s.id IS NULL',
        'SELECT COUNT(*) FROM attendance_records a LEFT JOIN grade_levels g ON g.grade_name=a.grade_level LEFT JOIN school_sections s ON s.grade_level_id=g.id AND s.section_name=a.section_name WHERE s.id IS NULL',
    ];
    foreach ($invalid as $sql) if ((int)$pdo->query($sql)->fetchColumn() !== 0) throw new RuntimeException('Unmatched section found; rolling back all renames.');
    $pdo->prepare('INSERT INTO system_migrations (migration_key, applied_at) VALUES (?, NOW())')->execute([$key]);
    $pdo->commit();
    echo json_encode(['updated' => $totals, 'sections' => get_student_sections_by_grade($pdo), 'backup' => basename($path)], JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
}
