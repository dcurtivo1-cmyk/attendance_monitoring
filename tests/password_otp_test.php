<?php
require __DIR__ . '/../config/password_otp.php';
class OtpTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function rejected(callable $action, string $message) {
    try { $action(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$pdo = new OtpTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT);
 CREATE TABLE password_reset_otps (request_id TEXT PRIMARY KEY, user_id INTEGER, otp_hash TEXT, created_at INTEGER, expires_at INTEGER, attempts INTEGER DEFAULT 0, verified_at INTEGER, used_at INTEGER);');
$pdo->prepare('INSERT INTO users VALUES (1, ?, ?, ?)')->execute(['Student', 'student@gmail.com', password_hash('old-password', PASSWORD_BCRYPT)]);
$code = '';
$deliveries = 0;
$deliver = function ($email, $name, $otp) use (&$code, &$deliveries) {
    check($email === 'student@gmail.com', 'Use the registered email address');
    check(preg_match('/^[0-9]{6}$/D', $otp) === 1, 'OTP must have six digits');
    $code = $otp; $deliveries++; return true;
};
$now = 1800000000;
$request = request_password_otp($pdo, 'STUDENT@gmail.com', $deliver, $now);
$row = $pdo->query('SELECT * FROM password_reset_otps')->fetch(PDO::FETCH_ASSOC);
check($row['otp_hash'] !== $code && password_verify($code, $row['otp_hash']), 'Store a hash only');
rejected(fn() => reset_password_with_otp($pdo, $request['request_id'], 'new-password', 'new-password', $now), 'Unverified reset must fail');
rejected(fn() => request_password_otp($pdo, 'student@gmail.com', $deliver, $now + 59), 'Resend cooldown must apply');
$unknown = request_password_otp($pdo, 'unknown@gmail.com', $deliver, $now);
check($deliveries === 1, 'Unknown address must not receive mail');
rejected(fn() => verify_password_otp($pdo, $unknown['request_id'], $code, $now), 'Unknown request must fail');
rejected(fn() => verify_password_otp($pdo, $request['request_id'], 'bad', $now), 'Incorrect code must fail');
check((int)$pdo->query('SELECT attempts FROM password_reset_otps')->fetchColumn() === 1, 'Failed attempt must persist');
verify_password_otp($pdo, $request['request_id'], $code, $now + 1);
rejected(fn() => verify_password_otp($pdo, $request['request_id'], $code, $now + 2), 'OTP verification must be single use');
rejected(fn() => reset_password_with_otp($pdo, $request['request_id'], 'new-password', 'different', $now + 2), 'Mismatch must fail');
reset_password_with_otp($pdo, $request['request_id'], '  new-password  ', '  new-password  ', $now + 3);
$hash = $pdo->query('SELECT password FROM users')->fetchColumn();
check(password_verify('  new-password  ', $hash) && !password_verify('old-password', $hash), 'New password replaces old and preserves spaces');
rejected(fn() => reset_password_with_otp($pdo, $request['request_id'], 'another-password', 'another-password', $now + 4), 'Reset grant cannot be reused');

$pdo->exec('DELETE FROM password_reset_otps');
$request = request_password_otp($pdo, 'student@gmail.com', $deliver, $now);
$originalCode = $code;
try {
    request_password_otp($pdo, 'student@gmail.com', fn() => false, $now + 61);
    throw new LogicException('Mail failure should throw');
} catch (RuntimeException $e) {}
check((int)$pdo->query('SELECT COUNT(*) FROM password_reset_otps')->fetchColumn() === 1, 'Failed delivery must roll back');
verify_password_otp($pdo, $request['request_id'], $originalCode, $now + 62);
$new = request_password_otp($pdo, 'student@gmail.com', $deliver, $now + 63);
rejected(fn() => reset_password_with_otp($pdo, $request['request_id'], 'new-password', 'new-password', $now + 64), 'Resend invalidates previous grant');
$wrong = $code === '000000' ? '111111' : '000000';
for ($i = 0; $i < 5; $i++) rejected(fn() => verify_password_otp($pdo, $new['request_id'], $wrong, $now + 65), 'Wrong code must fail');
rejected(fn() => verify_password_otp($pdo, $new['request_id'], $code, $now + 66), 'Correct code must fail after five attempts');
$pdo->exec('DELETE FROM password_reset_otps');
$request = request_password_otp($pdo, 'student@gmail.com', $deliver, $now);
rejected(fn() => verify_password_otp($pdo, $request['request_id'], $code, $now + 600), 'Expiry boundary must fail');
for ($i = 1; $i < 5; $i++) request_password_otp($pdo, 'student@gmail.com', $deliver, $now + $i * 61);
rejected(fn() => request_password_otp($pdo, 'student@gmail.com', $deliver, $now + 305), 'Hourly account limit must apply');
echo "PASS: issue, hashed storage, unknown account, cooldown, attempts, verification, expiry, resend, mail failure, password update, reuse and hourly limits.\n";
