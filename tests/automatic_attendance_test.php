<?php
// Native MySQL/MariaDB test with connection-local temporary tables only.
require __DIR__.'/../config/automatic_attendance.php';
function check($condition,$message) { if(!$condition) throw new RuntimeException($message); }
function fixture(): PDO {
    $p=new PDO('mysql:host=127.0.0.1;dbname=attendance_db;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec("SET time_zone='+08:00'");
    $p->exec("CREATE TEMPORARY TABLE users(id INT PRIMARY KEY,role VARCHAR(20),status VARCHAR(20));");
    $p->exec('CREATE TEMPORARY TABLE subjects(id INT PRIMARY KEY,professor_id INT NULL)');
    $p->exec('CREATE TEMPORARY TABLE student_subjects(student_id INT,subject_id INT,enrolled_at TIMESTAMP,UNIQUE(student_id,subject_id))');
    $p->exec('CREATE TEMPORARY TABLE schedules(id INT PRIMARY KEY,subject_id INT,day_of_week VARCHAR(20),start_time TIME,end_time TIME,created_at TIMESTAMP)');
    $p->exec("CREATE TEMPORARY TABLE attendance(id INT AUTO_INCREMENT PRIMARY KEY,student_id INT,subject_id INT,date DATE,time_in TIME NULL,status VARCHAR(20),scanned_by INT NULL,UNIQUE(student_id,subject_id,date))");
    $p->exec('CREATE TEMPORARY TABLE automatic_attendance_state(id INT PRIMARY KEY,enabled_at DATETIME,last_processed_date DATE)');
    $p->exec("INSERT INTO automatic_attendance_state VALUES (1,'2026-10-28 00:00:00','2026-10-28')");
    $p->exec("INSERT INTO users VALUES (1,'professor','Active'),(10,'student','Active'),(11,'student','Active'),(12,'student','Active'),(13,'student','Active'),(14,'student','Active'),(15,'student','Inactive'),(16,'student','Active'),(17,'student','Active')");
    $p->exec('INSERT INTO subjects VALUES (10,1),(20,1),(30,1),(40,1),(50,1)');
    $p->exec("INSERT INTO schedules VALUES
        (1,10,'Wednesday','08:00:00','09:00:00','2026-10-27 00:00:00'),
        (2,20,'Wednesday','08:00:00','09:00:00','2026-10-27 00:00:00'),
        (3,20,'Wednesday','13:00:00','15:00:00','2026-10-27 00:00:00'),
        (4,30,'Thursday','13:00:00','14:00:00','2026-10-27 00:00:00'),
        (5,50,'Wednesday','08:00:00','09:00:00','2026-10-28 10:00:00')");
    foreach([10,11,12,13,14,15] as $student) $p->exec("INSERT INTO student_subjects VALUES ($student,10,'2026-10-27 00:00:00')");
    $p->exec("INSERT INTO student_subjects VALUES (16,10,'2026-10-28 10:00:00'),(10,20,'2026-10-27 00:00:00'),(10,30,'2026-10-27 00:00:00'),(10,40,'2026-10-27 00:00:00'),(10,50,'2026-10-27 00:00:00')");
    foreach([11=>'Present',12=>'Late',13=>'Excused',14=>'Absent'] as $student=>$status) $p->exec("INSERT INTO attendance(student_id,subject_id,date,time_in,status,scanned_by) VALUES ($student,10,'2026-10-28','08:00:00','$status',1)");
    return $p;
}
$p=fixture();
$before=$p->query('SELECT * FROM attendance ORDER BY id')->fetchAll();
check(mark_finished_classes_absent($p,new DateTimeImmutable('2026-10-28T08:59:59+08:00'))===0,'Marked before end');
check(mark_finished_classes_absent($p,new DateTimeImmutable('2026-10-28T09:00:00+08:00'))===1,'Exact cutoff failed');
$record=$p->query('SELECT * FROM attendance WHERE student_id=10 AND subject_id=10')->fetch();
check($record['status']==='Absent' && $record['time_in']===null && $record['scanned_by']===null,'False arrival time or scanner recorded');
check($p->query('SELECT * FROM attendance WHERE student_id BETWEEN 11 AND 14 ORDER BY id')->fetchAll()===$before,'Existing attendance changed');
check(mark_finished_classes_absent($p,new DateTimeImmutable('2026-10-28T09:00:00+08:00'))===0,'Duplicate automatic records');
check($p->query('SELECT COUNT(*) FROM attendance WHERE student_id IN (15,16,17)')->fetchColumn()==0,'Inactive, late-enrolled, or unenrolled student marked');
check($p->query('SELECT COUNT(*) FROM attendance WHERE subject_id IN (20,30,40,50)')->fetchColumn()==0,'Future/no-schedule/newly-created classes marked');
check(!school_class_has_ended($p,20,new DateTimeImmutable('2026-10-28T09:00:00+08:00')),'Multiple-session class closed early');
check(school_class_has_ended($p,20,new DateTimeImmutable('2026-10-28T15:00:00+08:00')),'Class did not close at final end');
check(mark_finished_classes_absent($p,new DateTimeImmutable('2026-10-28T07:00:00Z'))===1,'Final session/timezone cutoff failed');
check(school_format_time(null)==="\u{2014}",'Absent displayed a fake time');
$p=fixture();
check(mark_finished_classes_absent($p,new DateTimeImmutable('2026-10-29T16:00:00+08:00'))===3,'Offline catch-up missed classes');
check($p->query("SELECT COUNT(*) FROM attendance WHERE date<'2026-10-28'")->fetchColumn()==0,'Pre-activation history backfilled');
check($p->query('SELECT last_processed_date FROM automatic_attendance_state')->fetchColumn()==='2026-10-29','Checkpoint not advanced');
check(mark_finished_classes_absent($p,new DateTimeImmutable('2026-10-29T16:01:00+08:00'))===0,'Catch-up not idempotent');
echo "PASS: cutoff, all enrolled students, existing status preservation, null arrival time, last session, timezone, offline catch-up and repeat safety. Real records unchanged.\n";
