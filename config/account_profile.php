<?php
require_once __DIR__ . '/professor_names.php';

/** Only the authenticated account ID and role may be passed by the controller. */
function save_account_profile(PDO $pdo, int $userId, string $role, array $input, string $csrf): array
{
    if (!in_array($role, ['student', 'professor'], true)) {
        throw new InvalidArgumentException('This profile is only available to students and teachers.');
    }
    if ($csrf === '' || !is_string($input['csrf'] ?? null) || !hash_equals($csrf, $input['csrf'])) {
        throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
    }
    $value = static function (string $key) use ($input): string {
        if (!is_string($input[$key] ?? '')) throw new InvalidArgumentException('Invalid form value.');
        return $input[$key] ?? '';
    };
    $action = $value('action');
    if (!in_array($action, ['profile', 'password'], true)) throw new InvalidArgumentException('Invalid profile action.');

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT * FROM users WHERE id = ? AND role = ? FOR UPDATE');
        $find->execute([$userId, $role]);
        $account = $find->fetch(PDO::FETCH_ASSOC);
        if (!$account || $account['status'] !== 'Active') throw new InvalidArgumentException('Your account is not available.');
        $invalidateRecovery = false;

        if ($action === 'password') {
            if (!password_verify($value('current_password'), $account['password'])) {
                throw new InvalidArgumentException('Your current password is incorrect.');
            }
            $password = $value('new_password');
            if (strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false) {
                throw new InvalidArgumentException('Use at least 8 characters and no more than 72 bytes for your new password.');
            }
            if ($password !== $value('confirm_password')) throw new InvalidArgumentException('The new passwords do not match.');
            if (password_verify($password, $account['password'])) throw new InvalidArgumentException('Choose a different password from your current password.');
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_BCRYPT), $userId]);
            $invalidateRecovery = true;
        } else {
            $email = trim($value('email'));
            if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid email address, up to 100 characters.');
            }
            $emailChanged = strcasecmp($email, $account['email']) !== 0;
            if ($emailChanged && !password_verify($value('current_password'), $account['password'])) {
                throw new InvalidArgumentException('Enter your current password to change your email address.');
            }
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?');
            $duplicate->execute([$email, $userId]);
            if ($duplicate->fetchColumn()) throw new InvalidArgumentException('That email address is already registered.');

            $contact = trim($value('contact'));
            if (mb_strlen($contact) > 50) throw new InvalidArgumentException('Contact number must be at most 50 characters.');
            if ($role === 'student') {
                $first = trim($value('first_name'));
                $middle = trim($value('middle_initial'));
                $last = trim($value('last_name'));
                if ($first === '' || $last === '' || mb_strlen($first) > 50 || mb_strlen($last) > 50 || mb_strlen($middle) > 10) {
                    throw new InvalidArgumentException('Enter your first and last name (up to 50 characters each) and an optional middle initial (up to 10 characters).');
                }
                $name = trim($first . ' ' . ($middle !== '' ? rtrim($middle, '.') . '. ' : '') . $last);
            } else {
                $name = professor_display_name(trim($value('name')));
                if ($name === 'Unassigned') throw new InvalidArgumentException('Enter your full name.');
            }
            if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                throw new InvalidArgumentException('Enter a full name of up to 100 characters.');
            }
            if ($role === 'student') {
                $pdo->prepare('UPDATE users SET name = ?, first_name = ?, middle_initial = ?, last_name = ?, email = ?, guardian_contact = ? WHERE id = ?')
                    ->execute([$name, $first, $middle, $last, $email, $contact, $userId]);
            } else {
                $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $userId]);
                // The admin lists and student subjects read the linked teacher record.
                $pdo->prepare('UPDATE teachers SET full_name = ?, email = ?, contact_number = ? WHERE user_id = ?')
                    ->execute([$name, $email, $contact, $userId]);
            }
            $invalidateRecovery = $emailChanged;
        }
        if ($invalidateRecovery) {
            $pdo->prepare('UPDATE password_reset_otps SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                ->execute([time(), $userId]);
            $pdo->prepare('DELETE FROM password_resets WHERE LOWER(email) = LOWER(?)')->execute([$account['email']]);
        }
        $find->execute([$userId, $role]);
        $updated = $find->fetch(PDO::FETCH_ASSOC);
        unset($updated['password']);
        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
