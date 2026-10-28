<?php
// config/email.php
// SMTP & Email Delivery Configuration for Attendance Monitoring System

return [
    // Public application root, including its subfolder (without /login.php).
    // Override APP_URL when the site is accessible from other devices.
    'app_url'        => getenv('APP_URL') ?: 'http://localhost/Attendance_Monitoring',
    // SMTP Server Settings
    // For Gmail: smtp.gmail.com, port 587, secure 'tls', username and 16-character App Password
    // For Mailtrap: sandbox.smtp.mailtrap.io, port 2525
    'smtp_host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'smtp_port'       => (int)(getenv('SMTP_PORT') ?: 587),
    'smtp_secure'     => getenv('SMTP_SECURE') ?: 'tls', // 'tls', 'ssl', or ''
    'smtp_user'       => getenv('SMTP_USER') ?: '',      // Your SMTP Username/Email
    'smtp_pass'       => getenv('SMTP_PASS') ?: '',      // Your SMTP Password or App Password
    'smtp_timeout'    => (int)(getenv('SMTP_TIMEOUT') ?: 8), // seconds

    // Sender Information
    'from_email'      => getenv('MAIL_FROM_EMAIL') ?: (getenv('SMTP_USER') ?: 'noreply@school.edu.ph'),
    'from_name'       => getenv('MAIL_FROM_NAME') ?: 'ODB Montessori School of Novaliches',


];
