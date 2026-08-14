<?php
/**
 * views/resident-sidebar.php — Minimal resident self-service navigation.
 * Replaces the admin sidebar on the resident portal so residents only see
 * their own pages (no Officials/Accounts/Audit/Broadcast links).
 */
$current = basename($_SERVER['PHP_SELF'] ?? '');
$links = [
    'resident-dashboard.php' => ['icon' => 'fa-tachometer-alt', 'label' => 'My Dashboard'],
    'request-document.php'   => ['icon' => 'fa-file-text',      'label' => 'Request Document'],
    'resident-profile.php'   => ['icon' => 'fa-id-card',        'label' => 'My Profile'],
];
$user = current_user();
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="assets/img/Brgy_Bidduang.png" alt="Barangay Bidduang Seal">
        <div class="brand-title">Barangay Bidduang<span class="brand-sub">Resident Portal</span></div>
    </div>
    <nav>
        <ul class="sidebar-nav">
            <?php foreach ($links as $file => $l): ?>
                <li>
                    <a href="<?= $file ?>" class="<?= $current === $file ? 'active' : '' ?>">
                        <i class="fas <?= $l['icon'] ?>"></i> <span><?= $l['label'] ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Sign out</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-account">
        <i class="fas fa-user-circle"></i>
        <div class="who">
            <strong><?= esc($user['full_name'] ?? $user['username'] ?? 'Resident') ?></strong>
            <span>Resident</span>
        </div>
    </div>
</aside>
<div class="drawer-backdrop" id="drawerBackdrop"></div>
