<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';
require_once __DIR__ . '/config/mailer.php';
require_once __DIR__ . '/config/password_otp.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}
$_SESSION['recovery_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$success = $_SESSION['recovery_notice'] ?? '';
unset($_SESSION['recovery_notice']);
$emailInput = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
function recovery_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['recovery_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'start_over') {
            unset($_SESSION['password_recovery']);
        } elseif ($action === 'send_otp' || $action === 'resend_otp') {
            $email = $action === 'resend_otp' ? ($_SESSION['password_recovery']['email'] ?? '') : $emailInput;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter your registered email address.');
            $wait = 60 - (time() - (int)($_SESSION['recovery_last_request'] ?? 0));
            if ($wait > 0) throw new InvalidArgumentException("Please wait {$wait} seconds before requesting another OTP.");
            $_SESSION['recovery_last_request'] = time();
            $_SESSION['password_recovery'] = request_password_otp(get_db_connection(), $email, function ($address, $name, $otp) {
                return send_password_reset_otp($address, $name, $otp)['success'];
            });
            $_SESSION['recovery_notice'] = 'If this email is registered, a 6-digit OTP has been sent. Check your inbox or spam folder. The code expires in 10 minutes.';
        } elseif ($action === 'verify_otp') {
            $request = $_SESSION['password_recovery'] ?? null;
            if (!$request) throw new InvalidArgumentException('Enter your email and request an OTP first.');
            $otp = is_string($_POST['otp'] ?? null) ? trim($_POST['otp']) : '';
            verify_password_otp(get_db_connection(), $request['request_id'], $otp);
            session_regenerate_id(true);
            $_SESSION['password_recovery']['verified'] = true;
            $_SESSION['recovery_notice'] = 'Email verified. You can now create your new password.';
        } elseif ($action === 'reset_password') {
            $request = $_SESSION['password_recovery'] ?? null;
            if (empty($request['verified'])) throw new InvalidArgumentException('Verify your OTP before setting a new password.');
            $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
            $confirmation = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
            reset_password_with_otp(get_db_connection(), $request['request_id'], $password, $confirmation);
            unset($_SESSION['password_recovery'], $_SESSION['recovery_csrf']);
            session_regenerate_id(true);
            header('Location: login.php?reset=success');
            exit;
        } else {
            throw new InvalidArgumentException('Please select a valid action.');
        }
        header('Location: forgot_password.php');
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to complete your request right now. Please try again later or contact the school administrator.';
        if (!($e instanceof InvalidArgumentException)) error_log('Password recovery failed: ' . get_class($e));
    }
}
$request = $_SESSION['password_recovery'] ?? null;
$step = !$request ? 1 : (!empty($request['verified']) ? 3 : 2);
$cooldown = max(0, 60 - (time() - (int)($_SESSION['recovery_last_request'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Student Attendance Monitoring System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/password-recovery.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="recovery-page">
<div class="auth-layout">
    <div class="auth-content">
        <div class="auth-hero">
            <div class="auth-brand">
                <span class="chip"><img src="assets/images/logo.png" alt="School logo" width="32" height="32"></span>
                <span>ODB MONTESSORI SCHOOL OF NOVALICHES</span>
            </div>
            <div class="recovery-hero-copy">
                <div class="auth-hero-badge">Account Recovery</div>
                <h1>Get back to<br>your workspace.</h1>
                <p>Verify your registered email with a one-time code, then choose a new password.</p>
                <div class="metrics-strip"><div class="metric-pill"><i class="fa-solid fa-envelope"></i> Email OTP</div><div class="metric-pill"><i class="fa-regular fa-clock"></i> 10 minutes</div></div>
            </div>
            <div class="recovery-footer">Your school account, protected.</div>
        </div>
    </div>
    <main class="auth-side">
        <div class="auth-card">
            <ol class="recovery-steps" aria-label="Password recovery progress">
                <?php foreach ([1 => 'Email', 2 => 'Verify OTP', 3 => 'Password'] as $number => $label): ?>
                <li class="<?= $number <= $step ? 'reached' : '' ?>"<?= $number === $step ? ' aria-current="step"' : '' ?>><span><?= $number ?></span><?= $label ?></li>
                <?php endforeach; ?>
            </ol>
            <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= recovery_escape($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success" role="status"><?= recovery_escape($success) ?></div><?php endif; ?>
            <?php if ($step === 1): ?>
                <h2>Forgot password?</h2>
                <p class="sub">Enter the email registered to your account. We will send a 6-digit OTP to that inbox.</p>
                <form method="post" action="forgot_password.php" data-busy-label="Sending OTP...">
                    <input type="hidden" name="csrf" value="<?= recovery_escape($_SESSION['recovery_csrf']) ?>">
                    <input type="hidden" name="action" value="send_otp">
                    <div class="field-group"><label for="email">Registered email address</label><input class="auth-input" id="email" type="email" name="email" value="<?= recovery_escape($emailInput) ?>" maxlength="100" placeholder="you@gmail.com" required autocomplete="email" autofocus></div>
                    <button class="auth-submit" type="submit">Send OTP <i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                </form>
            <?php elseif ($step === 2): ?>
                <h2>Check your email</h2>
                <p class="sub">Enter the 6-digit code for <strong class="recovery-email"><?= recovery_escape($request['email']) ?></strong>. Use the latest code within 10 minutes.</p>
                <form method="post" action="forgot_password.php" data-busy-label="Verifying...">
                    <input type="hidden" name="csrf" value="<?= recovery_escape($_SESSION['recovery_csrf']) ?>">
                    <input type="hidden" name="action" value="verify_otp">
                    <div class="field-group"><label for="otp">One-time password (OTP)</label><input class="auth-input otp-input" id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus aria-describedby="otp-help"><small id="otp-help">You can paste all six digits here. Do not share your code.</small></div>
                    <button class="auth-submit" type="submit">Verify OTP <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                </form>
                <div class="recovery-secondary">
                    <p>Did not receive a code? Check Spam, then try resending.</p>
                    <form method="post" action="forgot_password.php" data-busy-label="Sending OTP...">
                        <input type="hidden" name="csrf" value="<?= recovery_escape($_SESSION['recovery_csrf']) ?>">
                        <input type="hidden" name="action" value="resend_otp">
                        <button class="recovery-link" type="submit" data-cooldown="<?= $cooldown ?>">Resend OTP</button>
                    </form>
                </div>
            <?php else: ?>
                <h2>Set a new password</h2>
                <p class="sub">Email verified for <strong class="recovery-email"><?= recovery_escape($request['email']) ?></strong>. Choose a password with at least 8 characters.</p>
                <form method="post" action="forgot_password.php" data-busy-label="Saving password...">
                    <input type="hidden" name="csrf" value="<?= recovery_escape($_SESSION['recovery_csrf']) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <div class="field-group"><label for="new_password">New password</label><div class="password-field"><input class="auth-input" type="password" id="new_password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password" autofocus><button type="button" class="password-toggle" data-target="new_password" aria-label="Show new password"><i class="fa-regular fa-eye" aria-hidden="true"></i></button></div></div>
                    <div class="field-group"><label for="confirm_password">Confirm new password</label><div class="password-field"><input class="auth-input" type="password" id="confirm_password" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password"><button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show confirmation password"><i class="fa-regular fa-eye" aria-hidden="true"></i></button></div></div>
                    <button class="auth-submit" type="submit">Save new password</button>
                </form>
            <?php endif; ?>
            <?php if ($step > 1): ?>
                <form method="post" action="forgot_password.php" class="recovery-start-over"><input type="hidden" name="csrf" value="<?= recovery_escape($_SESSION['recovery_csrf']) ?>"><input type="hidden" name="action" value="start_over"><button class="recovery-link" type="submit"><?= $step === 2 ? 'Use a different email' : 'Start again / Request a new OTP' ?></button></form>
            <?php endif; ?>
            <div class="recovery-back"><a href="login.php" class="inline-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a></div>
        </div>
    </main>
</div>
<script src="assets/js/password-recovery.js" defer></script>
</body>
</html>
