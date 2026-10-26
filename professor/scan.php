<?php
// professor/scan.php
// QR Scanner has been moved to the Student Portal; Professor Portal now uses QR Code Generator on dashboard.php
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$target = 'dashboard.php' . ($subject_id > 0 ? '?subject_id=' . $subject_id : '');
header("Location: " . $target);
exit;
