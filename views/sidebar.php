<?php
/**
 * views/sidebar.php — Shared sidebar shell for all admin pages.
 * Active link is derived from the current script name, so every page
 * includes this single partial instead of duplicating the markup.
 */
$current = basename($_SERVER['PHP_SELF'] ?? '');
$links = [
    'dashboard.php'        => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
    'residents.php'        => ['icon' => 'fa-users',          'label' => 'Residents'],
    'households.php'       => ['icon' => 'fa-house',          'label' => 'Households'],
    'officials.php'        => ['icon' => 'fa-user-tie',       'label' => 'Officials'],
    'documents.php'        => ['icon' => 'fa-file-text',      'label' => 'Documents'],
    'blotter.php'          => ['icon' => 'fa-scale-balanced', 'label' => 'Blotter'],
    'welfare.php'          => ['icon' => 'fa-hand-holding-heart', 'label' => 'Welfare'],
    'health.php'           => ['icon' => 'fa-heartbeat',      'label' => 'Health'],
    'reports.php'          => ['icon' => 'fa-chart-bar',      'label' => 'Reports'],
    'accounts.php'         => ['icon' => 'fa-user-gear',      'label' => 'Accounts'],
    'admin-audit.php'      => ['icon' => 'fa-search-plus',    'label' => 'Audit Logs'],
    'broadcast.php'        => ['icon' => 'fa-tower-broadcast','label' => 'Broadcast Manager'],
];
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="assets/img/Brgy_Bidduang.png" alt="Barangay Bidduang Seal">
        <div class="brand-title">Barangay Bidduang<span class="brand-sub">Management Portal</span></div>
    </div>
    <nav>
        <ul class="sidebar-nav">
            <?php foreach ($links as $file => $l): ?>
                <li>
                    <a href="<?= $file ?>" class="<?= $current === $file ? 'active' : '' ?>">
                        <i class="fas <?= $l['icon'] ?>"></i> <?= $l['label'] ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>
</aside>
