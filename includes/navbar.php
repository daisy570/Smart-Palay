<?php $user = currentUser(); ?>
<header class="sp-navbar">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle">
        <i class="bi bi-list"></i>
    </button>
    <h4 class="mb-0 sp-page-title"><?= $pageTitle ?? 'Dashboard' ?></h4>
    <div class="ms-auto d-flex align-items-center gap-3">
        <span class="sp-role-badge role-<?= $user['role'] ?>">
            <i class="bi bi-person-fill"></i> <?= ucfirst($user['role']) ?>
        </span>
        <div class="sp-user">
            <div class="sp-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
            <span class="d-none d-md-inline"><?= sanitize($user['name']) ?></span>
        </div>
    </div>
</header>