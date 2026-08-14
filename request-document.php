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

$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT dr.*, dt.name as type_name
        FROM document_requests dr
        LEFT JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.resident_id = :rid
        ORDER BY dr.requested_at DESC
        LIMIT 20
    ");
    $stmt->execute([':rid' => $resident['id'] ?? 0]);
    $requests = $stmt->fetchAll();
} catch (PDOException $e) { }

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_document'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Invalid request. Please try again.';
    } else {
        $docType = trim($_POST['doc_type'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if (empty($docType) || empty($purpose)) {
            $errorMsg = 'Please fill in all required fields.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO document_requests (resident_id, document_type_id, purpose, status, requested_at)
                    VALUES (:rid, :doc, :purp, 'Pending', NOW())
                ");
                $stmt->execute([
                    ':rid'  => $resident['id'] ?? null,
                    ':doc'  => $docType,
                    ':purp' => $purpose,
                ]);
                log_audit('create', 'document_request', $pdo->lastInsertId(), null, ['document_type' => $docType]);
                $successMsg = 'Document request submitted successfully!';
            } catch (PDOException $e) {
                $errorMsg = 'Failed to submit request. Please contact the barangay office.';
                error_log('Doc Request Error: ' . $e->getMessage());
            }
        }
    }
}

$statusBadge = [
    'Pending'  => 'badge-warning',
    'Approved' => 'badge-success',
    'Rejected' => 'badge-danger',
    'Ready'    => 'badge-info',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Document - Barangay Bidduang</title>
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
                <h1 class="page-title"><i class="fas fa-file-text"></i> Request Document</h1>
                <p class="page-subtitle">Submit a certificate or document request</p>
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

        <div class="card">
            <div class="card-header"><h2>New Request</h2></div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="form-group">
                        <label for="doc_type"><i class="fas fa-certificate"></i> Document Type</label>
                        <select name="doc_type" id="doc_type" required class="form-control">
                            <option value="">Select document type</option>
                            <option value="Barangay Clearance">Barangay Clearance</option>
                            <option value="Certificate of Residency">Certificate of Residency</option>
                            <option value="Certificate of Indigency">Certificate of Indigency</option>
                            <option value="Business Permit">Business Permit</option>
                            <option value="Barangay ID">Barangay ID</option>
                            <option value="First Time Jobseeker">First Time Jobseeker Certificate</option>
                            <option value="Certificate of Good Moral">Certificate of Good Moral</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="purpose"><i class="fas fa-info-circle"></i> Purpose</label>
                        <input type="text" name="purpose" id="purpose" required
                               placeholder="e.g., Employment, School requirement" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="remarks"><i class="fas fa-comment"></i> Remarks (optional)</label>
                        <textarea name="remarks" id="remarks" rows="3" class="form-control"
                                  placeholder="Additional details"></textarea>
                    </div>
                    <button type="submit" name="submit_document" class="btn btn-create" style="width:100%;">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>My Requests</h2></div>
            <div class="card-body">
                <?php if (empty($requests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No requests yet</h3>
                        <p>Submit a request using the form above.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr><th>Type</th><th>Purpose</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $r): ?>
                                    <tr>
                                        <td><?= esc($r['type_name'] ?? $r['document_type_id'] ?? 'Document') ?></td>
                                        <td><?= esc($r['purpose'] ?? '') ?></td>
                                        <td><span class="badge <?= $statusBadge[$r['status']] ?? 'badge-secondary' ?>"><?= esc($r['status']) ?></span></td>
                                        <td><?= esc(date('M d, Y', strtotime($r['requested_at'] ?? 'now'))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
