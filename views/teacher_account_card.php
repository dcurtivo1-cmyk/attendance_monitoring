<?php // The signed-in account is supplied by the portal page. ?>
<div class="prof-card">
    <small>Teacher Account</small>
    <strong><?= htmlspecialchars(professor_display_name($teacherAccount['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
    <div class="teacher-account-id">ID: <?= htmlspecialchars($teacherAccount['identifier'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
    <div class="teacher-account-email"><i class="fa-regular fa-envelope" aria-hidden="true"></i> <?= htmlspecialchars($teacherAccount['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
</div>
