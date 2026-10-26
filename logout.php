<?php
// logout.php
require_once __DIR__ . '/config/database.php';

session_unset();
session_destroy();

header("Location: " . base_url('login.php'));
exit;
