-- =========================================================================
-- ODB MONTESSORI SCHOOL OF NOVALICHES
-- Student & Teacher Attendance Monitoring System Database Schema
-- =========================================================================

CREATE DATABASE IF NOT EXISTS `attendance_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `attendance_db`;

-- 1. Grade Levels Table
CREATE TABLE IF NOT EXISTS `grade_levels` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `grade_name` VARCHAR(50) NOT NULL UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Users Table (Students, Professors, Admins)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Student ID or Employee ID',
    `name` VARCHAR(100) NOT NULL,
    `first_name` VARCHAR(60) NULL,
    `middle_initial` VARCHAR(10) NULL,
    `last_name` VARCHAR(60) NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('student', 'professor', 'admin') NOT NULL DEFAULT 'student',
    `course_section` VARCHAR(50) NULL COMMENT 'e.g. Section A - Bonifacio',
    `grade_level` VARCHAR(50) NULL DEFAULT 'Grade 8',
    `guardian_contact` VARCHAR(50) NULL DEFAULT '+63 917 123 4567',
    `student_subtitle` VARCHAR(50) NULL,
    `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `qr_token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Unique hash used for the QR code',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Subjects Table
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_code` VARCHAR(20) NOT NULL,
    `subject_name` VARCHAR(100) NOT NULL,
    `professor_id` INT NULL,
    `grade_level_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`professor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Schedules Table (Days of the week: Monday to Sunday)
CREATE TABLE IF NOT EXISTS `schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_id` INT NOT NULL,
    `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL DEFAULT 'Monday',
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `late_threshold_mins` INT DEFAULT 15 COMMENT 'Minutes after start time considered late',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Student Subject Enrollment Table
CREATE TABLE IF NOT EXISTS `student_subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `subject_id` INT NOT NULL,
    `enrolled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_student_subject` (`student_id`, `subject_id`),
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Student Attendance Records Table
CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `subject_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `time_in` TIME NOT NULL,
    `status` ENUM('Present', 'Late', 'Absent', 'Excused') NOT NULL DEFAULT 'Present',
    `scanned_by` INT NULL COMMENT 'Professor ID who scanned or verified',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_daily_attendance` (`student_id`, `subject_id`, `date`),
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Teachers Table
CREATE TABLE IF NOT EXISTS `teachers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `teacher_id` VARCHAR(50) NOT NULL UNIQUE,
    `full_name` VARCHAR(100) NOT NULL,
    `department_code` VARCHAR(50) DEFAULT 'FACULTY',
    `grade_level_id` INT NULL,
    `subject_id` INT NULL,
    `grade_level_name` VARCHAR(50) NOT NULL,
    `subject_name` VARCHAR(100) NOT NULL,
    `section_name` VARCHAR(50) DEFAULT 'Section A - Rizal',
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `contact_number` VARCHAR(50) DEFAULT '+63 917 123 4567',
    `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Teacher Assignments Table
CREATE TABLE IF NOT EXISTS `teacher_assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `teacher_id` INT NOT NULL,
    `grade_level_id` INT NULL,
    `subject_id` INT NULL,
    `subject_name` VARCHAR(100) NOT NULL,
    `section_name` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Teacher Attendance Records Table
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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_daily_teacher_attendance` (`teacher_id`, `date`),
    FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================================
-- INITIAL SEED DATA
-- Default password for all seed accounts: password123
-- =========================================================================

-- Grade Levels
INSERT INTO `grade_levels` (`id`, `grade_name`) VALUES
(1, 'Grade 7'),
(2, 'Grade 8'),
(3, 'Grade 9'),
(4, 'Grade 10')
ON DUPLICATE KEY UPDATE `grade_name` = VALUES(`grade_name`);

-- Core Initial Users
INSERT INTO `users` (`id`, `identifier`, `name`, `first_name`, `middle_initial`, `last_name`, `email`, `password`, `role`, `course_section`, `grade_level`, `qr_token`) VALUES
(1, 'ADMIN-2026-001', 'System Administrator', 'System', 'A.', 'Administrator', 'admin@school.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'admin', NULL, NULL, 'ADMIN_TOKEN_001'),
(2, 'PROF-2026-001', 'Teacher Juan Dela Cruz', 'Juan', 'M.', 'Dela Cruz', 'teacher.juan@school.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'professor', NULL, NULL, 'PROF_TOKEN_JUAN_001'),
(3, 'PROF-2026-002', 'Mr. John Angac', 'John', 'B.', 'Angac', 'john.angac@school.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'professor', NULL, NULL, 'PROF_TOKEN_JOHN_002'),
(4, 'PROF-2026-003', 'Mr. Marco Villanueva', 'Marco', 'V.', 'Villanueva', 'marco.v@school.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'professor', NULL, NULL, 'PROF_TOKEN_MARCO_003'),
(5, 'PROF-2026-004', 'Ma\'am Rea Santos', 'Rea', 'S.', 'Santos', 'rea.santos@school.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'professor', NULL, NULL, 'PROF_TOKEN_REA_004'),
(6, 'STUD-2026-001', 'Juan Dela Cruz', 'Juan', 'M.', 'Dela Cruz', 'juan.delacruz@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'Section A - Rizal', 'Grade 7', 'STU_QR_JUAN_001'),
(7, 'STUD-2026-002', 'Maria Santos', 'Maria', 'S.', 'Santos', 'maria.santos@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'Section A - Bonifacio', 'Grade 8', 'STU_QR_MARIA_002'),
(8, 'STUD-2026-003', 'Edcel Ventura', 'Edcel', 'V.', 'Ventura', 'edcel.ventura@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'Section A - Mabini', 'Grade 9', 'STU_QR_EDCEL_003'),
(9, 'STUD-2026-004', 'Ryan Garcia', 'Ryan', 'G.', 'Garcia', 'ryan.garcia@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'Section A - Luna', 'Grade 10', 'STU_QR_RYAN_004'),
(10, 'STUD-2026-005', 'Luna Dela Cruz', 'Luna', 'D.', 'Dela Cruz', 'luna.delacruz@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'Section A - Rizal', 'Grade 7', 'STU_QR_LUNA_005'),
(11, 'STUD-2026-006', 'Ana Reyes', 'Ana', 'R.', 'Reyes', 'ana.reyes@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'Section B - Del Pilar', 'Grade 10', 'STU_QR_ANA_006')
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- Core Subjects
INSERT INTO `subjects` (`id`, `subject_code`, `subject_name`, `professor_id`, `grade_level_id`) VALUES
(1, 'G8-ENG1', 'English', 2, 2),
(2, 'G8-MAT2', 'Mathematics', 4, 2),
(3, 'G8-SCI3', 'Science', 5, 2),
(4, 'G8-FIL4', 'Filipino', 2, 2),
(5, 'G8-ARA5', 'Araling Panlipunan', 2, 2),
(6, 'G8-MAP6', 'MAPEH', 2, 2),
(7, 'G8-TLE7', 'TLE', 2, 2),
(8, 'G8-COM8', 'Computer', 3, 2),
(9, 'G7-MAT1', 'Mathematics', 4, 1),
(10, 'G9-SCI1', 'Science', 5, 3),
(11, 'G10-ARA5', 'Araling Panlipunan', 2, 4)
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- Class Schedules (Format: Monday - Sunday with time slots)
INSERT INTO `schedules` (`subject_id`, `day_of_week`, `start_time`, `end_time`, `late_threshold_mins`) VALUES
(1, 'Monday', '07:30:00', '08:30:00', 15),
(2, 'Tuesday', '08:30:00', '09:30:00', 15),
(3, 'Wednesday', '09:45:00', '10:45:00', 15),
(4, 'Thursday', '10:45:00', '11:45:00', 15),
(5, 'Friday', '13:00:00', '14:00:00', 15),
(6, 'Monday', '14:00:00', '15:00:00', 15),
(7, 'Tuesday', '15:00:00', '16:00:00', 15),
(8, 'Wednesday', '16:00:00', '17:00:00', 15),
(9, 'Thursday', '08:00:00', '09:00:00', 15),
(10, 'Friday', '09:00:00', '10:00:00', 15),
(11, 'Monday', '13:00:00', '14:00:00', 15)
ON DUPLICATE KEY UPDATE `subject_id` = VALUES(`subject_id`);

-- Teachers Directory
INSERT INTO `teachers` (`id`, `user_id`, `teacher_id`, `full_name`, `department_code`, `grade_level_id`, `subject_id`, `grade_level_name`, `subject_name`, `section_name`, `email`, `contact_number`, `status`) VALUES
(1, 2, 'TCH-2026-001', 'Teacher Juan Dela Cruz', 'FACULTY', 2, 5, 'Grade 8', 'Araling Panlipunan', 'Section A - Bonifacio', 'teacher.juan@school.edu.ph', '+63 917 123 4567', 'Active'),
(2, 3, 'TCH-2026-002', 'Mr. John Angac', 'FACULTY', 2, 8, 'Grade 8', 'Computer', 'Section A - Bonifacio', 'john.angac@school.edu.ph', '+63 917 234 5678', 'Active'),
(3, 4, 'TCH-2026-003', 'Mr. Marco Villanueva', 'FACULTY', 2, 2, 'Grade 8', 'Mathematics', 'Section A - Bonifacio', 'marco.v@school.edu.ph', '+63 917 345 6789', 'Active'),
(4, 5, 'TCH-2026-004', 'Ma\'am Rea Santos', 'FACULTY', 2, 3, 'Grade 8', 'Science', 'Section A - Bonifacio', 'rea.santos@school.edu.ph', '+63 917 456 7890', 'Active')
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- Teacher Assignments
INSERT INTO `teacher_assignments` (`teacher_id`, `grade_level_id`, `subject_id`, `subject_name`, `section_name`) VALUES
(1, 2, 5, 'Araling Panlipunan', 'Section A - Bonifacio'),
(1, 2, 1, 'English', 'Section A - Bonifacio'),
(1, 2, 4, 'Filipino', 'Section A - Bonifacio'),
(1, 4, 11, 'Araling Panlipunan', 'Section B - Del Pilar'),
(2, 2, 8, 'Computer', 'Section A - Bonifacio'),
(3, 2, 2, 'Mathematics', 'Section A - Bonifacio'),
(4, 2, 3, 'Science', 'Section A - Bonifacio')
ON DUPLICATE KEY UPDATE `teacher_id` = VALUES(`teacher_id`);

-- Teacher Attendance Logs (For Admin Portal Teacher Attendance)
INSERT INTO `teacher_attendance` (`teacher_id`, `subject_name`, `grade_level_name`, `date`, `time_in`, `time_out`, `status`, `remarks`) VALUES
(1, 'Araling Panlipunan', 'Grade 8', CURDATE(), '07:28:00', '16:00:00', 'Present', 'On time - In classroom'),
(2, 'Computer', 'Grade 8', CURDATE(), '07:35:00', '16:00:00', 'Present', 'On time - Lab ready'),
(3, 'Mathematics', 'Grade 8', CURDATE(), '07:55:00', '16:00:00', 'Late', 'Late 10 mins - Traffic'),
(4, 'Science', 'Grade 8', CURDATE(), NULL, NULL, 'Absent', 'Official Leave - Sick')
ON DUPLICATE KEY UPDATE `teacher_id` = VALUES(`teacher_id`);

-- Enroll Students to Core Subjects
INSERT INTO `student_subjects` (`student_id`, `subject_id`) VALUES
(6, 9),
(7, 1), (7, 2), (7, 3), (7, 4), (7, 5), (7, 6), (7, 7), (7, 8),
(8, 10),
(9, 11),
(10, 9),
(11, 11)
ON DUPLICATE KEY UPDATE `student_id` = VALUES(`student_id`);

-- Sample Student Attendance Record
INSERT INTO `attendance` (`student_id`, `subject_id`, `date`, `time_in`, `status`, `scanned_by`) VALUES
(7, 1, CURDATE(), '07:31:00', 'Present', 2),
(11, 11, CURDATE(), '13:02:00', 'Present', 2)
ON DUPLICATE KEY UPDATE `student_id` = VALUES(`student_id`);
