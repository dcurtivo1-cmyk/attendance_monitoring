<?php
require __DIR__ . '/../config/subject_sections.php';
// Exercise the enrollment POST handler with an isolated SQLite database.
// SQLite does not support FOR UPDATE, so only that locking suffix is removed.
class EnrollmentTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function require_auth($roles) {}
function current_user() { return ['id' => 10]; }
function get_db_connection() { return $GLOBALS['testPdo']; }
$scenario = $argv[1] ?? 'success';
$testPdo = new EnrollmentTestPDO('sqlite::memory:');
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testPdo->exec("CREATE TABLE subjects (id INTEGER PRIMARY KEY, subject_code TEXT, professor_id INTEGER, grade_level_id INTEGER);
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, status TEXT, email TEXT, identifier TEXT, course_section TEXT, grade_level TEXT);
CREATE TABLE student_subjects (id INTEGER PRIMARY KEY, student_id INTEGER, subject_id INTEGER, UNIQUE(student_id, subject_id));
CREATE TABLE grade_levels (id INTEGER PRIMARY KEY, grade_name TEXT);
CREATE TABLE school_sections (grade_level_id INTEGER, section_name TEXT);
INSERT INTO grade_levels VALUES (1,'Grade 7');
INSERT INTO school_sections VALUES (1,'Grade 7 - Emerald'),(1,'Grade 7 - Jade');
INSERT INTO subjects VALUES (1, 'ENG1', 10, 1), (2, 'MATH1', 11, 1);
INSERT INTO users (id,name,role,status) VALUES (20, 'Test Student', 'student', 'Active'), (21, 'Inactive Student', 'student', 'Inactive');
UPDATE users SET course_section='Grade 7 - Emerald',grade_level='Grade 7';");
$_SESSION = ['enrollment_csrf' => 'test-token'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'add_student', 'subject_id' => 1, 'student_id' => 20, 'csrf' => 'test-token', 'section' => 'Grade 7 - Emerald'];
if ($scenario === 'cross_section') $_POST['section'] = 'Grade 7 - Jade';
if ($scenario === 'unknown_section') $_POST['section'] = 'Invalid Section';
if ($scenario === 'missing_section') unset($_POST['section']);
if ($scenario === 'all_sections') $_POST['section'] = 'ALL';
if ($scenario === 'wrong_grade') $testPdo->exec("UPDATE users SET grade_level='Grade 8' WHERE id=20");
if ($scenario === 'creation_blocked') $_POST['action'] = 'add_subject';
if ($scenario === 'duplicate') $testPdo->exec('INSERT INTO student_subjects (student_id, subject_id) VALUES (20, 1)');
if ($scenario === 'ownership') $_POST['subject_id'] = 2;
if ($scenario === 'inactive') $_POST['student_id'] = 21;
if ($scenario === 'csrf') $_POST['csrf'] = 'wrong-token';
if ($scenario === 'nonstudent') $testPdo->exec("UPDATE users SET role = 'professor' WHERE id = 20");
register_shutdown_function(function () use ($scenario, $testPdo) {
    $count = (int)$testPdo->query('SELECT COUNT(*) FROM student_subjects')->fetchColumn();
    $expected = in_array($scenario, ['success', 'duplicate']) ? 1 : 0;
    $ok = $count === $expected && ($scenario === 'success' ? !empty($_SESSION['enrollment_success']) : !empty($GLOBALS['error']));
    if ($scenario === 'creation_blocked') $ok = $ok && http_response_code() === 403 && (int)$testPdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn() === 2;
    if (!$ok) { fwrite(STDERR, "FAIL: $scenario\n"); exit(1); }
    echo "PASS: $scenario\n";
});
$source = file_get_contents(__DIR__ . '/../professor/subjects.php');
$source = substr($source, 5, strpos($source, '// Fetch all subjects of professor') - 5);
$source = preg_replace('/require_once[^;]+;/', '', $source);
eval($source);
