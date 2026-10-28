<?php
require_once __DIR__ . '/mailer.php';

const STUDENT_DEFAULT_PASSWORD = 'password123';

function student_account_credentials_error(string $email): ?string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        return 'Enter a valid student login email address (up to 100 characters).';
    }
    return null;
}

function student_account_notification(string $email, string $name): array {
    $sent = false;
    $deliveryError = 'Check the application URL and email settings.';
    try {
        $root = trim((string)(get_email_config()['app_url'] ?? ''));
        $loginUrl = $root !== '' ? rtrim($root, '/') . '/login.php' : base_url('login.php');
        if (!filter_var($loginUrl, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($loginUrl, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            throw new RuntimeException('Invalid application URL.');
        }
        $delivery = send_student_account_email($email, $name, $loginUrl, STUDENT_DEFAULT_PASSWORD);
        $sent = $delivery['success'];
        $deliveryError = $delivery['error'] ?? $deliveryError;
    } catch (Throwable $e) {
        error_log('Student account notification failed. Check application URL and SMTP configuration.');
    }
    return [
        'success' => $sent,
        'message' => $sent
            ? "Login details and the website link were sent to $email. The default password is password123; the student may change it in Profile."
            : "The account is ready, but the email could not be sent. $deliveryError Sign in with $email and default password password123. The student may change it in Profile.",
    ];
}
