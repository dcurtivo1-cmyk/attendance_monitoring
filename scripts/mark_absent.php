<?php
// Cron/Task Scheduler fallback for hosts that cannot use database events.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
$pdo = get_db_connection();
mark_finished_classes_absent($pdo);
echo "Finished-class attendance checked at " . school_now()->format('Y-m-d H:i:s P') . "\n";
