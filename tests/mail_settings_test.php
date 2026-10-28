<?php
require __DIR__ . '/student_mail_stub.php';
require __DIR__ . '/../config/mail_settings.php';
use PHPMailer\PHPMailer\PHPMailer;
function check($condition, $label) { if (!$condition) throw new RuntimeException($label); }
function rejected(callable $call, string $label) {
    try { $call(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($label);
}
putenv('SMTP_USER=test@example.com');
putenv('SMTP_PASS=abcdefghijklmnop');
$base = get_email_config(true);
$candidate = gmail_settings(['sender_email'=>'sender@gmail.com', 'app_password'=>'abcd efgh ijkl mnop'], $base);
check($candidate['smtp_pass'] === 'abcdefghijklmnop' && $candidate['from_email'] === 'sender@gmail.com', 'Normalize password and match sender');
check($candidate['smtp_host'] === 'smtp.gmail.com' && $candidate['smtp_port'] === 587 && $candidate['smtp_secure'] === 'tls', 'Use Gmail TLS settings');
check(gmail_settings(['sender_email'=>'sender@gmail.com', 'app_password'=>''], $candidate)['smtp_pass'] === 'abcdefghijklmnop', 'Keep saved password');
rejected(fn()=>gmail_settings(['sender_email'=>'new@gmail.com','app_password'=>''], $candidate), 'A changed sender must supply its own password');
rejected(fn()=>gmail_settings(['sender_email'=>'invalid','app_password'=>'abcdefghijklmnop'], $candidate), 'Reject invalid email');
rejected(fn()=>gmail_settings(['sender_email'=>'sender@gmail.com','app_password'=>'short'], $candidate), 'Reject invalid app password');
$path = __DIR__ . '/../storage/mail-settings-test-' . bin2hex(random_bytes(8)) . '.php';
try {
    save_mail_settings($candidate, $path);
    ob_start(); $saved = require $path; $output = ob_get_clean();
    check($output === '' && $saved['smtp_pass'] === $candidate['smtp_pass'], 'Stored settings emit no credentials');
    check(!isset($saved['app_url']), 'Do not override unrelated settings');
    save_mail_settings(gmail_settings(['sender_email'=>'replacement@gmail.com','app_password'=>'ponmlkjihgfedcba'], $candidate), $path);
    check((require $path)['smtp_user'] === 'replacement@gmail.com', 'Replace settings atomically');
} finally { if (is_file($path)) unlink($path); }
$result = send_application_email('sender@gmail.com', 'Admin', 'Test', '<p>Test</p>', 'Test', $candidate);
check($result['success'] && PHPMailer::$last->Username === 'sender@gmail.com', 'Test candidate without changing saved settings');
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE users (name TEXT, email TEXT, password TEXT, role TEXT)');
$insert = $pdo->prepare('INSERT INTO users VALUES (?, ?, ?, ?)');
$hash = password_hash(STUDENT_DEFAULT_PASSWORD, PASSWORD_BCRYPT);
$insert->execute(['Student','student@example.com',$hash,'student']);
$insert->execute(['Changed','changed@example.com',password_hash('custom-password', PASSWORD_BCRYPT),'student']);
$insert->execute(['Admin','admin@example.com',$hash,'admin']);
check(resend_student_account_email($pdo, 'student@example.com')['success'], 'Resend default login details');
check(PHPMailer::$last->recipient === 'student@example.com', 'Only address registered student');
$attempts = PHPMailer::$attempts;
rejected(fn()=>resend_student_account_email($pdo, 'changed@example.com'), 'Never email an outdated default password');
rejected(fn()=>resend_student_account_email($pdo, 'admin@example.com'), 'Do not send student credentials to admin');
rejected(fn()=>resend_student_account_email($pdo, 'missing@example.com'), 'Reject unknown student');
check(PHPMailer::$attempts === $attempts, 'Rejected requests send no mail');
PHPMailer::$fail = true;
check(!resend_student_account_email($pdo, 'student@example.com')['success'], 'Report retry failure');
check($pdo->query("SELECT password FROM users WHERE email='student@example.com'")->fetchColumn() === $hash, 'Resend never changes password');
echo "PASS: Gmail validation, private storage, test transport, and safe student resend.\n";
