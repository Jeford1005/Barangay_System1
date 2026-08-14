<?php
/**
 * views/mobile-topbar.php — Slim topbar shown only on tablet/mobile (<=1023px).
 * Hamburger opens the off-canvas sidebar drawer. Keeps the seal + wordmark
 * visible (centered) so brand presence is never fully lost.
 * $variant: 'admin' (bell) or 'resident' (avatar) for the right-side icon.
 */
$variant = $variant ?? 'admin';
$user = current_user();
?>
<div class="mobile-topbar">
    <button class="topbar-hamburger" id="hamburgerBtn" aria-label="Open menu"><i class="fas fa-bars"></i></button>
    <div class="topbar-brand">
        <img src="assets/img/Brgy_Bidduang.png" alt="Seal">
        <div>Bidduang<small><?= $variant === 'resident' ? 'Resident Portal' : 'Management Portal' ?></small></div>
    </div>
    <div class="topbar-right">
        <?php if ($variant === 'resident'): ?>
            <span class="topbar-icon"><i class="fas fa-user"></i></span>
        <?php else: ?>
            <a href="broadcast.php" class="topbar-icon" aria-label="Notifications"><i class="fas fa-bell"></i></a>
        <?php endif; ?>
    </div>
</div>
