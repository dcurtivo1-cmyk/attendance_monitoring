<?php
// config/mailer.php
// Centralized Email Service for Password Reset & Notifications



// Load Composer autoloader for PHPMailer
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Get email configuration settings
 */
function get_email_config(bool $reload = false) {
    static $config = null;
    if ($config === null || $reload) {
        $configFile = __DIR__ . '/email.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            $config = [
                'smtp_host'      => 'smtp.gmail.com',
                'smtp_port'      => 587,
                'smtp_secure'    => 'tls',
                'smtp_user'      => '',
                'smtp_pass'      => '',
                'smtp_timeout'   => 8,
                'from_email'     => 'noreply@school.edu.ph',
                'from_name'      => 'ODB Montessori School of Novaliches',
            ];
        }
        $localFile = __DIR__ . '/../storage/mail-settings.php';
        if (is_file($localFile)) {
            $local = require $localFile;
            if (is_array($local)) $config = array_replace($config, $local);
        }
    }
    return $config;
}

/** Send only a one-time code. Codes are never returned, displayed or archived. */
function send_password_reset_otp($recipient_email, $recipient_name, $otp) {
    if (!preg_match('/^[0-9]{6}$/D', $otp)) throw new InvalidArgumentException('Invalid OTP format.');
    $config = get_email_config();
    $safeName = htmlspecialchars($recipient_name ?: 'Student', ENT_QUOTES, 'UTF-8');
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px 12px;background:#edf2ef;font-family:Arial,sans-serif;color:#23453b;">
<div style="max-width:520px;margin:auto;background:white;border-radius:16px;padding:28px;">
<p style="font-size:12px;font-weight:bold;color:#0d695f;">ODB MONTESSORI SCHOOL OF NOVALICHES</p>
<h1 style="font-size:24px;">Your password reset code</h1>
<p>Hello {$safeName},</p><p>Enter this one-time password on the password recovery page:</p>
<div style="padding:20px 8px;background:#e6f4ef;text-align:center;font-size:32px;font-weight:bold;letter-spacing:8px;">{$otp}</div>
<p>This code expires in <strong>10 minutes</strong>. Use it in the browser where you requested it.</p>
<p>Do not share this code. If you did not request a password reset, ignore this email.</p>
</div></body></html>
HTML;
    $textBody = "Hello {$recipient_name},\n\nYour ODB Attendance password reset code is: {$otp}\n\nEnter it on the password recovery page in the same browser. It expires in 10 minutes. Do not share this code. If you did not request it, ignore this email.";

    return send_application_email($recipient_email, $recipient_name, 'Your Password Reset OTP - ODB Attendance Monitoring', $htmlBody, $textBody);
}

function send_student_account_email(string $email, string $name, string $loginUrl, string $password): array {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:24px 12px;background:#edf2ef;font-family:Arial,sans-serif;color:#23453b;">
<div style="max-width:520px;margin:auto;background:white;border-radius:16px;padding:28px;">
<p style="font-size:12px;font-weight:bold;color:#0d695f;">ODB MONTESSORI SCHOOL OF NOVALICHES</p>
<h1 style="font-size:24px;">Your student account is ready</h1>
<p>Hello {$safeName},</p>
<p>Your Attendance Monitoring student account has been created. Here are your login details:</p>
<p><strong>Website:</strong> <a href="{$safeUrl}">{$safeUrl}</a><br>
<strong>Email:</strong> {$safeEmail}<br>
<strong>Default password:</strong> {$safePassword}</p>
<p><a href="{$safeUrl}" style="display:inline-block;padding:12px 20px;background:#0d695f;color:white;border-radius:8px;">Sign in to your account</a></p>
<p>You may change your password anytime in <strong>Profile &gt; Change Password</strong> after signing in. Changing it is optional.</p>
</div></body></html>
HTML;
    $textBody = "Hello {$name},\n\nYour Attendance Monitoring student account is ready.\n\nWebsite: {$loginUrl}\nEmail: {$email}\nDefault password: {$password}\n\nYou may change your password anytime in Profile > Change Password after signing in. Changing it is optional.";
    return send_application_email($email, $name, 'Your Student Account - ODB Attendance Monitoring', $htmlBody, $textBody);
}

/** Return SMTP acceptance without logging or archiving message contents. */
function send_application_email($recipient_email, $recipient_name, $subject, $htmlBody, $textBody, ?array $settings = null): array {
    $config = $settings ?? get_email_config();
    $sentSuccessfully = false;
    $failure = 'Email could not be sent. Check the sender settings and internet connection.';

    // Check if external SMTP credentials are provided
    $hasSmtpConfig = !empty($config['smtp_user']) && !empty($config['smtp_pass']);
    if (!$hasSmtpConfig) $failure = 'Gmail sender is not configured. Open Email Settings and enter the sender email and App Password.';

    if ($hasSmtpConfig && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'];
            $mail->Password   = $config['smtp_pass'];
            $mail->Port       = $config['smtp_port'];
            $mail->Timeout    = $config['smtp_timeout'];
            $mail->CharSet = 'UTF-8';

            if ($config['smtp_secure'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['smtp_secure'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                throw new RuntimeException('SMTP_SECURE must be tls or ssl.');
            }

            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($recipient_email, $recipient_name ?: $recipient_email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;

            $sentSuccessfully = $mail->send();

        } catch (\Throwable $e) {
            // Classify failures without returning provider output or credentials.
            $detail = strtolower($e->getMessage());
            if (str_contains($detail, 'authenticat') || str_contains($detail, 'password')) {
                $failure = 'Gmail rejected the login. Check the sender email and Google App Password.';
            } elseif (str_contains($detail, 'connect') || str_contains($detail, 'timed out')) {
                $failure = 'Could not connect to Gmail. Check the internet connection and allow SMTP port 587.';
            }
            error_log('SMTP transport failed.');
        }
    }

    if (!$sentSuccessfully) {
        error_log('Email delivery failed. Check SMTP configuration and provider logs.');
    }
    return ['success' => $sentSuccessfully, 'error' => $sentSuccessfully ? null : $failure];
}
