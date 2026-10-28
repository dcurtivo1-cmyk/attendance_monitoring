<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
$pdo = get_db_connection();
$pdo->exec(automatic_attendance_event_sql());
$pdo->exec('SET GLOBAL event_scheduler = ON');
echo "Automatic Absent enabled: checks every minute in Asia/Manila, including when no portal is open.\n";
