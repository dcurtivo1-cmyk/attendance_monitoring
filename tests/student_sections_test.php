<?php
require __DIR__ . '/../config/student_sections.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE grade_levels (id INTEGER PRIMARY KEY, grade_name TEXT);
CREATE TABLE school_sections (grade_level_id INTEGER, section_name TEXT);
INSERT INTO grade_levels VALUES (1, 'Grade 7'), (2, 'Grade 8'), (3, 'Grade 9'), (4, 'Grade 10');
INSERT INTO school_sections VALUES (1, 'Grade 7 - Section A'), (1, 'Grade 7 - Section B'), (2, 'Grade 8 - Section A'), (2, 'Grade 8 - Section B');");
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$map = get_student_sections_by_grade($pdo);
check(array_keys($map) === ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'], 'Grades must sort naturally');
check($map['Grade 7'] === ['Grade 7 - Section A', 'Grade 7 - Section B'], 'Read catalog sections without requiring enrolled students');
check($map['Grade 8'] === ['Grade 8 - Section A', 'Grade 8 - Section B'], 'Keep sections scoped to their own grade');
check($map['Grade 9'] === [] && $map['Grade 10'] === [], 'Empty grades must not invent sections');
check(is_valid_student_section($map, 'Grade 7', 'Grade 7 - Section A'), 'Valid pairing must pass');
check(!is_valid_student_section($map, 'Grade 8', 'Grade 7 - Section A'), 'Mismatched grade must fail');
check(!is_valid_student_section($map, 'Unknown grade', 'Grade 7 - Section A'), 'Unknown grade must fail');
check(!is_valid_student_section($map, 'Grade 7', ''), 'Missing section must fail');
$pdo->exec("UPDATE school_sections SET section_name = 'Grade 7 - Updated' WHERE grade_level_id = 1 AND section_name = 'Grade 7 - Section A'");
$map = get_student_sections_by_grade($pdo);
check(is_valid_student_section($map, 'Grade 7', 'Grade 7 - Updated'), 'Section renames must appear');
check(!is_valid_student_section($map, 'Grade 7', 'Grade 7 - Section A'), 'Old section must no longer be accepted');
echo "PASS: grade ordering, authoritative catalog, empty grades, valid/invalid pairings and renamed sections.\n";
