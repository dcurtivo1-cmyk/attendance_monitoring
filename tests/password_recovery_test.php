<?php
// Transport contract test: no external emails are sent.
namespace PHPMailer\PHPMailer {
    #[\AllowDynamicProperties]
    class PHPMailer {
        const ENCRYPTION_SMTPS = 'ssl';
        const ENCRYPTION_STARTTLS = 'tls';
        public static $fail = false;
        public static $last;
        public function __construct($exceptions = true) { self::$last = $this; }
        public function isSMTP() {}
        public function setFrom($email, $name) {}
        public function addAddress($email, $name) {}
        public function isHTML($html) {}
        public function send() {
            if (self::$fail) throw new \RuntimeException('Simulated transport failure');
            return true;
        }
    }
}
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    require __DIR__ . '/../config/mailer.php';
    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }
    putenv('SMTP_USER=test@example.com');
    putenv('SMTP_PASS=test-only');
    putenv('SMTP_SECURE=tls');
    $otp = '012345';
    $result = send_password_reset_otp('student@example.com', '<Student>', $otp);
    check($result['success'] === true, 'SMTP acceptance must succeed');
    $mail = \PHPMailer\PHPMailer\PHPMailer::$last;
    check(strpos($mail->Body, $otp) !== false && strpos($mail->AltBody, $otp) !== false, 'Both mail formats must include the OTP');
    check(strpos($mail->Body, 'href=') === false && strpos($mail->Body, 'token=') === false, 'Email must not contain reset links');
    check(strpos($mail->Body, '&lt;Student&gt;') !== false, 'HTML names must be escaped');
    check($mail->SMTPSecure === 'tls', 'Transport must use TLS');
    \PHPMailer\PHPMailer\PHPMailer::$fail = true;
    check(send_password_reset_otp('student@example.com', 'Student', $otp)['success'] === false, 'SMTP failure must fail closed');
    $password = '  Reset password  ';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    check(password_verify($password, $hash), 'Password must verify exactly');
    check(!password_verify(trim($password), $hash), 'Password whitespace must be preserved');
    echo "PASS: SMTP success/failure, OTP-only email, escaping, TLS and password hashing.\n";
}
