<?php
// Executes the real handlers against isolated fixtures; no actual attendance is changed.
require __DIR__ . '/../config/student_sections.php';
require __DIR__ . '/../config/school_time.php';
require __DIR__ . '/../config/professor_names.php';
class SectionAttendancePDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
        $query = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(student_id, subject_id, date) DO UPDATE SET', $query);
        $query = preg_replace('/VALUES\((\w+)\)/', 'excluded.$1', $query);
        return parent::prepare($query, $options);
    }
}
function is_logged_in() { return true; }
function require_auth($roles) {}
function current_user() { return ['id'=>10, 'role'=>'professor', 'name'=>'Test Teacher']; }
function get_db_connection() { return $GLOBALS['pdo']; }
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$scenario=$argv[1] ?? 'roster_a';
$pdo=new SectionAttendancePDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, identifier TEXT, name TEXT, email TEXT, role TEXT, course_section TEXT);
CREATE TABLE subjects (id INTEGER PRIMARY KEY, subject_code TEXT, professor_id INTEGER, grade_level_id INTEGER);
CREATE TABLE schedules (subject_id INTEGER, day_of_week TEXT, start_time TEXT, end_time TEXT, late_threshold_mins INTEGER);
CREATE TABLE student_subjects (student_id INTEGER, subject_id INTEGER, UNIQUE(student_id,subject_id));
CREATE TABLE attendance (id INTEGER PRIMARY KEY, student_id INTEGER, subject_id INTEGER, date TEXT, time_in TEXT, status TEXT, scanned_by INTEGER, UNIQUE(student_id,subject_id,date));
CREATE TABLE grade_levels (id INTEGER PRIMARY KEY, grade_name TEXT);
CREATE TABLE school_sections (grade_level_id INTEGER, section_name TEXT);
INSERT INTO users VALUES (10,'T1','Test Teacher','teacher@example.com','professor',NULL),
(20,'S20','Student A','a@example.com','student','Grade 7 - Emerald'),
(21,'S21','Student B','b@example.com','student','Grade 7 - Jade'),
(22,'S22','New Student A','newa@example.com','student','Grade 7 - Emerald'),
(23,'S23','New Student B','newb@example.com','student','Grade 7 - Jade');
INSERT INTO subjects VALUES (1,'ENG7',10,1),(2,'OTHER',11,1);
INSERT INTO student_subjects VALUES (20,1),(21,1);
INSERT INTO grade_levels VALUES (1,'Grade 7');
INSERT INTO school_sections VALUES (1,'Grade 7 - Emerald'),(1,'Grade 7 - Jade'),(1,'Grade 7 - Ruby');
INSERT INTO attendance VALUES (1,21,1,'2026-10-28','08:00:00','Late',10);");
$_SESSION=['user_id'=>10,'user_role'=>'professor','manual_attendance_csrf'=>'test-csrf'];
$_GET=['subject_id'=>1,'date'=>'2026-10-28','section'=>'Grade 7 - Emerald'];
$_POST=['csrf'=>'test-csrf','subject_id'=>1,'date'=>'2026-10-28','section'=>'Grade 7 - Emerald','student_id'=>20,'action'=>'set_status','status'=>'Present'];
if (str_starts_with($scenario,'roster') || str_starts_with($scenario,'enroll')) {
    $_SERVER['REQUEST_METHOD']='GET';
    if ($scenario==='roster_b') $_GET['section']='Grade 7 - Jade';
    if ($scenario==='roster_all' || $scenario==='enroll_all') $_GET['section']='ALL';
    if ($scenario==='roster_default') unset($_GET['section']);
    if ($scenario==='roster_invalid') $_GET['section']='Invalid';
    if ($scenario==='roster_none') $pdo->exec('DELETE FROM student_subjects; DELETE FROM school_sections');
    if ($scenario==='roster_empty') $_GET['section']='Grade 7 - Ruby';
    if ($scenario==='roster_foreign') $_GET['subject_id']=2;
    if (str_starts_with($scenario,'enroll')) {
        $_SERVER['REQUEST_METHOD']='POST';
        $_POST['action']='enroll_and_mark';
        $_POST['student_identifier']=$scenario==='enroll_cross'?'S23':'S22';
    }
    $source=file_get_contents(__DIR__.'/../professor/manual_attendance.php');
    $source=substr($source,5,strpos($source,'?>')-5);
    $source=preg_replace('/require_once[^;]+;/','',$source);
    eval($source);
    $expected=match($scenario) {'roster_b'=>[21], 'roster_empty','roster_foreign','roster_invalid','roster_none'=>[], 'enroll_same'=>[22,20], default=>[20]};
    check(array_map('intval',array_column($students,'student_id'))===$expected,'Wrong roster: '.$scenario);
    foreach($otherStudents as $other) check($selected_section!=='' && $other['course_section']===$selected_section,'Other section in enrollment options');
    check($pdo->query('SELECT status FROM attendance WHERE student_id=21')->fetchColumn()==='Late','Other section attendance changed');
    if ($scenario==='enroll_all') check(!empty($error) && $pdo->query('SELECT COUNT(*) FROM student_subjects WHERE student_id=22')->fetchColumn()==0,'ALL accepted for enrollment');
    if ($scenario==='enroll_cross') check(!empty($error) && $pdo->query('SELECT COUNT(*) FROM student_subjects WHERE student_id=23')->fetchColumn()==0,'Cross section enrollment allowed');
    echo "PASS: $scenario\n";
    exit;
}
$_SERVER['REQUEST_METHOD']='POST';
if (str_starts_with($scenario,'bulk')) $_POST['action']='mark_all_present';
if (in_array($scenario,['bulk_all','set_all','clear_all'])) $_POST['section']='ALL';
if ($scenario==='bulk_missing') unset($_POST['section']);
if ($scenario==='bulk_blank') $_POST['section']='';
if (in_array($scenario,['set_existing','bulk_existing'])) { $_POST['student_id']=21; $_POST['section']='Grade 7 - Jade'; }
if ($scenario==='set_spoof') $_POST['time_in']='00:00:01';
if ($scenario==='bulk_empty') $_POST['section']='Grade 7 - Ruby';
if ($scenario==='set_cross' || $scenario==='clear_cross') $_POST['student_id']=21;
if (str_starts_with($scenario,'clear')) $_POST['action']='clear_record';
if ($scenario==='clear_same') {
    $_POST['student_id']=21;$_POST['section']='Grade 7 - Jade';
}
if ($scenario==='foreign') $_POST['subject_id']=2;
if ($scenario==='csrf') $_POST['csrf']='bad';
ob_start();
register_shutdown_function(function() use($pdo,$scenario) {
    $result=json_decode(ob_get_clean(),true);
    $rejected=in_array($scenario,['set_cross','clear_cross','foreign','csrf','bulk_all','bulk_missing','bulk_blank','set_all','clear_all'],true);
    check(($result['status']??'')===($rejected?'error':'success'),'Unexpected result '.$scenario);
    $a=$pdo->query('SELECT status FROM attendance WHERE student_id=20')->fetchColumn();
    $b=$pdo->query('SELECT status FROM attendance WHERE student_id=21')->fetchColumn();
    check($a===(in_array($scenario,['bulk_a','set_same','set_spoof'],true)?'Present':false),'Wrong section A result');
    check($b===($scenario==='clear_same'?false:(in_array($scenario,['set_existing','bulk_existing'])?'Present':'Late')),'Wrong section B result');
    if(str_starts_with($scenario,'bulk') && !$rejected) check($result['count']===(in_array($scenario,['bulk_a','bulk_existing'])?1:0),'Wrong bulk count');
    if (in_array($scenario,['set_existing','bulk_existing'])) {
        check($pdo->query('SELECT time_in FROM attendance WHERE student_id=21')->fetchColumn()==='08:00:00','Status edit changed original arrival time');
        if($scenario==='set_existing') check($result['data']['time_in']==='08:00 AM','Response did not return original time');
    }
    if($scenario==='set_spoof') {
        $stored=$pdo->query('SELECT time_in FROM attendance WHERE student_id=20')->fetchColumn();
        $epoch=strtotime(date('Y-m-d').' '.$stored);
        check(min(abs($epoch-time()),abs($epoch-time()-86400),abs($epoch-time()+86400))<10,'Browser-supplied time was trusted');
    }
    echo "PASS: $scenario\n";
});
$source=file_get_contents(__DIR__.'/../api/manual_attendance.php');
$source=preg_replace('/require_once[^;]+;/','',substr($source,5));
eval($source);
