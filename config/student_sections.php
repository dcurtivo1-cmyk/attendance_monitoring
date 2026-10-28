<?php
// Distinct section names per year level for new installations and the named-section migration.
function default_grade_section_names(string $grade): array {
    $names = [
        'Grade 7' => ['Emerald', 'Jade', 'Ruby'],
        'Grade 8' => ['Sapphire', 'Topaz', 'Amethyst'],
        'Grade 9' => ['Diamond', 'Pearl'],
        'Grade 10' => ['Onyx', 'Quartz'],
    ];
    return array_map(static fn($name) => $grade . ' - ' . $name, $names[$grade] ?? []);
}

// Authoritative grade/section catalog shared by all enrollment forms.
function get_student_sections_by_grade(PDO $pdo): array {
    $map = [];
    foreach ($pdo->query('SELECT grade_name FROM grade_levels')->fetchAll(PDO::FETCH_COLUMN) as $grade) {
        $map[$grade] = [];
    }
    $rows = $pdo->query('SELECT gl.grade_name, ss.section_name FROM school_sections ss JOIN grade_levels gl ON gl.id = ss.grade_level_id');
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['grade_name']][] = $row['section_name'];
    }
    uksort($map, 'strnatcasecmp');
    foreach ($map as &$sections) {
        natcasesort($sections);
        $sections = array_values($sections);
    }
    unset($sections);
    return $map;
}

function is_valid_student_section(array $map, string $grade, string $section): bool {
    return $grade !== '' && $section !== '' && in_array($section, $map[$grade] ?? [], true);
}

function sync_teacher_user_section(PDO $pdo, int $teacherId): void {
    $pdo->prepare("UPDATE users u JOIN teachers t ON t.user_id = u.id SET u.grade_level = t.grade_level_name, u.course_section = t.section_name WHERE t.id = ? AND u.role = 'professor'")->execute([$teacherId]);
}
