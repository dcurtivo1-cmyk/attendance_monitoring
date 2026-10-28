<?php
// Run with PHP CLI. SQLite fixtures never modify actual user accounts.
require __DIR__ . '/../config/account_profile.php';
class ProfileTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function snapshot(PDO $pdo): array {
    $result = [];
    foreach (['users', 'teachers', 'password_reset_otps', 'password_resets'] as $table) $result[$table] = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
    return $result;
}
function reject(PDO $pdo, int $id, string $role, array $input): void {
    $before = snapshot($pdo);
    try {
        save_account_profile($pdo, $id, $role, $input, 'valid-csrf');
        throw new RuntimeException('Expected rejection');
    } catch (InvalidArgumentException $e) {
        check(snapshot($pdo) === $before, 'Rejected update changed records');
    }
}
$checks = 0;
foreach ([1 => 'student', 2 => 'professor'] as $id => $role) {
    $pdo = new ProfileTestPDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, identifier TEXT, name TEXT, first_name TEXT, middle_initial TEXT, last_name TEXT, email TEXT COLLATE NOCASE UNIQUE, password TEXT, role TEXT, status TEXT, grade_level TEXT, course_section TEXT, guardian_contact TEXT);
    CREATE TABLE teachers (id INTEGER PRIMARY KEY, user_id INTEGER, full_name TEXT, email TEXT, contact_number TEXT);
    CREATE TABLE password_reset_otps (request_id TEXT, user_id INTEGER, used_at INTEGER);
    CREATE TABLE password_resets (email TEXT);");
    $insert = $pdo->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([1 => 'student', 2 => 'professor', 3 => 'student'] as $uid => $urole) {
        $insert->execute([$uid, 'ID-'.$uid, 'Original Person', 'Original', '', 'Person', "account$uid@example.com", password_hash('Old-password-123', PASSWORD_BCRYPT), $urole, 'Active', 'Grade 7', 'Grade 7 - Emerald', '09170000000']);
        $pdo->prepare('INSERT INTO password_reset_otps VALUES (?, ?, NULL)')->execute(['request-'.$uid, $uid]);
        $pdo->prepare('INSERT INTO password_resets VALUES (?)')->execute(["account$uid@example.com"]);
    }
    $pdo->exec("INSERT INTO teachers VALUES (1,2,'Original Person','account2@example.com','09170000000'),(2,2,'Original Person','account2@example.com','09170000000')");
    $profile = ['csrf'=>'valid-csrf', 'action'=>'profile', 'first_name'=>'Updated', 'middle_initial'=>'M', 'last_name'=>'Person', 'name'=>'Prof. Updated Person', 'email'=>"account$id@example.com", 'contact'=>'09991234567'];
    $before = snapshot($pdo);
    $updated = save_account_profile($pdo,$id,$role,$profile + ['id'=>3, 'user_id'=>3, 'role'=>'admin', 'grade_level'=>'Grade 10', 'course_section'=>'Invalid'],'valid-csrf');
    check($updated['name'] === ($role === 'student' ? 'Updated M. Person' : 'Updated Person'), 'Name not saved');
    check($updated['role'] === $role && $updated['grade_level'] === 'Grade 7' && $updated['course_section'] === 'Grade 7 - Emerald', 'Protected fields changed');
    check(!isset($updated['password']), 'Returned password hash');
    check(snapshot($pdo)['users'][2] === $before['users'][2], 'Other account changed');
    check(snapshot($pdo)['users'][$id-1]['password'] === $before['users'][$id-1]['password'], 'Profile save changed password');
    if ($role === 'professor') check((int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE full_name='Updated Person' AND contact_number='09991234567'")->fetchColumn() === 2,'Teacher records not synced');
    else check($updated['guardian_contact'] === '09991234567' && $updated['first_name'] === 'Updated','Student fields not saved');
    $checks++;
    foreach ([['csrf'=>'bad'],['email'=>'invalid'],['email'=>"new$id@example.com"],['email'=>'ACCOUNT3@example.com','current_password'=>'Old-password-123'],['contact'=>str_repeat('1',51)],['email'=>['invalid']],['name'=>'','first_name'=>'']] as $change) {
        reject($pdo,$id,$role,array_replace($profile,$change));$checks++;
    }
    $password=['csrf'=>'valid-csrf','action'=>'password','current_password'=>'Old-password-123','new_password'=>'New-password-456','confirm_password'=>'New-password-456'];
    foreach ([['csrf'=>''],['current_password'=>'wrong'],['new_password'=>'short','confirm_password'=>'short'],['new_password'=>str_repeat('x',73),'confirm_password'=>str_repeat('x',73)],['new_password'=>"null\0password",'confirm_password'=>"null\0password"],['confirm_password'=>'mismatch'],['new_password'=>'Old-password-123','confirm_password'=>'Old-password-123']] as $change) {
        reject($pdo,$id,$role,array_replace($password,$change));$checks++;
    }
    reject($pdo,$id,$role==='student'?'professor':'student',$profile);$checks++;
    reject($pdo,$id,'admin',$profile);$checks++;
    save_account_profile($pdo,$id,$role,array_replace($profile,['email'=>"new$id@example.com",'current_password'=>'Old-password-123']),'valid-csrf');
    check((int)$pdo->query("SELECT COUNT(*) FROM password_reset_otps WHERE user_id=$id AND used_at IS NULL")->fetchColumn()===0,'Email edit kept OTP active');
    check((int)$pdo->query("SELECT COUNT(*) FROM password_resets WHERE email='account$id@example.com'")->fetchColumn()===0,'Legacy reset not invalidated');
    if ($role==='professor') check((int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE email='new2@example.com'")->fetchColumn()===2,'Teacher email not synced');
    $checks++;
    $pdo->exec("UPDATE password_reset_otps SET used_at=NULL WHERE user_id=$id");
    save_account_profile($pdo,$id,$role,$password,'valid-csrf');
    $hash=$pdo->query("SELECT password FROM users WHERE id=$id")->fetchColumn();
    check(password_verify('New-password-456',$hash) && !password_verify('Old-password-123',$hash),'Password change failed');
    check(password_get_info($hash)['algoName']==='bcrypt','Password not bcrypt');
    check((int)$pdo->query("SELECT COUNT(*) FROM password_reset_otps WHERE user_id=$id AND used_at IS NULL")->fetchColumn()===0,'Password edit kept OTP active');
    check((int)$pdo->query('SELECT COUNT(*) FROM password_reset_otps WHERE user_id=3 AND used_at IS NULL')->fetchColumn()===1,'Other user OTP affected');
    $checks++;
    if ($role==='professor') {
        $before=snapshot($pdo);
        $pdo->exec("CREATE TRIGGER fail_sync BEFORE UPDATE ON teachers BEGIN SELECT RAISE(ABORT,'Sync failed'); END");
        try {
            save_account_profile($pdo,$id,$role,array_replace($profile,['email'=>'new2@example.com','name'=>'Changed Name']),'valid-csrf');
            throw new RuntimeException('Expected sync failure');
        } catch (PDOException $e) { check(snapshot($pdo)===$before,'Partial save after failed sync');$checks++; }
    }
}
echo "PASS: $checks profile/security checks; actual accounts unchanged.\n";
