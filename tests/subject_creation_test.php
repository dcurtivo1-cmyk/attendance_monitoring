<?php
// Isolated fixtures: no real subjects or schedules are created.
require __DIR__ . '/../config/subject_creation.php';
class SubjectCreationPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
$pdo=new SubjectCreationPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, role TEXT, status TEXT);
CREATE TABLE grade_levels(id INTEGER PRIMARY KEY);
CREATE TABLE subjects(id INTEGER PRIMARY KEY,subject_code TEXT,subject_name TEXT,professor_id INTEGER,grade_level_id INTEGER);
CREATE TABLE schedules(id INTEGER PRIMARY KEY,subject_id INTEGER,day_of_week TEXT,start_time TEXT,end_time TEXT,late_threshold_mins INTEGER);
INSERT INTO users VALUES (1,'professor','Active'),(2,'student','Active'),(3,'professor','Inactive');
INSERT INTO grade_levels VALUES (1);");
$form=['csrf'=>'test-token','subject_code'=>'G7-ENG','subject_name'=>'English','professor_id'=>'1','grade_level_id'=>'1','day_of_week'=>'Sunday','start_time'=>'08:00','end_time'=>'09:00','late_threshold'=>'15'];
$checks=0;
foreach ([['professor',[]],['student',[]],['admin',['csrf'=>'wrong']],['admin',['professor_id'=>'2']],['admin',['professor_id'=>'3']],['admin',['grade_level_id'=>'99']],['admin',['grade_level_id'=>'']],['admin',['subject_code'=>'']],['admin',['subject_name'=>str_repeat('X',101)]],['admin',['day_of_week'=>'invalid']],['admin',['start_time'=>'25:00']],['admin',['end_time'=>'08:00']],['admin',['end_time'=>'07:00']],['admin',['late_threshold'=>'61']]] as [$role,$change]) {
    try {
        create_subject_with_schedule($pdo,$role,array_replace($form,$change),'test-token');
        throw new RuntimeException('Invalid submission accepted');
    } catch(InvalidArgumentException $e) {
        check($pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn()==0,'Invalid submission created a subject');
        check($pdo->query('SELECT COUNT(*) FROM schedules')->fetchColumn()==0,'Invalid submission created a schedule');
        $checks++;
    }
}
$id=create_subject_with_schedule($pdo,'admin',$form,'test-token');
$row=$pdo->query('SELECT * FROM subjects')->fetch(PDO::FETCH_ASSOC);
$schedule=$pdo->query('SELECT * FROM schedules')->fetch(PDO::FETCH_ASSOC);
check((int)$row['id']===$id && (int)$row['professor_id']===1 && (int)$row['grade_level_id']===1,'Subject assignment failed');
check((int)$schedule['subject_id']===$id && $schedule['day_of_week']==='Sunday' && $schedule['start_time']==='08:00' && $schedule['end_time']==='09:00','Schedule not saved');
$checks++;
$pdo->exec("CREATE TRIGGER reject_schedule BEFORE INSERT ON schedules BEGIN SELECT RAISE(ABORT, 'Simulated schedule failure'); END");
try {
    create_subject_with_schedule($pdo,'admin',$form,'test-token');
    throw new RuntimeException('Expected schedule failure');
} catch(PDOException $e) {
    check((int)$pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn()===1,'Partial subject left after failed schedule');
    check((int)$pdo->query('SELECT COUNT(*) FROM schedules')->fetchColumn()===1,'Unexpected schedule persisted');
    $checks++;
}
echo "PASS: $checks subject/schedule creation checks.\n";
