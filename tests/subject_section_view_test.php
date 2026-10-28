<?php
// Exercise the actual page queries without changing real class records.
require __DIR__.'/../config/subject_sections.php';
function require_auth($roles) {}
function current_user() { return ['id'=>10]; }
function get_db_connection() { return $GLOBALS['pdo']; }
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,identifier TEXT,role TEXT,status TEXT,course_section TEXT,grade_level TEXT);
CREATE TABLE subjects(id INTEGER PRIMARY KEY,subject_code TEXT,subject_name TEXT,professor_id INTEGER,grade_level_id INTEGER);
CREATE TABLE schedules(subject_id INTEGER,day_of_week TEXT,start_time TEXT,end_time TEXT,late_threshold_mins INTEGER);
CREATE TABLE grade_levels(id INTEGER PRIMARY KEY,grade_name TEXT);
CREATE TABLE school_sections(grade_level_id INTEGER,section_name TEXT);
CREATE TABLE student_subjects(student_id INTEGER,subject_id INTEGER);
INSERT INTO grade_levels VALUES (1,'Grade 7'),(2,'Grade 8');
INSERT INTO school_sections VALUES (1,'Grade 7 - Emerald'),(1,'Grade 7 - Jade'),(1,'Grade 7 - Ruby'),(2,'Grade 8 - Sapphire');
INSERT INTO subjects VALUES (1,'ENG7','English',10,1),(2,'ENG8','English',11,2);
INSERT INTO schedules VALUES (1,'Monday','08:00','09:00',15),(1,'Tuesday','09:00','10:00',15);
INSERT INTO users VALUES (20,'Student A','a@example.com','S20','student','Active','Grade 7 - Emerald','Grade 7'),
(21,'Student B','b@example.com','S21','student','Active','Grade 7 - Jade','Grade 7'),
(22,'New A','c@example.com','S22','student','Active','Grade 7 - Emerald','Grade 7'),
(23,'New B','d@example.com','S23','student','Active','Grade 7 - Jade','Grade 7'),
(24,'Wrong Grade','e@example.com','S24','student','Active','Grade 7 - Emerald','Grade 8');
INSERT INTO student_subjects VALUES (20,1),(21,1);");
$scenario=$argv[1]??'emerald';
$_SESSION=[];$_SERVER['REQUEST_METHOD']='GET';$_GET=['subject_id'=>1];
if($scenario==='emerald') $_GET['section']='Grade 7 - Emerald';
if($scenario==='jade') $_GET['section']='Grade 7 - Jade';
if($scenario==='empty') $_GET['section']='Grade 7 - Ruby';
if($scenario==='invalid') $_GET['section']='Invalid';
if($scenario==='foreign') $_GET['subject_id']=2;
$source=file_get_contents(__DIR__.'/../professor/subjects.php');
$source=substr($source,5,strpos($source,'$profDisplayName =')-5);
$source=preg_replace('/require_once[^;]+;/','',$source);
eval($source);
check(count($subjectClasses)===3,'Duplicate/missing class rows across schedules');
check(array_column($subjectClasses,'enrolled_students')===[1,1,0],'Counts not separated by section');
foreach($subjectClasses as $class) check(count($class['schedules'])===2,'Lost scheduled day');
$expected=match($scenario) {'jade'=>['S21'],'empty','invalid','foreign'=>[],default=>['S20']};
check(array_column($enrolledStudents,'identifier')===$expected,'Mixed section roster');
$available=match($scenario) {'jade'=>[23],'empty','invalid','foreign'=>[],default=>[22]};
check(array_map('intval',array_column($availableStudents,'id'))===$available,'Wrong section or grade in enrollment options');
if(in_array($scenario,['invalid','foreign'])) check(!$selectedSubject,'Invalid selection allowed');
echo "PASS: $scenario section view\n";
