<?php
/** One entry per section, including empty catalog sections and legacy enrollments. */
function subject_section_counts(PDO $pdo, int $subjectId, ?int $gradeId): array
{
    $counts = [];
    if ($gradeId) {
        $catalog = $pdo->prepare('SELECT section_name FROM school_sections WHERE grade_level_id = ? ORDER BY section_name');
        $catalog->execute([$gradeId]);
        foreach ($catalog->fetchAll(PDO::FETCH_COLUMN) as $section) $counts[$section] = 0;
    }
    $enrolled = $pdo->prepare("SELECT COALESCE(u.course_section, '') AS section_name, COUNT(*) AS student_count
        FROM student_subjects ss JOIN users u ON u.id = ss.student_id
        WHERE ss.subject_id = ? AND u.role = 'student'
        GROUP BY COALESCE(u.course_section, '')");
    $enrolled->execute([$subjectId]);
    foreach ($enrolled->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['section_name']] = (int)$row['student_count'];
    if (!$counts) $counts[''] = 0;
    uksort($counts, 'strnatcasecmp');
    return $counts;
}
