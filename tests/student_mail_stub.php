<?php
namespace PHPMailer\PHPMailer;
#[\AllowDynamicProperties]
class PHPMailer {
    const ENCRYPTION_SMTPS = 'ssl';
    const ENCRYPTION_STARTTLS = 'tls';
    public static $fail = false;
    public static $last;
    public static $attempts = 0;
    public static $onSend;
    public function __construct($exceptions = true) { self::$last = $this; }
    public function isSMTP() {}
    public function setFrom($email, $name) {}
    public function addAddress($email, $name) { $this->recipient = $email; }
    public function isHTML($html) {}
    public function send() {
        self::$attempts++;
        if (self::$onSend) (self::$onSend)();
        if (self::$fail) throw new \RuntimeException('Simulated failure');
        return true;
    }
}
