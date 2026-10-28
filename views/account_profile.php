<?php
if (!isset($profileRole) || !in_array($profileRole, ['student', 'professor'], true)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/account_profile.php';
require_auth([$profileRole]);
$pdo = get_db_connection();
$userId = (int)$_SESSION['user_id'];
$_SESSION['profile_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $updated = save_account_profile($pdo, $userId, $profileRole, $_POST, $_SESSION['profile_csrf']);
        $_SESSION['user_name'] = $updated['name'];
        $_SESSION['user_email'] = $updated['email'];
        session_regenerate_id(true);
        $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['profile_success'] = ($_POST['action'] ?? '') === 'password'
            ? 'Password changed successfully. Use your new password the next time you sign in.'
            : 'Your profile has been updated.';
        header('Location: profile.php');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Profile update failed: ' . $e->getMessage());
        $error = 'We could not save your changes. Please try again.';
    }
}
$stmt = $pdo->prepare('SELECT id, identifier, name, first_name, middle_initial, last_name, email, grade_level, course_section, guardian_contact FROM users WHERE id = ? AND role = ?');
$stmt->execute([$userId, $profileRole]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    http_response_code(403);
    exit('Account unavailable. Please sign in again.');
}
$isStudent = $profileRole === 'student';
$teacherAccount = $account;
$teacher = false;
if (!$isStudent) {
    $stmt = $pdo->prepare('SELECT contact_number FROM teachers WHERE user_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$userId]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}
$displayName = $isStudent ? $account['name'] : professor_display_name($account['name']);
$fields = $account;
$fields['name'] = $displayName;
$fields['contact'] = $isStudent ? $account['guardian_contact'] : ($teacher['contact_number'] ?? '');
// Older accounts may have only a full name. Prefill editable name parts for them.
if ($isStudent && empty($fields['first_name']) && empty($fields['last_name'])) {
    $parts = preg_split('/\s+/', trim($account['name']));
    $fields['last_name'] = count($parts) > 1 ? array_pop($parts) : '';
    $fields['first_name'] = implode(' ', $parts);
}
if ($error !== '' && ($_POST['action'] ?? '') === 'profile') {
    foreach (['name', 'first_name', 'middle_initial', 'last_name', 'email', 'contact'] as $key) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) $fields[$key] = $_POST[$key];
    }
}
function profile_escape($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
$portalTitle = $isStudent ? 'Student Portal' : 'Teacher Portal';
$nav = $isStudent ? [
    ['dashboard.php?view=dashboard', 'fa-house', 'Dashboard'],
    ['dashboard.php?view=scanner', 'fa-camera', 'QR Scanner'],
    ['dashboard.php?view=subjects', 'fa-book-open', 'My Subjects'],
    ['dashboard.php?view=attendance', 'fa-clipboard-check', 'Attendance Records'],
] : [
    ['dashboard.php', 'fa-qrcode', 'Dashboard & QR Code'],
    ['manual_attendance.php', 'fa-clipboard-user', 'Student Attendance'],
    ['subjects.php', 'fa-book-open', 'My Subjects & Students'],
    ['reports.php', 'fa-chart-line', 'Attendance Reports'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?= profile_escape($portalTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/account-profile.css">
    <?php if (!$isStudent): ?>
        <link rel="stylesheet" href="../assets/css/teacher-profile-layout.css">
        <link rel="stylesheet" href="../assets/css/teacher-account.css">
    <?php endif; ?>
</head>
<body>
<div class="layout-shell">
    <aside class="sidebar">
        <div class="brand-box">
            <img src="../assets/images/logo.png" alt="School Logo" class="brand-icon">
            <div><div class="brand-title">ODB Montessori School of Novaliches</div><span class="brand-sub"><?= profile_escape($portalTitle) ?></span></div>
        </div>
        <div class="nav-section-title"><?= $isStudent ? 'Menu' : 'Workspace' ?></div>
        <nav class="sidebar-nav" aria-label="Portal navigation">
            <?php foreach ($nav as [$href, $icon, $label]): ?>
                <a href="<?= profile_escape($href) ?>" class="nav-link"><i class="fa-solid <?= profile_escape($icon) ?>" aria-hidden="true"></i><span><?= profile_escape($label) ?></span></a>
            <?php endforeach; ?>
            <a href="profile.php" class="nav-link active" aria-current="page"><i class="fa-solid fa-user-gear" aria-hidden="true"></i><span>Profile</span></a>
        </nav>
        <div class="sidebar-footer">
            <?php if ($isStudent): ?>
                <strong><?= profile_escape($displayName) ?></strong>
            <?php else: ?>
                <?php require __DIR__ . '/teacher_account_card.php'; ?>
            <?php endif; ?>
        </div>
    </aside>
    <div class="main-panel">
        <header class="top-header">
            <strong class="top-header-title"><i class="fa-solid fa-user-gear" aria-hidden="true"></i> Profile</strong>
            <div class="top-header-actions">
                <a href="../logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Logout</span></a>
                <?php if (!$isStudent) require __DIR__ . '/teacher_header_profile.php'; ?>
            </div>
        </header>
        <main class="content-container">
            <h1><?= $isStudent ? 'Student' : 'Teacher' ?> Profile</h1>
            <p class="page-intro">Manage your personal information and password.</p>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= profile_escape($error) ?></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="notice success" role="status"><?= profile_escape($success) ?></div><?php endif; ?>
            <div class="profile-grid">
                <section class="card" aria-labelledby="personal-title">
                    <h2 id="personal-title">Personal Information</h2>
                    <form method="post" action="profile.php">
                        <input type="hidden" name="csrf" value="<?= profile_escape($_SESSION['profile_csrf']) ?>">
                        <input type="hidden" name="action" value="profile">
                        <?php if ($isStudent): ?>
                            <div class="field-row">
                                <div class="field"><label for="first_name">First Name</label><input id="first_name" name="first_name" autocomplete="given-name" maxlength="50" required value="<?= profile_escape($fields['first_name']) ?>"></div>
                                <div class="field"><label for="last_name">Last Name</label><input id="last_name" name="last_name" autocomplete="family-name" maxlength="50" required value="<?= profile_escape($fields['last_name']) ?>"></div>
                            </div>
                            <div class="field"><label for="middle_initial">Middle Initial <span class="muted">(optional)</span></label><input id="middle_initial" name="middle_initial" maxlength="10" value="<?= profile_escape($fields['middle_initial']) ?>"></div>
                        <?php else: ?>
                            <div class="field"><label for="name">Full Name</label><input id="name" name="name" autocomplete="name" maxlength="100" required value="<?= profile_escape($fields['name']) ?>"></div>
                        <?php endif; ?>
                        <div class="field"><label for="email">Email Address</label><input type="email" id="email" name="email" autocomplete="email" maxlength="100" required value="<?= profile_escape($fields['email']) ?>"><small>Used to sign in and receive password recovery codes.</small></div>
                        <?php if ($isStudent || $teacher): ?>
                            <div class="field"><label for="contact"><?= $isStudent ? 'Guardian Contact Number' : 'Contact Number' ?></label><input type="tel" id="contact" name="contact" maxlength="50" value="<?= profile_escape($fields['contact']) ?>"></div>
                        <?php endif; ?>
                        <div class="field"><label for="email_password">Current Password <span class="muted">(when changing email)</span></label><input type="password" id="email_password" name="current_password" autocomplete="current-password" aria-describedby="email-password-help"><small id="email-password-help">Required only if you change your email address.</small></div>
                        <button type="submit" class="btn-primary">Save Profile</button>
                    </form>
                    <div class="school-details">
                        <h3>School Information</h3>
                        <dl>
                            <div><dt><?= $isStudent ? 'Student' : 'Employee' ?> ID</dt><dd><?= profile_escape($account['identifier']) ?></dd></div>
                            <?php if ($isStudent): ?>
                                <div><dt>Grade Level</dt><dd><?= profile_escape($account['grade_level'] ?: 'Unassigned') ?></dd></div>
                                <div><dt>Section</dt><dd><?= profile_escape($account['course_section'] ?: 'Unassigned') ?></dd></div>
                            <?php endif; ?>
                        </dl>
                        <small>Contact the administrator to correct your school information.</small>
                    </div>
                </section>
                <section class="card" aria-labelledby="password-title">
                    <h2 id="password-title">Change Password</h2>
                    <form method="post" action="profile.php" id="password-form">
                        <input type="hidden" name="csrf" value="<?= profile_escape($_SESSION['profile_csrf']) ?>">
                        <input type="hidden" name="action" value="password">
                        <div class="field"><label for="current_password">Current Password</label><input type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
                        <div class="field"><label for="new_password">New Password</label><input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required aria-describedby="password-help"><small id="password-help">Use at least 8 characters and a password you do not use elsewhere.</small></div>
                        <div class="field"><label for="confirm_password">Confirm New Password</label><input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
                        <label class="show-password"><input type="checkbox" id="show-passwords"> Show passwords</label>
                        <button type="submit" class="btn-primary">Change Password</button>
                    </form>
                </section>
            </div>
        </main>
    </div>
</div>
<script src="../assets/js/account-profile.js"></script>
</body>
</html>
