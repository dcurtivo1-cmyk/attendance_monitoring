<?php
require_once __DIR__ . '/school_time.php';

function ensure_automatic_attendance_schema(PDO $pdo): void
{
    foreach (['time_in' => 'TIME', 'scanned_by' => 'INT'] as $column => $type) {
        $info = $pdo->query("SHOW COLUMNS FROM attendance LIKE '$column'")->fetch(PDO::FETCH_ASSOC);
        if ($info && $info['Null'] !== 'YES') $pdo->exec("ALTER TABLE attendance MODIFY `$column` $type NULL DEFAULT NULL");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS automatic_attendance_state (
        id TINYINT PRIMARY KEY, enabled_at DATETIME NOT NULL, last_processed_date DATE NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec('INSERT IGNORE INTO automatic_attendance_state (id, enabled_at, last_processed_date) VALUES (1, NOW(), CURRENT_DATE())');
}

/** Expressions are internal SQL literals or event variables, never request input. */
function automatic_absent_sql(string $dayExpression, string $nowExpression): string
{
    return "INSERT IGNORE INTO attendance (student_id, subject_id, date, time_in, status, scanned_by)
        SELECT ss.student_id, ss.subject_id, $dayExpression, NULL, 'Absent', NULL
        FROM student_subjects ss
        JOIN users u ON u.id = ss.student_id AND u.role = 'student' AND u.status = 'Active'
        JOIN subjects s ON s.id = ss.subject_id AND s.professor_id IS NOT NULL
        JOIN (
            SELECT sc.subject_id, MAX(sc.end_time) AS end_time
            FROM schedules sc
            WHERE sc.day_of_week = DAYNAME($dayExpression)
            GROUP BY sc.subject_id
        ) finished ON finished.subject_id = ss.subject_id
        LEFT JOIN attendance recorded ON recorded.student_id = ss.student_id
            AND recorded.subject_id = ss.subject_id AND recorded.date = $dayExpression
        WHERE recorded.id IS NULL
          AND TIMESTAMP($dayExpression, finished.end_time) <= $nowExpression
          AND ss.enrolled_at <= TIMESTAMP($dayExpression, finished.end_time)
          AND EXISTS (SELECT 1 FROM schedules valid_schedule
              WHERE valid_schedule.subject_id = ss.subject_id
                AND valid_schedule.day_of_week = DAYNAME($dayExpression)
                AND valid_schedule.created_at <= TIMESTAMP($dayExpression, finished.end_time))";
}

function mark_finished_classes_absent(PDO $pdo, ?DateTimeImmutable $now = null): int
{
    $now = ($now ?? school_now())->setTimezone(new DateTimeZone(SCHOOL_TIMEZONE));
    $pdo->beginTransaction();
    try {
        $state = $pdo->query('SELECT enabled_at, last_processed_date FROM automatic_attendance_state WHERE id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!$state) throw new RuntimeException('Automatic attendance is not initialized.');
        $day = new DateTimeImmutable(max(substr($state['enabled_at'], 0, 10), $state['last_processed_date']), new DateTimeZone(SCHOOL_TIMEZONE));
        $count = 0;
        while ($day->format('Y-m-d') <= $now->format('Y-m-d')) {
            $count += $pdo->exec(automatic_absent_sql($pdo->quote($day->format('Y-m-d')), $pdo->quote($now->format('Y-m-d H:i:s'))));
            $day = $day->modify('+1 day');
        }
        if ($now->format('Y-m-d') > $state['last_processed_date']) {
            $pdo->prepare('UPDATE automatic_attendance_state SET last_processed_date = ? WHERE id = 1')->execute([$now->format('Y-m-d')]);
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function automatic_attendance_event_sql(): string
{
    $insert = automatic_absent_sql('processing_day', 'run_at');
    return "CREATE EVENT IF NOT EXISTS attendance_mark_absent
        ON SCHEDULE EVERY 1 MINUTE STARTS CURRENT_TIMESTAMP
        ON COMPLETION PRESERVE ENABLE
        COMMENT 'Mark missing attendance after the final class session, Philippine time'
        DO BEGIN
            DECLARE processing_day DATE;
            DECLARE run_at DATETIME;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
            SET run_at = NOW();
            START TRANSACTION;
            SELECT GREATEST(DATE(enabled_at), last_processed_date) INTO processing_day
                FROM automatic_attendance_state WHERE id = 1 FOR UPDATE;
            WHILE processing_day <= DATE(run_at) DO
                $insert;
                SET processing_day = DATE_ADD(processing_day, INTERVAL 1 DAY);
            END WHILE;
            UPDATE automatic_attendance_state SET last_processed_date = GREATEST(last_processed_date, DATE(run_at)) WHERE id = 1;
            COMMIT;
        END";
}

function resume_automatic_attendance_scheduler(PDO $pdo): void
{
    // The event definition persists across restarts. Re-enable the local scheduler
    // on the next application request if the host resets its global setting.
    try {
        if ($pdo->query('SELECT @@event_scheduler')->fetchColumn() === 'OFF') {
            $installed = $pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'attendance_mark_absent'")->fetchColumn();
            if ($installed) $pdo->exec('SET GLOBAL event_scheduler = ON');
        }
    } catch (PDOException $e) {
        // Request-driven processing remains available on restricted database hosts.
        error_log('Automatic attendance scheduler unavailable: ' . $e->getMessage());
    }
}
