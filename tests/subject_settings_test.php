<?php
// Isolated subject editor fixtures; actual school records are untouched.
require __DIR__.'/../config/subject_settings.php';
class SettingsPDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
function fixture(): PDO {
    $p=new SettingsPDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);
CREATE TABLE subjects(id INTEGER PRIMARY KEY,subject_code TEXT,subject_name TEXT,professor_id INTEGER);
CREATE TABLE schedules(id INTEGER PRIMARY KEY,subject_id INTEGER,day_of_week TEXT,start_time TEXT,end_time TEXT,late_threshold_mins INTEGER);
CREATE TABLE teachers(id INTEGER PRIMARY KEY,subject_id INTEGER,subject_name TEXT);
CREATE TABLE teacher_assignments(id INTEGER PRIMARY KEY,subject_id INTEGER,subject_name TEXT);
CREATE TABLE student_subjects(student_id INTEGER,subject_id INTEGER);
CREATE TABLE attendance(student_id INTEGER,subject_id INTEGER,status TEXT);
INSERT INTO users VALUES (1,'professor','Active'),(2,'professor','Active'),(3,'professor','Inactive'),(4,'student','Active');
INSERT INTO subjects VALUES (10,'ENG7','English',1),(20,'MATH7','Math',2),(30,'SCI7','Science',1);
INSERT INTO schedules VALUES (100,10,'Monday','08:00:00','09:00:00',15),(101,10,'Wednesday','10:00:00','11:00:00',10),(200,20,'Friday','08:00:00','09:00:00',15);
INSERT INTO teachers VALUES (1,10,'English');
INSERT INTO teacher_assignments VALUES (1,10,'English');
INSERT INTO student_subjects VALUES (50,10);
INSERT INTO attendance VALUES (50,10,'Present');");
    return $p;
}
function snapshot(PDO $p): array {
    $rows=[];
    foreach(['subjects','schedules','teachers','teacher_assignments','student_subjects','attendance'] as $table) $rows[$table]=$p->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}
$form=['csrf'=>'test','subject_id'=>'10','subject_code'=>'ENG7B','subject_name'=>'English Revised','professor_id'=>'2','schedules'=>[
100=>['day_of_week'=>'Tuesday','start_time'=>'13:00','end_time'=>'14:00','late_threshold_mins'=>'20'],
101=>['day_of_week'=>'Wednesday','start_time'=>'10:00:00','end_time'=>'11:00:00','late_threshold_mins'=>'10']]];
$checks=0;
$invalid=[];
$invalid[]=['professor',$form];$invalid[]=['student',$form];
foreach(['csrf'=>'wrong','professor_id'=>'3','subject_id'=>'999','subject_name'=>''] as $key=>$value) $invalid[]=['admin',array_replace($form,[$key=>$value])];
$invalid[]=['admin',array_replace($form,['professor_id'=>'4'])];
foreach(['day_of_week'=>'Invalid','start_time'=>'25:00','end_time'=>'12:00','late_threshold_mins'=>'61'] as $field=>$value) {
    $bad=$form;$bad['schedules'][100][$field]=$value;$invalid[]=['admin',$bad];
}
$bad=$form;unset($bad['schedules'][101]);$invalid[]=['admin',$bad];
$bad=$form;$bad['schedules'][200]=$bad['schedules'][100];$invalid[]=['admin',$bad];
$bad=$form;$bad['schedules']=[];$invalid[]=['admin',$bad];
foreach($invalid as [$role,$input]) {
    $p=fixture();$before=snapshot($p);
    try { save_subject_settings($p,$role,$input,'test');throw new RuntimeException('Invalid edit accepted'); }
    catch(InvalidArgumentException $e) { check(snapshot($p)===$before,'Rejected edit changed data');$checks++; }
}
$p=fixture();$before=snapshot($p);save_subject_settings($p,'admin',$form,'test');$after=snapshot($p);
check($after['subjects'][0]['professor_id']==2 && $after['subjects'][0]['subject_name']==='English Revised','Teacher/subject not saved');
check($after['schedules'][0]['day_of_week']==='Tuesday' && $after['schedules'][0]['start_time']==='13:00:00' && $after['schedules'][0]['late_threshold_mins']==20,'Schedule not saved');
check($after['schedules'][1]===$before['schedules'][1] && $after['schedules'][2]===$before['schedules'][2],'Untouched schedule changed');
check($after['teachers'][0]['subject_name']==='English Revised' && $after['teacher_assignments'][0]['subject_name']==='English Revised','Linked subject names not synced');
check($after['student_subjects']===$before['student_subjects'] && $after['attendance']===$before['attendance'],'Enrollment or history changed');$checks++;
$p=fixture();$new=$form;$new['subject_id']='30';$new['schedules']=[0=>$form['schedules'][100]];
save_subject_settings($p,'admin',$new,'test');check($p->query('SELECT COUNT(*) FROM schedules WHERE subject_id=30')->fetchColumn()==1,'Missing schedule not created');$checks++;
$p=fixture();$before=snapshot($p);$p->exec("CREATE TRIGGER fail_schedule BEFORE UPDATE ON schedules BEGIN SELECT RAISE(ABORT,'Simulated failure'); END");
try { save_subject_settings($p,'admin',$form,'test');throw new RuntimeException('Expected schedule failure'); }
catch(PDOException $e) { check(snapshot($p)===$before,'Partial subject update after schedule failure');$checks++; }
echo "PASS: $checks subject editor checks, including teacher reassignment, schedules, authorization and rollback.\n";
