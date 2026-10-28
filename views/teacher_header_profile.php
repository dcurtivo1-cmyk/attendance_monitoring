<?php
$teacherHeaderName = professor_display_name($teacherAccount['name'] ?? '');
$teacherHeaderParts = preg_split('/\s+/u', $teacherHeaderName);
$teacherHeaderInitials = mb_strtoupper(mb_substr($teacherHeaderParts[0] ?? '', 0, 1) . mb_substr($teacherHeaderParts[1] ?? '', 0, 1));
?>
<a href="profile.php" class="profile-pill teacher-profile-link" aria-label="Edit your teacher profile">
    <div class="avatar-circle" aria-hidden="true"><?= htmlspecialchars($teacherHeaderInitials, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="profile-meta">
        <strong><?= htmlspecialchars($teacherHeaderName, ENT_QUOTES, 'UTF-8') ?></strong>
        <small>Teacher / Faculty</small>
        <span class="teacher-profile-email"><?= htmlspecialchars($teacherAccount['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</a>
