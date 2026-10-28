<?php
// Account recovery state lives in the database; only the random request ID lives in the session.
const PASSWORD_OTP_LIFETIME = 600;
const PASSWORD_OTP_MAX_ATTEMPTS = 5;

function request_password_otp(PDO $pdo, string $email, callable $deliver, ?int $now = null): array {
    $now ??= time();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        throw new InvalidArgumentException('Enter your registered email address.');
    }
    $requestId = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id, name, email FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1 FOR UPDATE');
        $find->execute([$email]);
        $user = $find->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $recent = $pdo->prepare('SELECT created_at FROM password_reset_otps WHERE user_id = ? AND created_at > ? ORDER BY created_at DESC');
            $recent->execute([$user['id'], $now - 3600]);
            $requests = $recent->fetchAll(PDO::FETCH_COLUMN);
            if (count($requests) >= 5 || ($requests && (int)$requests[0] > $now - 60)) {
                throw new InvalidArgumentException('Please wait before requesting another code. You can request up to 5 codes per hour.');
            }
            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO password_reset_otps (request_id, user_id, otp_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$requestId, $user['id'], password_hash($otp, PASSWORD_BCRYPT), $now, $now + PASSWORD_OTP_LIFETIME]);
            if (!$deliver($user['email'], $user['name'], $otp)) {
                throw new RuntimeException('OTP delivery failed.');
            }
            // A successfully sent replacement invalidates all earlier codes and reset grants.
            $pdo->prepare('UPDATE password_reset_otps SET used_at = ? WHERE user_id = ? AND request_id <> ? AND used_at IS NULL')
                ->execute([$now, $user['id'], $requestId]);
        }
        $pdo->commit();
        // Unknown emails get the same next step but cannot verify a code.
        return ['request_id' => $requestId, 'email' => $user['email'] ?? $email, 'verified' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function lock_password_otp(PDO $pdo, string $requestId): array {
    $find = $pdo->prepare('SELECT user_id FROM password_reset_otps WHERE request_id = ?');
    $find->execute([$requestId]);
    $userId = $find->fetchColumn();
    if (!$userId) throw new InvalidArgumentException('Invalid or expired code. Request a new OTP.');
    // Always lock user before request, matching issuance and password changes.
    $user = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $user->execute([$userId]);
    if (!$user->fetchColumn()) throw new InvalidArgumentException('Account is no longer available.');
    $request = $pdo->prepare('SELECT * FROM password_reset_otps WHERE request_id = ? FOR UPDATE');
    $request->execute([$requestId]);
    $row = $request->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Invalid or expired code. Request a new OTP.');
    return $row;
}

function verify_password_otp(PDO $pdo, string $requestId, string $otp, ?int $now = null): void {
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $row = lock_password_otp($pdo, $requestId);
        if ($row['used_at'] !== null || $row['verified_at'] !== null || (int)$row['expires_at'] <= $now || (int)$row['attempts'] >= PASSWORD_OTP_MAX_ATTEMPTS) {
            throw new InvalidArgumentException('This code has expired or is no longer available. Request a new OTP.');
        }
        if (!preg_match('/^[0-9]{6}$/D', $otp) || !password_verify($otp, $row['otp_hash'])) {
            $attempts = (int)$row['attempts'] + 1;
            $pdo->prepare('UPDATE password_reset_otps SET attempts = ?, used_at = ? WHERE request_id = ?')
                ->execute([$attempts, $attempts >= PASSWORD_OTP_MAX_ATTEMPTS ? $now : null, $requestId]);
            $pdo->commit();
            $remaining = PASSWORD_OTP_MAX_ATTEMPTS - $attempts;
            throw new InvalidArgumentException($remaining ? "Incorrect OTP. {$remaining} attempts remaining." : 'Too many incorrect attempts. Request a new OTP.');
        }
        $pdo->prepare('UPDATE password_reset_otps SET verified_at = ? WHERE request_id = ?')->execute([$now, $requestId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function reset_password_with_otp(PDO $pdo, string $requestId, string $password, string $confirmation, ?int $now = null): void {
    $now ??= time();
    if (strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false) {
        throw new InvalidArgumentException('Use at least 8 characters and no more than 72 bytes for your password.');
    }
    if ($password !== $confirmation) throw new InvalidArgumentException('The passwords do not match.');
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    try {
        $row = lock_password_otp($pdo, $requestId);
        if ($row['used_at'] !== null || $row['verified_at'] === null || (int)$row['expires_at'] <= $now) {
            throw new InvalidArgumentException('Your reset session has expired. Request and verify a new OTP.');
        }
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $row['user_id']]);
        $pdo->prepare('UPDATE password_reset_otps SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([$now, $row['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
