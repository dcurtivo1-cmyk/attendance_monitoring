<?php
// config/schema_setup.php
require_once __DIR__ . '/student_sections.php';

function ensure_system_schema(PDO $pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        // 1. grade_levels table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grade_levels` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `grade_name` VARCHAR(50) NOT NULL UNIQUE,
                `grade_number` INT NOT NULL,
                `description` VARCHAR(150) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 1A. password_resets table for Forgot Password functionality
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(100) NOT NULL,
                `token_hash` VARCHAR(64) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                INDEX `idx_email` (`email`),
                INDEX `idx_token_hash` (`token_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // OTP recovery records use Unix timestamps to avoid timezone differences.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `password_reset_otps` (
    `request_id` CHAR(64) PRIMARY KEY,
    `user_id` INT NOT NULL,
    `otp_hash` VARCHAR(255) NOT NULL,
    `created_at` BIGINT NOT NULL,
    `expires_at` BIGINT NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `verified_at` BIGINT NULL,
    `used_at` BIGINT NULL,
    INDEX `idx_otp_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 1B. Ensure users table has status, grade_level, guardian_contact, student_subtitle columns for Student Records
        $statusCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'status'")->fetch();
        if (!$statusCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' AFTER `course_section`");
        }
        $gradeCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'grade_level'")->fetch();
        if (!$gradeCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `grade_level` VARCHAR(50) NOT NULL DEFAULT 'Grade 8' AFTER `course_section`");
        }
        $guardianCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'guardian_contact'")->fetch();
        if (!$guardianCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `guardian_contact` VARCHAR(50) NOT NULL DEFAULT '+63 917 123 4567' AFTER `grade_level`");
        }
        $subtitleCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'student_subtitle'")->fetch();
        if (!$subtitleCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `student_subtitle` VARCHAR(100) NULL DEFAULT NULL AFTER `guardian_contact`");
        }

        // 1C. Ensure users table has first_name, middle_initial, last_name columns
        $fnCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'first_name'")->fetch();
        if (!$fnCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(50) NULL DEFAULT NULL AFTER `name`");
        }
        $miCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'middle_initial'")->fetch();
        if (!$miCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `middle_initial` VARCHAR(10) NULL DEFAULT NULL AFTER `first_name`");
        }
        $lnCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'last_name'")->fetch();
        if (!$lnCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(50) NULL DEFAULT NULL AFTER `middle_initial`");
        }

        // 2. Add grade_level_id to subjects if not exists
        $colCheck = $pdo->query("SHOW COLUMNS FROM `subjects` LIKE 'grade_level_id'")->fetch();
        if (!$colCheck) {
            $pdo->exec("ALTER TABLE `subjects` ADD COLUMN `grade_level_id` INT NULL AFTER `subject_name`");
        }

        // 3. teachers table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `teachers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NULL,
                `teacher_id` VARCHAR(50) NOT NULL UNIQUE,
                `full_name` VARCHAR(100) NOT NULL,
                `department_code` VARCHAR(50) NULL DEFAULT 'FACULTY',
                `grade_level_id` INT NULL,
                `subject_id` INT NULL,
                `grade_level_name` VARCHAR(50) NOT NULL DEFAULT 'Grade 7',
                `subject_name` VARCHAR(100) NOT NULL DEFAULT 'English',
                `email` VARCHAR(100) NOT NULL UNIQUE,
                `contact_number` VARCHAR(30) NOT NULL DEFAULT '',
                `status` ENUM('Active', 'Inactive', 'On Leave') NOT NULL DEFAULT 'Active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
                FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $deptColCheck = $pdo->query("SHOW COLUMNS FROM `teachers` LIKE 'department_code'")->fetch();
        if (!$deptColCheck) {
            $pdo->exec("ALTER TABLE `teachers` ADD COLUMN `department_code` VARCHAR(50) NULL DEFAULT 'FACULTY' AFTER `full_name`");
        }

        $secColCheck = $pdo->query("SHOW COLUMNS FROM `teachers` LIKE 'section_name'")->fetch();
        if (!$secColCheck) {
            $pdo->exec("ALTER TABLE `teachers` ADD COLUMN `section_name` VARCHAR(100) NOT NULL DEFAULT 'Section A' AFTER `grade_level_name`");
        }

        // 4. teacher_assignments table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `teacher_assignments` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `teacher_id` INT NOT NULL,
                `grade_level_id` INT NOT NULL,
                `subject_id` INT NULL,
                `subject_name` VARCHAR(100) NOT NULL,
                `section_name` VARCHAR(50) NULL DEFAULT 'Section A',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 5. teacher_attendance table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `teacher_attendance` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `teacher_id` INT NOT NULL,
                `subject_name` VARCHAR(100) NOT NULL,
                `grade_level_name` VARCHAR(50) NOT NULL,
                `date` DATE NOT NULL,
                `time_in` TIME NULL,
                `time_out` TIME NULL,
                `status` ENUM('Present', 'Late', 'Absent') NOT NULL DEFAULT 'Present',
                `remarks` VARCHAR(255) NULL,
                `recorded_by` INT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_teacher_daily` (`teacher_id`, `date`),
                FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed grade_levels if empty
        $gradeCount = (int)$pdo->query("SELECT COUNT(*) FROM `grade_levels`")->fetchColumn();
        if ($gradeCount === 0) {
            $pdo->exec("
                INSERT INTO `grade_levels` (`id`, `grade_name`, `grade_number`, `description`) VALUES
                (1, 'Grade 7', 7, 'Junior High School - Grade 7'),
                (2, 'Grade 8', 8, 'Junior High School - Grade 8'),
                (3, 'Grade 9', 9, 'Junior High School - Grade 9'),
                (4, 'Grade 10', 10, 'Junior High School - Grade 10')
                ON DUPLICATE KEY UPDATE `grade_name` = VALUES(`grade_name`);
            ");
        }

        // Keep available sections even when no students are assigned yet.
        $pdo->exec("CREATE TABLE IF NOT EXISTS school_sections (
            id INT AUTO_INCREMENT PRIMARY KEY, grade_level_id INT NOT NULL, section_name VARCHAR(50) NOT NULL,
            UNIQUE KEY unique_grade_section (grade_level_id, section_name),
            FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $sectionCount = $pdo->prepare('SELECT COUNT(*) FROM school_sections WHERE grade_level_id = ?');
        $sectionInsert = $pdo->prepare('INSERT INTO school_sections (grade_level_id, section_name) VALUES (?, ?)');
        foreach ($pdo->query('SELECT id, grade_name FROM grade_levels')->fetchAll(PDO::FETCH_ASSOC) as $grade) {
            $sectionCount->execute([$grade['id']]);
            if ((int)$sectionCount->fetchColumn() === 0) {
                foreach (default_grade_section_names($grade['grade_name']) as $sectionName) $sectionInsert->execute([$grade['id'], $sectionName]);
            }
        }

        // Seed Grade Level Curriculum Subjects if not yet linked
        $coreSubjects = [
            'English',
            'Mathematics',
            'Science',
            'Filipino',
            'Araling Panlipunan',
            'MAPEH',
            'TLE',
            'Computer'
        ];

        $profId = (int)$pdo->query("SELECT id FROM `users` WHERE role IN ('professor','admin') ORDER BY role='professor' DESC LIMIT 1")->fetchColumn();
        if ($profId <= 0) $profId = 1;

        $gradedSubjectCount = (int)$pdo->query("SELECT COUNT(*) FROM `subjects` WHERE `grade_level_id` IS NOT NULL")->fetchColumn();
        if ($gradedSubjectCount === 0) {
            $insSub = $pdo->prepare("INSERT INTO `subjects` (`subject_code`, `subject_name`, `grade_level_id`, `professor_id`) VALUES (?, ?, ?, ?)");
            $grades = [
                1 => 'G7',
                2 => 'G8',
                3 => 'G9',
                4 => 'G10'
            ];
            foreach ($grades as $gId => $prefix) {
                foreach ($coreSubjects as $idx => $sName) {
                    $code = $prefix . '-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $sName), 0, 3)) . ($idx + 1);
                    $insSub->execute([$code, $sName, $gId, $profId]);
                }
            }
        }

        // Seed 12 Sample Teachers (10 Present, 1 Absent, 1 Late)
        $teacherCount = (int)$pdo->query("SELECT COUNT(*) FROM `teachers`")->fetchColumn();
        if ($teacherCount === 0) {
            $defaultPass = '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW'; // password123
            $sampleTeachers = [
                ['TCH-2026-001', 'Mr. John Angac',       'HCI (IT)',  2, 'Grade 8',  'Computer',           'john.angac@school.edu.ph',       '0917-234-5601', 'Active', '07:52:00', '15:45:00', 'Present'],
                ['TCH-2026-002', 'Ms. Donna Santos',     'Ethics',    3, 'Grade 9',  'Araling Panlipunan', 'donna.santos@school.edu.ph',     '0917-234-5602', 'Active', '08:10:00', '15:20:00', 'Absent'],
                ['TCH-2026-003', 'Ma\'am Jay',           'NSTP2',     1, 'Grade 7',  'Filipino',           'jay.reyes@school.edu.ph',        '0917-234-5603', 'Active', '07:50:00', '15:30:00', 'Present'],
                ['TCH-2026-004', 'Sir Salazar',          'PATHFIT2',  4, 'Grade 10', 'MAPEH',              'salazar@school.edu.ph',          '0917-234-5604', 'Active', '08:00:00', '16:15:00', 'Late'],
                ['TCH-2026-005', 'Ma\'am Rea',           'SCITECH',   2, 'Grade 8',  'Science',            'rea.cruz@school.edu.ph',         '0917-234-5605', 'Active', '07:45:00', '15:25:00', 'Present'],
                ['TCH-2026-006', 'Ma\'am Leila',         'ENVICIS',   3, 'Grade 9',  'English',            'leila.gomez@school.edu.ph',      '0917-234-5606', 'Active', '08:05:00', '16:10:00', 'Present'],
                ['TCH-2026-007', 'Sir Jeryco',           'WEBSYS',    4, 'Grade 10', 'Mathematics',        'jeryco.lim@school.edu.ph',       '0917-234-5607', 'Active', '07:55:00', '15:40:00', 'Present'],
                ['TCH-2026-008', 'Ms. Clarissa Mendoza', 'FILIPINO',  1, 'Grade 7',  'Filipino',           'clarissa.mendoza@school.edu.ph', '0917-234-5608', 'Active', '07:48:00', '15:50:00', 'Present'],
                ['TCH-2026-009', 'Mr. Marco Villanueva', 'MATH',      2, 'Grade 8',  'Mathematics',        'marco.v@school.edu.ph',          '0917-234-5609', 'Active', '07:50:00', '16:00:00', 'Present'],
                ['TCH-2026-010', 'Ms. Patricia Aquino',  'SCIENCE',   3, 'Grade 9',  'Science',            'patricia.aquino@school.edu.ph',  '0917-234-5610', 'Active', '07:42:00', '15:45:00', 'Present'],
                ['TCH-2026-011', 'Mr. Dennis Bautista',  'TLE-ICT',   4, 'Grade 10', 'TLE',                'dennis.bautista@school.edu.ph',  '0917-234-5611', 'Active', '07:53:00', '16:05:00', 'Present'],
                ['TCH-2026-012', 'Ms. Angela Soriano',   'ENGLISH',   1, 'Grade 7',  'English',            'angela.soriano@school.edu.ph',   '0917-234-5612', 'Active', '07:46:00', '15:35:00', 'Present']
            ];

            $userIns = $pdo->prepare("
                INSERT IGNORE INTO `users` (`identifier`, `name`, `email`, `password`, `role`, `course_section`, `qr_token`)
                VALUES (?, ?, ?, ?, 'professor', NULL, ?)
            ");

            $tchIns = $pdo->prepare("
                INSERT IGNORE INTO `teachers` (`user_id`, `teacher_id`, `full_name`, `department_code`, `grade_level_id`, `subject_id`, `grade_level_name`, `subject_name`, `section_name`, `email`, `contact_number`, `status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $assignIns = $pdo->prepare("
                INSERT INTO `teacher_assignments` (`teacher_id`, `grade_level_id`, `subject_id`, `subject_name`, `section_name`)
                VALUES (?, ?, ?, ?, ?)
            ");

            $attIns = $pdo->prepare("
                INSERT IGNORE INTO `teacher_attendance` (`teacher_id`, `subject_name`, `grade_level_name`, `date`, `time_in`, `time_out`, `status`, `remarks`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $today = date('Y-m-d');

            foreach ($sampleTeachers as $t) {
                [$tCode, $tName, $deptCode, $gId, $gName, $sName, $tEmail, $tPhone, $tStatus, $tIn, $tOut, $attStatus] = $t;

                $qrToken = 'PROF_QR_' . md5($tCode);
                $userIns->execute([$tCode, $tName, $tEmail, $defaultPass, $qrToken]);

                $uStmt = $pdo->prepare("SELECT id FROM `users` WHERE `email` = ? LIMIT 1");
                $uStmt->execute([$tEmail]);
                $uId = $uStmt->fetchColumn() ?: null;

                $sStmt = $pdo->prepare("SELECT id FROM `subjects` WHERE `grade_level_id` = ? AND `subject_name` = ? LIMIT 1");
                $sStmt->execute([$gId, $sName]);
                $sId = $sStmt->fetchColumn() ?: null;

                $tchIns->execute([$uId, $tCode, $tName, $deptCode, $gId, $sId, $gName, $sName, default_grade_section_names($gName)[0], $tEmail, $tPhone, $tStatus]);

                $getTStmt = $pdo->prepare("SELECT id FROM `teachers` WHERE `teacher_id` = ? LIMIT 1");
                $getTStmt->execute([$tCode]);
                $newTeacherId = (int)$getTStmt->fetchColumn();
                if ($newTeacherId <= 0) continue;
                $pdo->prepare('UPDATE users SET grade_level = ?, course_section = ? WHERE id = ?')->execute([$gName, default_grade_section_names($gName)[0], $uId]);

                $assignIns->execute([$newTeacherId, $gId, $sId, $sName, default_grade_section_names($gName)[0]]);

                if ($uId && $sId) {
                    $pdo->prepare("UPDATE `subjects` SET `professor_id` = ? WHERE `id` = ?")->execute([$uId, $sId]);
                }

                // Seed today's attendance
                $attIns->execute([$newTeacherId, $sName, $gName, $today, $tIn, $tOut, $attStatus, 'Daily check']);

                // Seed past 6 days of attendance history
                for ($d = 1; $d <= 6; $d++) {
                    $pastDate = date('Y-m-d', strtotime("-$d days"));
                    $dayOfWeek = date('N', strtotime($pastDate));
                    if ($dayOfWeek >= 6) continue;

                    if ($d === 2 && $tCode === 'TCH-2026-002') {
                        $attIns->execute([$newTeacherId, $sName, $gName, $pastDate, '08:10:00', '15:20:00', 'Absent', 'On leave']);
                    } elseif ($d === 3 && $tCode === 'TCH-2026-004') {
                        $attIns->execute([$newTeacherId, $sName, $gName, $pastDate, '08:00:00', '16:15:00', 'Late', 'Traffic']);
                    } else {
                        $attIns->execute([$newTeacherId, $sName, $gName, $pastDate, '07:50:00', '15:45:00', 'Present', 'On time']);
                    }
                }
            }
        }

        // Daily attendance is recorded only by actual scans or authorized manual entries.

        // 6. Seed / Sync the 7 Student Records to match High School sections (no BSIT / college codes)
        $defaultStudentPass = '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW'; // password123
        $seedStudents = [
            ['2025-07-00594', 'Edcel Ventura',      '2025-07-00594', 'Grade 7',  'Grade 7 - Emerald',     'edcel01ventura@gmail.com',      '+63 912 345 6789', 'Active'],
            ['2026-000-001',  'RYAN',               '2026-000-001',  'Grade 8',  'Grade 8 - Topaz',   'ryan@gmail.com',                '+63 917 123 4567', 'Active'],
            ['2026-000-002',  'DEN MAR',            'admin',         'Grade 9',  'Grade 9 - Diamond',    'denmar@gmail.com',              '+63 918 765 4321', 'Active'],
            ['2026-005',      'luna dela cruz',     'STUD-2026-005', 'Grade 10', 'Grade 10 - Onyx',      'stud@gmail.com',                '+63 919 876 5432', 'Active'],
            ['2026-101',      'Maria Clara Santos', 'STUD-2026-101', 'Grade 8',  'Grade 8 - Sapphire', 'maria.santos@student.edu.ph',   '+63 920 111 2222', 'Active'],
            ['2026-102',      'Juan Dela Cruz',     'STUD-2026-102', 'Grade 7',  'Grade 7 - Jade',  'juan.delaCruz@gmail.com',       '+63 921 333 4444', 'Active'],
            ['2026-103',      'Ana Reyes',          'STUD-2026-103', 'Grade 10', 'Grade 10 - Quartz', 'ana.reyes@gmail.com',           '+63 922 555 6666', 'Active'],
        ];

        $stuCount = (int)$pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'student'")->fetchColumn();
        if ($stuCount === 0) {
            $stuUpsert = $pdo->prepare("
                INSERT INTO `users` (`identifier`, `name`, `email`, `password`, `role`, `course_section`, `grade_level`, `guardian_contact`, `student_subtitle`, `status`, `qr_token`)
                VALUES (?, ?, ?, ?, 'student', ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `name` = VALUES(`name`),
                    `course_section` = VALUES(`course_section`),
                    `grade_level` = VALUES(`grade_level`),
                    `guardian_contact` = VALUES(`guardian_contact`),
                    `student_subtitle` = VALUES(`student_subtitle`),
                    `status` = VALUES(`status`)
            ");
            foreach ($seedStudents as $st) {
                [$sId, $sName, $sSub, $sGrade, $sSec, $sEmail, $sPhone, $sStatus] = $st;
                $qrTok = 'STU_QR_' . md5($sId);
                $stuUpsert->execute([$sId, $sName, $sEmail, $defaultStudentPass, $sSec, $sGrade, $sPhone, $sSub, $sStatus, $qrTok]);
            }
        }

        // Convert any remaining college course_section (BSIT / IT-*) on students to High School sections
        $pdo->exec("
            UPDATE `users`
            SET `course_section` = CASE
                WHEN `grade_level` = 'Grade 7'  THEN 'Grade 7 - Emerald'
                WHEN `grade_level` = 'Grade 8'  THEN 'Grade 8 - Sapphire'
                WHEN `grade_level` = 'Grade 9'  THEN 'Grade 9 - Diamond'
                WHEN `grade_level` = 'Grade 10' THEN 'Grade 10 - Onyx'
                ELSE 'Grade 8 - Sapphire'
            END
            WHERE `role` = 'student'
              AND (`course_section` IS NULL OR `course_section` = '' OR `course_section` LIKE '%BSIT%' OR `course_section` LIKE '%bsit%' OR `course_section` LIKE 'IT-%')
        ");

        // Remove legacy college subjects (where grade_level_id IS NULL, e.g. IT301, IT302) from student enrollments
        $pdo->exec("
            DELETE ss FROM `student_subjects` ss
            JOIN `subjects` s ON s.id = ss.subject_id
            WHERE s.grade_level_id IS NULL
        ");

        // 7. Ensure subjects are linked to their respective teachers and students are enrolled in their grade level subjects
        $pdo->exec("
            UPDATE `subjects` s
            JOIN `teachers` t ON t.subject_id = s.id AND t.user_id IS NOT NULL
            SET s.professor_id = t.user_id
            WHERE s.professor_id IS NULL
        ");

        // Ensure every subject has a class schedule with start_time and end_time
        $missingSchedSubjects = $pdo->query("
            SELECT s.id, s.subject_code
            FROM `subjects` s
            LEFT JOIN `schedules` sc ON sc.subject_id = s.id
            WHERE sc.id IS NULL
            ORDER BY s.id ASC
        ")->fetchAll();

        if (!empty($missingSchedSubjects)) {
            $timeSlots = [
                ['Monday',    '07:30:00', '08:30:00'],
                ['Monday',    '08:30:00', '09:30:00'],
                ['Tuesday',   '09:45:00', '10:45:00'],
                ['Tuesday',   '10:45:00', '11:45:00'],
                ['Wednesday', '13:00:00', '14:00:00'],
                ['Thursday',  '14:00:00', '15:00:00'],
                ['Friday',    '15:00:00', '16:00:00'],
                ['Friday',    '16:00:00', '17:00:00'],
            ];
            $insSched = $pdo->prepare("
                INSERT INTO `schedules` (`subject_id`, `day_of_week`, `start_time`, `end_time`, `late_threshold_mins`)
                VALUES (?, ?, ?, ?, 15)
            ");
            foreach ($missingSchedSubjects as $idx => $ms) {
                $slot = $timeSlots[$idx % count($timeSlots)];
                $insSched->execute([$ms['id'], $slot[0], $slot[1], $slot[2]]);
            }
        }

        $pdo->exec("
            INSERT IGNORE INTO `student_subjects` (`student_id`, `subject_id`)
            SELECT u.id, s.id
            FROM `users` u
            JOIN `grade_levels` gl ON gl.grade_name = u.grade_level
            JOIN `subjects` s ON s.grade_level_id = gl.id
            WHERE u.role = 'student'
        ");
    } catch (PDOException $e) {
        // Silently allow execution if minor schema notice occurs
    }
}

