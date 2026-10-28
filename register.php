<?php
// register.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';
require_once __DIR__ . '/config/student_sections.php';
require_once __DIR__ . '/config/student_account.php';
$studentSectionsByGrade = get_student_sections_by_grade(get_db_connection());

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier     = trim($_POST['identifier'] ?? '');
    $name           = trim($_POST['name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = STUDENT_DEFAULT_PASSWORD;
    $course_section = trim($_POST['course_section'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');

    if (empty($identifier) || empty($name) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (student_account_credentials_error($email) !== null) {
        $error = "Please enter a valid email address.";
    } elseif (!is_valid_student_section($studentSectionsByGrade, $grade_level, $course_section)) {
        $error = 'Choose a section that belongs to the selected grade level.';

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
                $insertStmt = $pdo->prepare("INSERT INTO users (identifier, name, email, password, role, course_section, grade_level, qr_token) 
                                            VALUES (:identifier, :name, :email, :password, 'student', :course_section, :grade_level, :qr_token)");
                $insertStmt->execute([
                    ':identifier'     => $identifier,
                    ':name'           => $name,
                    ':email'          => $email,
                    ':password'       => $hashed_password,
                    ':course_section' => $course_section,
                    ':grade_level' => $grade_level,
                    ':qr_token'       => $qr_token
                ]);

                $new_student_id = $pdo->lastInsertId();

                // Enroll in subjects for the selected grade level.
                $subjectsStmt = $pdo->prepare('SELECT s.id FROM subjects s JOIN grade_levels gl ON gl.id = s.grade_level_id WHERE gl.grade_name = ?');
                $subjectsStmt->execute([$grade_level]);
                $subjects = $subjectsStmt->fetchAll();
                if (!empty($subjects)) {
                    $enrollStmt = $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
                    foreach ($subjects as $s) {
                        $enrollStmt->execute([$new_student_id, $s['id']]);
                    }
                }

                $notification = student_account_notification($email, $name);
                $_SESSION['registration_notice'] = $notification['message'];

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
                <label class="form-label" for="register_grade">Grade Level *</label>
                <select id="register_grade" name="grade_level" class="form-control" required data-student-grade>
                    <option value="">Choose a grade level</option>
                    <?php foreach ($studentSectionsByGrade as $grade => $sections): ?><option value="<?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($grade) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="course_section">Section *</label>
                <select id="course_section" name="course_section" class="form-control" required data-student-section></select>
            </div>

            <div class="form-group">
                <p>Default password: <strong><?= htmlspecialchars(STUDENT_DEFAULT_PASSWORD) ?></strong></p>
                <small>Your website link, email, and default password will be emailed to you. You may change your password anytime in Profile.</small>
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

<script type="application/json" id="student-sections-data"><?= json_encode((object)$studentSectionsByGrade, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/js/student-sections.js"></script>
</body>
</html>
