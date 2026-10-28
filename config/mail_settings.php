<?php
require_once __DIR__ . '/student_account.php';

function gmail_settings(array $input, array $current): array {
    $sender = trim((string)($input['sender_email'] ?? ''));
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || strlen($sender) > 254) {
        throw new InvalidArgumentException('Enter a valid Gmail or Google Workspace sender address.');
    }
    $password = preg_replace('/\s+/', '', (string)($input['app_password'] ?? ''));
    if ($password === '' && $sender === ($current['smtp_user'] ?? '')) {
        $password = $current['smtp_pass'] ?? '';
    }
    if (!preg_match('/^[a-zA-Z]{16}$/D', $password)) {
        throw new InvalidArgumentException('Enter the 16-letter Google App Password. Spaces are allowed.');
    }
    return array_replace($current, [
        'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_secure' => 'tls',
        'smtp_user' => $sender, 'smtp_pass' => $password, 'from_email' => $sender,
    ]);
}

function save_mail_settings(array $settings, ?string $path = null): void {
    $path ??= __DIR__ . '/../storage/mail-settings.php';
    $saved = array_intersect_key($settings, array_flip(['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'from_email']));
    // Executable PHP returns no output when requested directly; storage also denies HTTP access.
    $contents = "<?php\n// Private mail credentials. Do not share or commit this file.\nreturn " . var_export($saved, true) . ";\n";
    $temporary = dirname($path) . '/mail-' . bin2hex(random_bytes(12)) . '.php';
    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not save email settings. Check storage folder permissions.');
        }
        if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function resend_student_account_email(PDO $pdo, string $email): array {
    if (student_account_credentials_error($email) !== null) throw new InvalidArgumentException('Enter a valid student email.');
    $query = $pdo->prepare("SELECT name, email, password FROM users WHERE email = ? AND role = 'student' LIMIT 1");
    $query->execute([$email]);
    $student = $query->fetch(PDO::FETCH_ASSOC);
    if (!$student) throw new InvalidArgumentException('No student account uses that email.');
    if (!password_verify(STUDENT_DEFAULT_PASSWORD, $student['password'])) {
        throw new InvalidArgumentException('This student already has a different password. Use Forgot Password on the login page if they need help signing in.');
    }
    return student_account_notification($student['email'], $student['name']);
}
