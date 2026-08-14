<?php
require_once __DIR__ . '/config.php';
require_auth();

$user = current_user();
$csrf = generate_csrf_token();

$resident = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM residents WHERE id = :rid LIMIT 1");
    $stmt->execute([':rid' => $user['resident_id'] ?? 0]);
    $resident = $stmt->fetch();
} catch (PDOException $e) { }

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Invalid request. Please try again.';
    } else {
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        try {
            if ($resident) {
                $stmt = $pdo->prepare("UPDATE residents SET phone = :p, email = :e, address = :a WHERE id = :id");
                $stmt->execute([':p' => $phone, ':e' => $email, ':a' => $address, ':id' => $resident['id']]);
            }
            $stmt = $pdo->prepare("UPDATE users SET email = :e, phone = :p WHERE id = :id");
            $stmt->execute([':e' => $email, ':p' => $phone, ':id' => $user['id']]);
            log_audit('update', 'resident_profile', $resident['id'] ?? null, null, ['field' => 'contact']);
            $successMsg = 'Profile updated successfully!';
            $resident['phone'] = $phone;
            $resident['email'] = $email;
            $resident['address'] = $address;
        } catch (PDOException $e) {
            $errorMsg = 'Failed to update profile. Please try again.';
            error_log('Profile Update Error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Barangay Bidduang</title>
    <link rel="stylesheet" href="assets/css/design-system.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/resident-sidebar.php'; ?>

    <main class="main-content">
        <?php $variant = 'resident'; include __DIR__ . '/views/mobile-topbar.php'; ?>
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-id-card"></i> My Profile</h1>
                <p class="page-subtitle">View and update your resident information</p>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="toast-alert toast-success" id="floatingAlert">
                <i class="fas fa-circle-check"></i><span><?= esc($successMsg) ?></span>
                <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="toast-alert toast-danger" id="floatingAlert">
                <i class="fas fa-exclamation"></i><span><?= esc($errorMsg) ?></span>
                <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!$resident): ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <h3>No resident record linked</h3>
                    <p>Your account is not yet linked to a resident profile. Please visit the barangay office.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header"><h2>Personal Information</h2></div>
                <div class="card-body">
                    <div class="profile-summary" style="display:flex;gap:16px;align-items:center;margin-bottom:18px;">
                        <div class="avatar" style="width:56px;height:56px;font-size:20px;">
                            <?= esc(strtoupper(substr($resident['first_name'] ?? 'R', 0, 1))) ?>
                        </div>
                        <div>
                            <h3 style="margin:0;"><?= esc(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')) ?></h3>
                            <p class="muted" style="margin:2px 0 0;"><?= esc($resident['resident_code'] ?? '') ?></p>
                        </div>
                    </div>
                    <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:18px;">
                        <div><span class="muted" style="font-size:12px;">Birth Date</span><div><?= esc($resident['birth_date'] ?? '—') ?></div></div>
                        <div><span class="muted" style="font-size:12px;">Gender</span><div><?= esc($resident['gender'] ?? '—') ?></div></div>
                        <div><span class="muted" style="font-size:12px;">Civil Status</span><div><?= esc($resident['civil_status'] ?? '—') ?></div></div>
                        <div><span class="muted" style="font-size:12px;">Household</span><div><?= esc($resident['household_id'] ?? '—') ?></div></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Update Contact Details</h2></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <div class="grid-2">
                            <div class="form-group">
                                <label for="phone"><i class="fas fa-phone"></i> Phone</label>
                                <input type="tel" name="phone" id="phone" class="form-control"
                                       value="<?= esc($resident['phone'] ?? '') ?>" placeholder="09XXXXXXXXX">
                            </div>
                            <div class="form-group">
                                <label for="email"><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" name="email" id="email" class="form-control"
                                       value="<?= esc($resident['email'] ?? $user['email'] ?? '') ?>" placeholder="you@example.com">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="address"><i class="fas fa-map-marker-alt"></i> Address</label>
                            <input type="text" name="address" id="address" class="form-control"
                                   value="<?= esc($resident['address'] ?? '') ?>" placeholder="Full address">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-update" style="width:100%;">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
