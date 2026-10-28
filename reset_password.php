<?php
// Password recovery now requires an emailed OTP in the requesting browser.
header('Cache-Control: no-store');
header('Location: forgot_password.php');
exit;
