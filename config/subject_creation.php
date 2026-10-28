<?php
/** Create a subject and its initial schedule together, using administrator input. */
function create_subject_with_schedule(PDO $pdo, string $role, array $input, string $csrf): int
{
    if ($role !== 'admin') throw new InvalidArgumentException('Only administrators can add subjects and schedules.');
    if ($csrf === '' || !is_string($input['csrf'] ?? null) || !hash_equals($csrf, $input['csrf'])) {
        throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
    }
    $value = static function (string $key) use ($input): string {
        if (!is_string($input[$key] ?? null)) throw new InvalidArgumentException('Complete all required subject and schedule fields.');
        return trim($input[$key]);
    };
    $code = $value('subject_code');
    $name = $value('subject_name');
    $professorId = filter_var($value('professor_id'), FILTER_VALIDATE_INT);
    $gradeId = filter_var($value('grade_level_id'), FILTER_VALIDATE_INT);
    $day = $value('day_of_week');
    $start = $value('start_time');
    $end = $value('end_time');
    $late = filter_var($value('late_threshold'), FILTER_VALIDATE_INT);
    if ($code === '' || $name === '' || mb_strlen($code) > 20 || mb_strlen($name) > 100) {
        throw new InvalidArgumentException('Enter a subject code (up to 20 characters) and name (up to 100 characters).');
    }
    if (!$professorId || !$gradeId) throw new InvalidArgumentException('Choose a professor and grade level.');
    if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], true)) {
        throw new InvalidArgumentException('Choose a valid class day.');
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $end) || $end <= $start) {
        throw new InvalidArgumentException('Enter valid class times, with the end time after the start time.');
    }
    if ($late === false || $late < 0 || $late > 60) throw new InvalidArgumentException('Late grace period must be between 0 and 60 minutes.');
    $pdo->beginTransaction();
    try {
        $prof = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'professor' AND status = 'Active' FOR UPDATE");
        $prof->execute([$professorId]);
        if (!$prof->fetchColumn()) throw new InvalidArgumentException('Choose an active professor.');
        $grade = $pdo->prepare('SELECT id FROM grade_levels WHERE id = ?');
        $grade->execute([$gradeId]);
        if (!$grade->fetchColumn()) throw new InvalidArgumentException('Choose an existing grade level.');
        $pdo->prepare('INSERT INTO subjects (subject_code, subject_name, professor_id, grade_level_id) VALUES (?, ?, ?, ?)')
            ->execute([$code, $name, $professorId, $gradeId]);
        $subjectId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO schedules (subject_id, day_of_week, start_time, end_time, late_threshold_mins) VALUES (?, ?, ?, ?, ?)')
            ->execute([$subjectId, $day, $start, $end, $late]);
        $pdo->commit();
        return $subjectId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
