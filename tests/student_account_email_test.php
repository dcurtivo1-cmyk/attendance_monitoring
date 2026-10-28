<?php
// Simulated SMTP only; never sends to external mailboxes.
require __DIR__ . '/student_mail_stub.php';
require __DIR__ . '/../config/student_account.php';
use PHPMailer\PHPMailer\PHPMailer;
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$scenario = $argv[1] ?? 'success';
putenv('SMTP_USER=' . ($scenario === 'missing_smtp' ? '' : 'test@example.com'));
putenv('SMTP_PASS=' . ($scenario === 'missing_smtp' ? '' : 'test-only'));
putenv('APP_URL=' . ($scenario === 'invalid_url' ? 'javascript:alert(1)' : ''));
$result = student_account_notification('student@example.com', '<Student & Test>');
if (in_array($scenario, ['missing_smtp', 'invalid_url'], true)) {
    check(!$result['success'] && PHPMailer::$attempts === 0, 'Configuration failure must not report delivery or send mail');
} else {
    check($result['success'], 'Configured SMTP must accept welcome email');
    $mail = PHPMailer::$last;
    foreach ([$mail->Body, $mail->AltBody] as $body) {
        check(str_contains($body, 'http://localhost/Attendance_Monitoring/login.php'), 'Use configured local login URL');
        check(str_contains($body, 'student@example.com') && str_contains($body, 'password123'), 'Include correct login credentials');
        check(str_contains($body, 'optional'), 'Password changes must stay optional');
    }
    check(str_contains($mail->Body, '&lt;Student &amp; Test&gt;'), 'Escape HTML names');
    check($mail->recipient === 'student@example.com' && $mail->SMTPSecure === 'tls', 'Correct recipient and encrypted SMTP');
    PHPMailer::$fail = true;
    check(!student_account_notification('student@example.com', 'Student')['success'], 'SMTP failure must be reported');
}
echo "PASS: student email / $scenario\n";
