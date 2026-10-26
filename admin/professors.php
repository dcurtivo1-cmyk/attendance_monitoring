<?php
// admin/professors.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

$error = '';
$success = '';

// Handle Delete Professor
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    if ($delId > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'professor'");
            $stmt->execute([$delId]);
            $success = "Professor account deleted successfully.";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Handle Add Professor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_professor') {
    $identifier = trim($_POST['identifier'] ?? '');
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = trim($_POST['password'] ?? '');

    if (empty($identifier) || empty($name) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            // Check if identifier or email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE identifier = :identifier OR email = :email LIMIT 1");
            $checkStmt->execute([':identifier' => $identifier, ':email' => $email]);
            if ($checkStmt->fetch()) {
                $error = "Employee ID or Email is already registered!";
            } else {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $qr_token = 'PROF_TOKEN_' . strtoupper(bin2hex(random_bytes(8)));

                $insertStmt = $pdo->prepare("
                    INSERT INTO users (identifier, name, email, password, role, course_section, qr_token)
                    VALUES (:identifier, :name, :email, :password, 'professor', NULL, :qr_token)
                ");
                $insertStmt->execute([
                    ':identifier' => $identifier,
                    ':name'       => $name,
                    ':email'      => $email,
                    ':password'   => $hashed_password,
                    ':qr_token'   => $qr_token
                ]);

                $success = "Professor account for <strong>" . htmlspecialchars($name) . "</strong> created successfully!";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Handle Edit / Reset Password for Professor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_professor') {
    $prof_id    = intval($_POST['prof_id'] ?? 0);
    $identifier = trim($_POST['identifier'] ?? '');
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = trim($_POST['new_password'] ?? '');

    if ($prof_id <= 0 || empty($identifier) || empty($name) || empty($email)) {
        $error = "Please fill in all required fields.";
    } else {
        try {
            // Check uniqueness except current user
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE (identifier = :identifier OR email = :email) AND id != :id LIMIT 1");
            $checkStmt->execute([':identifier' => $identifier, ':email' => $email, ':id' => $prof_id]);
            if ($checkStmt->fetch()) {
                $error = "Employee ID or Email is already taken by another account!";
            } else {
                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        $error = "New password must be at least 6 characters.";
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        $updateStmt = $pdo->prepare("UPDATE users SET identifier = ?, name = ?, email = ?, password = ? WHERE id = ? AND role = 'professor'");
                        $updateStmt->execute([$identifier, $name, $email, $hashed_password, $prof_id]);
                        $success = "Professor details and password updated successfully!";
                    }
                } else {
                    $updateStmt = $pdo->prepare("UPDATE users SET identifier = ?, name = ?, email = ? WHERE id = ? AND role = 'professor'");
                    $updateStmt->execute([$identifier, $name, $email, $prof_id]);
                    $success = "Professor details updated successfully!";
                }
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Fetch Editing Professor if requested
$editingProf = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'professor'");
    $stmt->execute([$editId]);
    $editingProf = $stmt->fetch();
}

// Fetch All Professors with Subject Count
$professorsStmt = $pdo->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM subjects s WHERE s.professor_id = u.id) as total_subjects,
           (SELECT COUNT(DISTINCT a.student_id) FROM attendance a WHERE a.scanned_by = u.id) as total_students_scanned
    FROM users u
    WHERE u.role = 'professor'
    ORDER BY u.created_at DESC
");
$professors = $professorsStmt->fetchAll();

// Auto-suggest next Employee ID
$nextEmpId = 'PROF-' . date('Y') . '-' . str_pad((count($professors) + 1), 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Professors - Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- Admin Navbar -->
<nav class="navbar">
    <div class="brand">
        <div class="brand-icon" style="background: linear-gradient(135deg, #dc2626, #f59e0b);">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <span>Admin Portal</span>
    </div>
    <ul class="nav-links">
        <li><a href="dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li><a href="professors.php" class="active"><i class="fa-solid fa-chalkboard-user"></i> Manage Professors</a></li>
        <li><a href="subjects.php"><i class="fa-solid fa-book-open"></i> All Subjects</a></li>
        <li><a href="students.php"><i class="fa-solid fa-user-graduate"></i> Students</a></li>
        <li>
            <div class="user-badge">
                <span class="role-pill admin">Admin</span>
                <span><?= htmlspecialchars($user['name']) ?></span>
            </div>
        </li>
        <li><a href="../logout.php" style="color: var(--danger);"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
    </ul>
</nav>

<div class="container">
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <span><?= $success ?></span>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <!-- Form: Create or Edit Professor -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <?php if ($editingProf): ?>
                        <i class="fa-solid fa-user-pen" style="color: var(--warning);"></i> Edit Professor Account
                    <?php else: ?>
                        <i class="fa-solid fa-user-plus" style="color: var(--primary);"></i> Provision New Professor Account
                    <?php endif; ?>
                </div>
                <?php if ($editingProf): ?>
                    <a href="professors.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
                <?php endif; ?>
            </div>

            <form action="professors.php" method="POST">
                <?php if ($editingProf): ?>
                    <input type="hidden" name="action" value="edit_professor">
                    <input type="hidden" name="prof_id" value="<?= $editingProf['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="action" value="add_professor">
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label" for="identifier">
                        <i class="fa-solid fa-id-badge"></i> Employee ID / Faculty ID *
                    </label>
                    <input type="text" id="identifier" name="identifier" class="form-control"
                           placeholder="e.g. PROF-2026-002" required
                           value="<?= htmlspecialchars($editingProf['identifier'] ?? $nextEmpId) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="name">
                        <i class="fa-solid fa-user"></i> Full Name (with Title) *
                    </label>
                    <input type="text" id="name" name="name" class="form-control"
                           placeholder="e.g. Prof. Maria Santos" required
                           value="<?= htmlspecialchars($editingProf['name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">
                        <i class="fa-solid fa-envelope"></i> Official School Email *
                    </label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="e.g. maria.santos@school.edu.ph" required
                           value="<?= htmlspecialchars($editingProf['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">
                        <i class="fa-solid fa-lock"></i>
                        <?= $editingProf ? 'New Password (leave blank to keep current)' : 'Account Password *' ?>
                    </label>
                    <input type="password" id="password" name="<?= $editingProf ? 'new_password' : 'password' ?>"
                           class="form-control"
                           placeholder="<?= $editingProf ? 'Enter new password to reset' : 'e.g. password123' ?>"
                           <?= $editingProf ? '' : 'required' ?> minlength="6">
                    <small style="color: var(--gray-500); display: block; margin-top: 0.3rem;">
                        Minimum 6 characters. Give this credential to the professor to log in.
                    </small>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1rem;">
                    <?php if ($editingProf): ?>
                        <i class="fa-solid fa-floppy-disk"></i> Update Professor Details
                    <?php else: ?>
                        <i class="fa-solid fa-user-plus"></i> Create & Provision Professor Account
                    <?php endif; ?>
                </button>
            </form>
        </div>

        <!-- List of All Registered Professors -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fa-solid fa-chalkboard-user" style="color: var(--secondary);"></i> Registered Professors List
                </div>
                <span class="badge badge-primary"><?= count($professors) ?> Professors</span>
            </div>

            <?php if (empty($professors)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                    No professors registered yet. Fill out the form on the left to create one.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Faculty Member</th>
                                <th>Subjects</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professors as $p): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($p['name']) ?></strong><br>
                                        <small style="color: var(--gray-500); font-family: monospace; font-weight: 600;">
                                            <?= htmlspecialchars($p['identifier']) ?>
                                        </small><br>
                                        <small style="color: var(--primary);"><?= htmlspecialchars($p['email']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?= (int)$p['total_subjects'] ?> Subjects</span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.35rem;">
                                            <a href="professors.php?edit=<?= $p['id'] ?>" class="btn btn-secondary btn-sm" title="Edit details or reset password">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                            <a href="professors.php?delete=<?= $p['id'] ?>" class="btn btn-danger btn-sm" title="Delete account"
                                               onclick="return confirm('Are you sure you want to delete this professor account? All their subjects will also be deleted.')">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
