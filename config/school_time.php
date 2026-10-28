<?php
// School schedules and DATE/TIME attendance fields use Philippine local time.
const SCHOOL_TIMEZONE = 'Asia/Manila';
date_default_timezone_set(SCHOOL_TIMEZONE);

function school_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(SCHOOL_TIMEZONE));
}

function school_format_time(?string $time): string
{
    return $time !== null && $time !== '' ? date('h:i A', strtotime($time)) : "\u{2014}";
}

function school_class_has_ended(PDO $pdo, int $subjectId, DateTimeImmutable $now): bool
{
    $now = $now->setTimezone(new DateTimeZone(SCHOOL_TIMEZONE));
    $query = $pdo->prepare('SELECT MAX(end_time) FROM schedules WHERE subject_id = ? AND day_of_week = ?');
    $query->execute([$subjectId, $now->format('l')]);
    $end = $query->fetchColumn();
    if (!$end) return false;
    return $now >= new DateTimeImmutable($now->format('Y-m-d') . ' ' . $end, new DateTimeZone(SCHOOL_TIMEZONE));
}

function school_scan_status(PDO $pdo, int $subjectId, DateTimeImmutable $now): string
{
    $now = $now->setTimezone(new DateTimeZone(SCHOOL_TIMEZONE));
    // Attendance is one record per subject/day, so use that day's first class.
    $find = $pdo->prepare('SELECT start_time, late_threshold_mins FROM schedules WHERE subject_id = ? AND day_of_week = ? ORDER BY start_time, id LIMIT 1');
    $find->execute([$subjectId, $now->format('l')]);
    $schedule = $find->fetch(PDO::FETCH_ASSOC);
    if (!$schedule) return 'Present';
    $start = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $schedule['start_time'], new DateTimeZone(SCHOOL_TIMEZONE));
    $cutoff = $start->modify('+' . max(0, (int)($schedule['late_threshold_mins'] ?? 15)) . ' minutes');
    // Compare whole seconds, matching the precision of the saved attendance time.
    return $now->getTimestamp() > $cutoff->getTimestamp() ? 'Late' : 'Present';
}
