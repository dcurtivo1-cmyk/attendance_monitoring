<?php
// register.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier     = trim($_POST['identifier'] ?? '');
    $name           = trim($_POST['name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = trim($_POST['password'] ?? '');
    $course_section = trim($_POST['course_section'] ?? '');

    if (empty($identifier) || empty($name) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            $pdo = get_db_connection();

            // Check if student ID or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE identifier = :identifier OR email = :email LIMIT 1");
            $stmt->execute([':identifier' => $identifier, ':email' => $email]);
            if ($stmt->fetch()) {
                $error = "Student ID or Email is already registered!";
            } else {
                // Generate secure unique QR Token
                $qr_token = generate_qr_token($identifier);
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                // Insert student
                $insertStmt = $pdo->prepare("INSERT INTO users (identifier, name, email, password, role, course_section, qr_token) 
                                            VALUES (:identifier, :name, :email, :password, 'student', :course_section, :qr_token)");
                $insertStmt->execute([
                    ':identifier'     => $identifier,
                    ':name'           => $name,
                    ':email'          => $email,
                    ':password'       => $hashed_password,
                    ':course_section' => $course_section,
                    ':qr_token'       => $qr_token
                ]);

                $new_student_id = $pdo->lastInsertId();

                // Auto-enroll to available subjects for quick testing
                $subjectsStmt = $pdo->query("SELECT id FROM subjects");
                $subjects = $subjectsStmt->fetchAll();
                if (!empty($subjects)) {
                    $enrollStmt = $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
                    foreach ($subjects as $s) {
                        $enrollStmt->execute([$new_student_id, $s['id']]);
                    }
                }

                header("Location: " . base_url('login.php?registered=1'));
                exit;
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - Attendance System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-box" style="max-width: 480px;">
        <div class="auth-header">
            <div class="brand-icon" style="margin: 0 auto; width: 48px; height: 48px; font-size: 1.5rem;">
                <i class="fa-solid fa-user-plus"></i>
            </div>
            <h1>Create Student Account</h1>
            <p>Access your subjects, teachers, and QR attendance scanner</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <div class="form-group">
                <label class="form-label" for="identifier">
                    <i class="fa-solid fa-id-badge"></i> Student ID Number *
                </label>
                <input type="text" id="identifier" name="identifier" class="form-control" 
                       placeholder="e.g. STUD-2026-105" required
                       value="<?= isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : '' ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="name">
                    <i class="fa-solid fa-user"></i> Full Name *
                </label>
                <input type="text" id="name" name="name" class="form-control" 
                       placeholder="e.g. Juan Luna" required
                       value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="email">
                    <i class="fa-solid fa-envelope"></i> Email Address *
                </label>
                <input type="email" id="email" name="email" class="form-control" 
                       placeholder="e.g. juan.luna@student.edu.ph" required
                       value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="course_section">
                    <i class="fa-solid fa-graduation-cap"></i> Grade &amp; Section
                </label>
                <input type="text" id="course_section" name="course_section" class="form-control" 
                       placeholder="e.g. Section A - Bonifacio" 
                       value="<?= isset($_POST['course_section']) ? htmlspecialchars($_POST['course_section']) : '' ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">
                    <i class="fa-solid fa-lock"></i> Password *
                </label>
                <input type="password" id="password" name="password" class="form-control" 
                       placeholder="At least 6 characters" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1.5rem;">
                <i class="fa-solid fa-check"></i> Register Student Account
            </button>
        </form>

        <div style="margin-top: 1.5rem; text-align: center; font-size: 0.9rem; color: var(--gray-500);">
            Already have an account? <a href="login.php" style="font-weight: 600;">Sign In</a>
        </div>
    </div>
</div>

</body>
</html>
