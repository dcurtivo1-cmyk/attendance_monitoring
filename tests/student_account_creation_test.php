<?php
// Run: php tests/student_account_creation_test.php students|dashboard scenario
// Executes each real creation handler against an isolated database, without modifying live accounts.
require __DIR__ . '/student_mail_stub.php';
putenv('SMTP_USER=test@example.com');
putenv('SMTP_PASS=test-only');
putenv('APP_URL=https://school.example.com/attendance');
require __DIR__ . '/../config/student_sections.php';
require __DIR__ . '/../config/student_account.php';
function generate_qr_token($identifier) { return bin2hex(random_bytes(32)); }
class StudentCreationTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query), $options);
    }
}
$portal = $argv[1] ?? 'students';
$scenario = $argv[2] ?? 'success';
$pdo = new StudentCreationTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function get_db_connection() { global $pdo; return $pdo; }
function base_url($path) { return 'https://fallback.example.com/' . $path; }
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, identifier TEXT UNIQUE, name TEXT, first_name TEXT, middle_initial TEXT, last_name TEXT, email TEXT COLLATE NOCASE UNIQUE, password TEXT, role TEXT, course_section TEXT, grade_level TEXT, guardian_contact TEXT, student_subtitle TEXT, status TEXT, qr_token TEXT);
CREATE TABLE grade_levels (id INTEGER PRIMARY KEY, grade_name TEXT);
CREATE TABLE school_sections (grade_level_id INTEGER, section_name TEXT);
CREATE TABLE subjects (id INTEGER PRIMARY KEY, grade_level_id INTEGER);
CREATE TABLE student_subjects (student_id INTEGER, subject_id INTEGER);
INSERT INTO grade_levels VALUES (1, 'Grade 7');
INSERT INTO school_sections VALUES (1, 'Grade 7 - Section A');
INSERT INTO subjects VALUES (1,1);");
$_SESSION = ['create_student_csrf' => 'test-token'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => 'test-token', 'identifier' => 'TEST-001', 'first_name' => 'Test', 'last_name' => 'Student', 'email' => 'student@example.com', 'grade_level' => 'Grade 7', 'course_section' => 'Grade 7 - Section A', 'password' => '  Unique password  ', 'confirm_password' => '  Unique password  '];
if ($portal === 'register') $_POST['name'] = 'Test Student';
if ($scenario === 'missing') unset($_POST['password'], $_POST['confirm_password']);
if ($scenario === 'mismatch') $_POST['confirm_password'] = 'different-password';
if ($scenario === 'short') $_POST['password'] = $_POST['confirm_password'] = 'short';
if ($scenario === 'long') $_POST['password'] = $_POST['confirm_password'] = str_repeat('x',73);
if ($scenario === 'email') $_POST['email'] = 'not-an-email';
if ($scenario === 'csrf') $_POST['csrf'] = 'wrong';
if ($scenario === 'section') $_POST['course_section'] = 'Grade 8 - Section A';
if ($scenario === 'duplicate') $pdo->exec("INSERT INTO users (identifier,email,role,password) VALUES ('EXISTING','STUDENT@example.com','student','original-hash')");
\PHPMailer\PHPMailer\PHPMailer::$fail = $scenario === 'smtp_failed';
\PHPMailer\PHPMailer\PHPMailer::$onSend = function () use ($pdo) {
    if (!$pdo->query("SELECT id FROM users WHERE identifier='TEST-001'")->fetchColumn()) throw new RuntimeException('Email sent before account creation');
};
$studentSectionsByGrade = get_student_sections_by_grade($pdo);
$action = 'quick_add_student';
$ajaxAction = 'add_student';
$error = ''; $message = '';
ob_start();
register_shutdown_function(function () use ($pdo, $portal, $scenario) {
    ob_end_clean();
    $user = $pdo->query("SELECT * FROM users WHERE identifier='TEST-001'")->fetch(PDO::FETCH_ASSOC);
    $created = in_array($scenario, ['success', 'missing', 'mismatch', 'short', 'long', 'smtp_failed'], true);
    $ok = $created ? ($user && $user['role'] === 'student' && $user['email'] === 'student@example.com' && password_verify('password123', $user['password']) && $user['password'] !== 'password123') : !$user;
    $ok = $ok && \PHPMailer\PHPMailer\PHPMailer::$attempts === ($created ? 1 : 0);
    if ($created) {
        $mail = \PHPMailer\PHPMailer\PHPMailer::$last;
        $ok = $ok && $mail->recipient === 'student@example.com' && str_contains($mail->Body, 'https://school.example.com/attendance/login.php') && str_contains($mail->AltBody, 'password123');
        $notice = $portal === 'students' ? ($_SESSION['student_account_notice'] ?? '') : ($portal === 'register' ? ($_SESSION['registration_notice'] ?? '') : ($GLOBALS['message'] ?? ''));
        $ok = $ok && str_contains($notice, $scenario === 'smtp_failed' ? 'email could not be sent' : 'were sent to');
    }
    if ($scenario === 'duplicate') $ok = $ok && $pdo->query("SELECT password FROM users WHERE identifier='EXISTING'")->fetchColumn() === 'original-hash';
    if (!$ok) { fwrite(STDERR, "FAIL: $portal / $scenario\n"); exit(1); }
    echo "PASS: $portal / $scenario\n";
});
$source = file_get_contents(__DIR__ . '/../' . ($portal === 'register' ? 'register.php' : 'admin/' . ($portal === 'students' ? 'students.php' : 'dashboard.php')));
$start = strpos($source, $portal === 'students' ? "        if (\$ajaxAction === 'add_student'" : "        if (\$action === 'quick_add_student'");
$end = strpos($source, $portal === 'students' ? '// 2. Get Single Student' : '// 2. Quick Action:', $start);
if ($portal === 'register') {
    $start = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
    $end = strpos($source, '?>', $start);
}
try { eval(substr($source, $start, $end - $start)); }
catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
