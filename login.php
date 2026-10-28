<?php
// login.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

// Redirect if already logged in
if (is_logged_in()) {
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'student') {
        header("Location: " . base_url('student/dashboard.php'));
        exit;
    } elseif ($role === 'professor') {
        header("Location: " . base_url('professor/dashboard.php'));
        exit;
    } elseif ($role === 'admin') {
        header("Location: " . base_url('admin/dashboard.php'));
        exit;
    }
}

$error = '';
$success = '';

if (isset($_GET['registered'])) {
    $success = 'Registration successful! ' . ($_SESSION['registration_notice'] ?? 'You can now log in with your email address and password.');
    unset($_SESSION['registration_notice']);
}
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = "Password reset successfully! You can now log in with your new password.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = ($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $pdo = get_db_connection();
            // Authenticate strictly via Email Address and Password
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Upgrade the stored hash when the hashing cost changes
                if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                    $rehashed = password_hash($password, PASSWORD_BCRYPT);
                    $updateStmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
                    $updateStmt->execute([':password' => $rehashed, ':id' => $user['id']]);
                }

                session_regenerate_id(true);

                // Set Session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_identifier'] = $user['identifier'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['course_section'] = $user['course_section'];
                $_SESSION['qr_token'] = $user['qr_token'];

                // Redirect based on role
                if ($user['role'] === 'student') {
                    header("Location: " . base_url('student/dashboard.php'));
                    exit;
                } elseif ($user['role'] === 'professor') {
                    header("Location: " . base_url('professor/dashboard.php'));
                    exit;
                } else {
                    header("Location: " . base_url('admin/dashboard.php'));
                    exit;
                }
            } else {
                $error = "Invalid Email Address or Password.";
            }
        } catch (PDOException $e) {
            error_log("Login database failure: " . $e->getMessage());
            $error = "Sign in is temporarily unavailable. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Student Attendance Monitoring System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="auth-layout">
    <div class="auth-content">
        <div class="auth-hero">
            <div class="auth-brand">
                <span class="chip" style="background:#ffffff;padding:2px;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;"><img src="assets/images/logo.png" alt="School Logo" style="width:26px;height:26px;object-fit:contain;border-radius:50%;"></span>
                <span>ODB MONTESSORI SCHOOL OF NOVALICHES</span>
            </div>

            <div>
                <div class="auth-hero-badge">Secure school operations</div>
                <h1>Every learner,<br>accounted for.</h1>
                <p>A clear, dependable workspace for student records, daily attendance, and school-wide reporting.</p>

                <div class="metrics-strip" style="margin-top: 32px;">
                    <div class="metric-pill"><i class="fa-regular fa-clock"></i> 2 min</div>
                    <div class="metric-pill"><i class="fa-solid fa-users"></i> 486 active students</div>
                    <div class="metric-pill"><i class="fa-solid fa-chart-column"></i> 96.2% term attendance</div>
                </div>
            </div>

            <div style="font-size: 0.82rem; opacity: 0.9;">ODB MONTESSORI SCHOOL OF NOVALICHES - Student information is protected and access is monitored.</div>
        </div>
    </div>

    <div class="auth-side">
        <div class="auth-card">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="margin-bottom: 18px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success" style="margin-bottom: 18px;">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <span class="eyebrow">Authorized access</span>
            <h2>Welcome back</h2>
            <div class="sub">Sign in with your registered email address and password.</div>

            <form action="login.php" method="POST">
                <div class="field-group">
                    <label for="email">Email address</label>
                    <input class="auth-input" type="email" id="email" name="email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" placeholder="student@school.edu.ph" required autocomplete="email">
                </div>

                <div class="field-group">
                    <label for="password">Password</label>
                    <div class="password-field">
                        <input class="auth-input" type="password" id="password" name="password" placeholder="••••••••••••" required autocomplete="current-password">
                        <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                            <i class="fa-regular fa-eye-slash"></i>
                        </button>
                    </div>
                </div>

                <div class="auth-options">
                    <label class="checkbox-row">
                        <input type="checkbox" name="remember" checked>
                        <span>Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="inline-link">Forgot password?</a>
                </div>

                <button type="submit" class="auth-submit">Sign in to workspace <i class="fa-solid fa-arrow-right"></i></button>
            </form>

            <div class="auth-help">
                <i class="fa-regular fa-circle-question"></i>
                <span>Need access? <a href="register.php">Contact the school administrator.</a></span>
            </div>

            <div class="auth-resources">
                <span><i class="fa-regular fa-circle-dot"></i> Administrator</span>
                <span class="bullet"></span>
                <span><i class="fa-regular fa-circle-dot"></i> Teacher</span>
                <span class="bullet"></span>
                <span><i class="fa-regular fa-circle-dot"></i> School Staff</span>
            </div>
        </div>
    </div>
</div>

<script>
    document.querySelector('.password-toggle')?.addEventListener('click', function () {
        const input = document.getElementById('password');
        const icon = this.querySelector('i');
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        icon.className = isPassword ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
    });
</script>

</body>
</html>
