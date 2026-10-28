<?php
// Isolated tests for Philippine dates, timezone-independent scans and lateness.
date_default_timezone_set('Europe/Berlin');
require __DIR__.'/../config/school_time.php';
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
check(date_default_timezone_get()==='Asia/Manila','Wrong application timezone');
check(school_now()->getTimezone()->getName()==='Asia/Manila','Server timestamp has wrong timezone');
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE schedules(id INTEGER PRIMARY KEY,subject_id INTEGER,day_of_week TEXT,start_time TEXT,late_threshold_mins INTEGER);
INSERT INTO schedules VALUES (1,1,'Wednesday','08:00:00',15),(2,1,'Wednesday','13:00:00',15),(3,2,'Wednesday','08:00:00',0),(4,3,'Wednesday','00:00:00',0);");
$cases=[
[1,'2026-10-28T07:59:59+08:00','Present'],
[1,'2026-10-28T08:15:00+08:00','Present'],
[1,'2026-10-28T08:15:00.999+08:00','Present'],
[1,'2026-10-28T08:15:01+08:00','Late'],
[1,'2026-10-28T00:15:01Z','Late'],
[2,'2026-10-28T08:00:00+08:00','Present'],
[2,'2026-10-28T08:00:01+08:00','Late'],
[3,'2026-10-27T16:00:01Z','Late'],
[99,'2026-10-28T08:00:00+08:00','Present'],
[1,'2026-10-28T13:01:00+08:00','Late']];
foreach($cases as [$subject,$instant,$expected]) check(school_scan_status($p,$subject,new DateTimeImmutable($instant))===$expected,'Incorrect Present/Late: '.$instant);
$midnight=(new DateTimeImmutable('2026-10-27T16:00:00Z'))->setTimezone(new DateTimeZone(SCHOOL_TIMEZONE));
check($midnight->format('Y-m-d H:i:s l')==='2026-10-28 00:00:00 Wednesday','Wrong Philippine midnight/day');
echo 'PASS: Philippine timezone, midnight rollover, exact grace boundary, zero grace and consistent daily schedule.',PHP_EOL;
