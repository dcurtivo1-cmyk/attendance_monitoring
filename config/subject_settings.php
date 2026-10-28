<?php
function save_subject_settings(PDO $pdo, string $role, array $input, string $csrf): void
{
    if ($role !== 'admin') throw new InvalidArgumentException('Only administrators can edit subject assignments and schedules.');
    if ($csrf === '' || !is_string($input['csrf'] ?? null) || !hash_equals($csrf, $input['csrf'])) {
        throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
    }
    $id = filter_var($input['subject_id'] ?? null, FILTER_VALIDATE_INT);
    $professorId = filter_var($input['professor_id'] ?? null, FILTER_VALIDATE_INT);
    $code = is_string($input['subject_code'] ?? null) ? trim($input['subject_code']) : '';
    $name = is_string($input['subject_name'] ?? null) ? trim($input['subject_name']) : '';
    if (!$id || !$professorId || $code === '' || $name === '' || mb_strlen($code) > 20 || mb_strlen($name) > 100) {
        throw new InvalidArgumentException('Enter a subject code, name, and assigned teacher.');
    }
    if (!is_array($input['schedules'] ?? null) || !$input['schedules']) throw new InvalidArgumentException('Enter the class schedule.');
    $schedules = [];
    foreach ($input['schedules'] as $scheduleId => $row) {
        if (!is_array($row) || !ctype_digit((string)$scheduleId)) throw new InvalidArgumentException('Invalid schedule. Refresh and try again.');
        $day = $row['day_of_week'] ?? '';
        if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], true)) {
            throw new InvalidArgumentException('Choose a valid day for every schedule.');
        }
        $times = [];
        foreach (['start_time', 'end_time'] as $field) {
            $value = $row[$field] ?? '';
            if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value)) {
                throw new InvalidArgumentException('Enter valid start and end times for every schedule.');
            }
            $times[$field] = strlen($value) === 5 ? $value . ':00' : $value;
        }
        if ($times['end_time'] <= $times['start_time']) throw new InvalidArgumentException('The end time must be after the start time.');
        $late = filter_var($row['late_threshold_mins'] ?? null, FILTER_VALIDATE_INT);
        if ($late === false || $late < 0 || $late > 60) throw new InvalidArgumentException('Late grace period must be between 0 and 60 minutes.');
        $schedules[(int)$scheduleId] = [$day, $times['start_time'], $times['end_time'], $late];
    }
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id, professor_id FROM subjects WHERE id = ? FOR UPDATE');
        $find->execute([$id]);
        $subject = $find->fetch(PDO::FETCH_ASSOC);
        if (!$subject) throw new InvalidArgumentException('Subject not found.');
        $teacher = $pdo->prepare("SELECT id, status FROM users WHERE id = ? AND role = 'professor' FOR UPDATE");
        $teacher->execute([$professorId]);
        $assignedTeacher = $teacher->fetch(PDO::FETCH_ASSOC);
        if (!$assignedTeacher || ($assignedTeacher['status'] !== 'Active' && (int)$subject['professor_id'] !== $professorId)) {
            throw new InvalidArgumentException('Choose an active teacher.');
        }
        $existing = $pdo->prepare('SELECT id FROM schedules WHERE subject_id = ? ORDER BY id FOR UPDATE');
        $existing->execute([$id]);
        $expectedIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
        $postedIds = array_keys($schedules);
        sort($postedIds, SORT_NUMERIC);
        if ($postedIds !== ($expectedIds ?: [0])) {
            throw new InvalidArgumentException('The schedule list changed. Refresh this page before saving.');
        }
        $pdo->prepare('UPDATE subjects SET subject_code = ?, subject_name = ?, professor_id = ? WHERE id = ?')->execute([$code, $name, $professorId, $id]);
        $pdo->prepare('UPDATE teachers SET subject_name = ? WHERE subject_id = ?')->execute([$name, $id]);
        $pdo->prepare('UPDATE teacher_assignments SET subject_name = ? WHERE subject_id = ?')->execute([$name, $id]);
        foreach ($schedules as $scheduleId => $values) {
            if ($scheduleId === 0) {
                $pdo->prepare('INSERT INTO schedules (day_of_week, start_time, end_time, late_threshold_mins, subject_id) VALUES (?, ?, ?, ?, ?)')->execute([...$values, $id]);
            } else {
                $pdo->prepare('UPDATE schedules SET day_of_week = ?, start_time = ?, end_time = ?, late_threshold_mins = ? WHERE id = ? AND subject_id = ?')->execute([...$values, $scheduleId, $id]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
