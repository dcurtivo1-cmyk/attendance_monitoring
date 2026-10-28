<?php
require_once __DIR__ . '/../config/auth_check.php';
require_auth(['admin']);
require_once __DIR__ . '/../config/mail_settings.php';
header('Cache-Control: no-store');
$_SESSION['mail_settings_csrf'] ??= bin2hex(random_bytes(32));
$notice = $_SESSION['mail_settings_notice'] ?? null;
unset($_SESSION['mail_settings_notice']);
$config = get_email_config();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['mail_settings_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
        }
        if (time() - ($_SESSION['mail_settings_last_send'] ?? 0) < 30) {
            throw new InvalidArgumentException('Wait 30 seconds before sending another email.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'save_test') {
            $candidate = gmail_settings($_POST, $config);
            $_SESSION['mail_settings_last_send'] = time();
            $result = send_application_email($candidate['smtp_user'], 'Administrator', 'Email setup test - ODB Attendance Monitoring', '<p>Your attendance system can now send email through this Gmail account.</p>', 'Your attendance system can now send email through this Gmail account.', $candidate);
            if (!$result['success']) throw new RuntimeException($result['error']);
            save_mail_settings($candidate);
            $notice = ['success' => true, 'message' => 'Gmail accepted the test email and your sender settings are saved. Check the sender inbox or Spam folder. You can now resend the student login email below.'];
        } elseif ($action === 'resend') {
            $_SESSION['mail_settings_last_send'] = time();
            $notice = resend_student_account_email(get_db_connection(), trim((string)($_POST['student_email'] ?? '')));
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        $notice = ['success' => false, 'message' => $e instanceof PDOException ? 'Could not look up the student. Please try again.' : $e->getMessage()];
    }
    $_SESSION['mail_settings_notice'] = $notice;
    header('Location: email_settings.php');
    exit;
}
$ready = !empty($config['smtp_user']) && !empty($config['smtp_pass']);
function mail_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Email Settings - ODB Attendance</title><link rel="stylesheet" href="../assets/css/style.css">
<style>
body{background:#eef4f1;color:#23453b}main{max-width:760px;margin:40px auto;padding:0 20px}nav{display:flex;gap:20px;margin-bottom:24px}h1{margin-bottom:8px}section{background:white;border:1px solid #dce7e2;border-radius:16px;padding:24px;margin:20px 0}p{margin-bottom:16px;line-height:1.6}label{display:block;font-weight:600;margin:16px 0 6px}input{width:100%;padding:12px;border:1px solid #b9cec5;border-radius:8px}button{margin-top:18px;background:#0d5b49;color:white;border:0;border-radius:8px;padding:12px 18px;cursor:pointer}button:disabled{opacity:.6}small{display:block;margin-top:8px;color:#526c61}.notice{padding:16px;border-radius:10px;background:#def7ec;margin-top:20px}.notice.error{background:#fde8e8}h2{font-size:20px;margin-bottom:12px}
</style></head>
<body><main>
<nav><a href="dashboard.php">Dashboard</a><a href="students.php">Student Records</a></nav>
<h1>Email Settings</h1><p>Connect the Gmail account that sends student login details and password recovery codes.</p>
<?php if ($notice): ?><div class="notice <?= $notice['success'] ? '' : 'error' ?>" role="status"><?= mail_escape($notice['message']) ?></div><?php endif; ?>
<section><h2><?= $ready ? 'Gmail sender configured' : 'Connect your Gmail sender' ?></h2>
<p>Enable 2-Step Verification on the sender Google account, then <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">create an App Password</a>. <a href="https://support.google.com/mail/answer/185833?hl=en" target="_blank" rel="noopener noreferrer">Google setup guide</a></p>
<form method="post">
<input type="hidden" name="csrf" value="<?= mail_escape($_SESSION['mail_settings_csrf']) ?>"><input type="hidden" name="action" value="save_test">
<label for="sender_email">Sender Gmail address</label><input type="email" id="sender_email" name="sender_email" value="<?= mail_escape($config['smtp_user']) ?>" maxlength="254" required autocomplete="off">
<label for="app_password">Google App Password</label><input type="password" id="app_password" name="app_password" autocomplete="new-password" maxlength="32" <?= $ready ? '' : 'required' ?>>
<small><?= $ready ? 'Leave blank to keep the saved password for the same sender.' : 'Paste the 16-letter App Password here. Spaces are accepted.' ?> This value is never displayed after saving.</small>
<button type="submit">Save and send test email</button><small>A test email will be sent to the sender address. Settings are saved when Gmail accepts it.</small>
</form></section>
<section><h2>Resend student login email</h2><p>For accounts that were created before email was connected. This sends the website link, email, and default password without changing the account.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= mail_escape($_SESSION['mail_settings_csrf']) ?>"><input type="hidden" name="action" value="resend">
<label for="student_email">Registered student email</label><input type="email" name="student_email" id="student_email" maxlength="100" required>
<button type="submit" <?= $ready ? '' : 'disabled' ?>>Send student login email</button><small>Available for students who still use the default password. Connect a sender first.</small></form></section>
</main><script>document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',()=>{const button=form.querySelector('button');button.disabled=true;button.textContent='Sending...';}));</script></body></html>
