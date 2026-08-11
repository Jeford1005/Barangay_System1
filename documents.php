<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/sms/SmsTriggers.php';
require_role(['admin', 'staff']);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_request') {
            $resident_id = $_POST['resident_id'] ?? null;
            $document_type_id = $_POST['document_type_id'] ?? null;
            $or_number = trim($_POST['or_number'] ?? '');
            $purpose = trim($_POST['purpose'] ?? '');

            if (!$resident_id || !$document_type_id) {
                $error = 'Please select a resident and document type.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO document_requests (resident_id, document_type_id, or_number, purpose, status) VALUES (?, ?, ?, ?, 'Pending')");
                $stmt->execute([$resident_id, $document_type_id, $or_number ?: null, $purpose ?: null]);
                $newId = $pdo->lastInsertId();
                log_audit('create', 'document_request', $newId);
                $message = 'Document request submitted successfully.';
            }
        } elseif ($action === 'update_status') {
            $id = $_POST['id'] ?? null;
            $status = $_POST['status'] ?? '';
            $processed_by = $_SESSION['user_id'] ?? null;

            if ($id && $status) {
                $now = date('Y-m-d H:i:s');
                if ($status === 'Released') {
                    $stmt = $pdo->prepare("UPDATE document_requests SET status=?, processed_at=?, released_at=?, processed_by=? WHERE id=?");
                    $stmt->execute([$status, $now, $now, $processed_by, $id]);
                } elseif ($status === 'Processing') {
                    $stmt = $pdo->prepare("UPDATE document_requests SET status=?, processed_at=?, processed_by=? WHERE id=?");
                    $stmt->execute([$status, $now, $processed_by, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE document_requests SET status=? WHERE id=?");
                    $stmt->execute([$status, $id]);
                }
                log_audit('update', 'document_request', $id, ['status' => $status]);
                // Cloud SMS trigger: notify resident on Ready for Pickup / Rejected
                try { SmsTriggers::documentStatusUpdate((int)$id, $status); } catch (Throwable $e) { error_log('SMS doc trigger: ' . $e->getMessage()); }
                $message = 'Status updated successfully.';
            }
        } elseif ($action === 'add_or') {
            $resident_id = $_POST['resident_id'] ?? null;
            $document_type_id = $_POST['document_type_id'] ?? null;
            $or_number = trim($_POST['or_number'] ?? '');
            $amount = $_POST['amount'] ?? 0;
            $payment_method = $_POST['payment_method'] ?? 'Cash';
            $received_by = $_SESSION['user_id'] ?? null;

            if (!$resident_id || !$document_type_id || !$or_number) {
                $error = 'Resident, Document Type, and OR Number are required.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO official_receipts (or_number, resident_id, document_type_id, amount, payment_method, received_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$or_number, $resident_id, $document_type_id, $amount, $payment_method, $received_by]);
                $newId = $pdo->lastInsertId();
                log_audit('create', 'official_receipt', $newId);
                $message = 'Official Receipt created successfully.';
            }
        } elseif ($action === 'delete_request' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM document_requests WHERE id = ?");
            $stmt->execute([$id]);
            log_audit('delete', 'document_request', $id);
            $message = 'Document request deleted.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR dt.document_name LIKE ? OR dr.or_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter) {
    $where[] = "dr.status = ?";
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM document_requests dr LEFT JOIN residents r ON dr.resident_id = r.id LEFT JOIN document_types dt ON dr.document_type_id = dt.id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT dr.*, r.first_name, r.last_name, r.middle_name, dt.document_name, dt.fee,
        u.full_name as processed_by_name
    FROM document_requests dr
    LEFT JOIN residents r ON dr.resident_id = r.id
    LEFT JOIN document_types dt ON dr.document_type_id = dt.id
    LEFT JOIN users u ON dr.processed_by = u.id
    WHERE $whereSql
    ORDER BY dr.requested_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$requests = $stmt->fetchAll();

$residents = $pdo->query("SELECT id, first_name, last_name, middle_name FROM residents WHERE status='Active' ORDER BY last_name, first_name")->fetchAll();
$docTypes = $pdo->query("SELECT * FROM document_types WHERE is_active=1 ORDER BY document_name")->fetchAll();
$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . "/assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-file-text"></i> Document Processing</h1>
                <p>Manage document requests, official receipts, and processing</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="toast-alert toast-success" id="floatingAlert">
                <i class="fas fa-circle-check"></i>
                <span><?= esc($message) ?></span>
                <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="toast-alert toast-danger" id="floatingAlert">
                <i class="fas fa-exclamation"></i>
                <span><?= esc($error) ?></span>
                <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
            </div>
        <?php endif; ?>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--info);"><i class="fas fa-clock"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM document_requests WHERE status='Pending'")->fetchColumn()) ?></h3><p>Pending Requests</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--warning);"><i class="fas fa-spinner"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM document_requests WHERE status='Processing'")->fetchColumn()) ?></h3><p>Processing</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--accent);"><i class="fas fa-check"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM document_requests WHERE status='Ready for Pickup'")->fetchColumn()) ?></h3><p>Ready for Pickup</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--secondary);"><i class="fas fa-receipt"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM official_receipts")->fetchColumn()) ?></h3><p>Total Receipts</p></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Document Requests</h2>
                <div class="toolbar">
                    <form method="GET" class="search-box" style="flex:1;min-width:220px;">
                        <input type="hidden" name="status" value="<?= esc($statusFilter) ?>">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search requests..." value="<?= esc($search) ?>">
                    </form>
                    <select class="form-control" style="width:auto;min-width:150px;" onchange="window.location.href='?status='+this.value+'&search=<?= urlencode($search) ?>'">
                        <option value="">All Status</option>
                        <option value="Pending" <?= $statusFilter==='Pending'?'selected':'' ?>>Pending</option>
                        <option value="Processing" <?= $statusFilter==='Processing'?'selected':'' ?>>Processing</option>
                        <option value="Ready for Pickup" <?= $statusFilter==='Ready for Pickup'?'selected':'' ?>>Ready for Pickup</option>
                        <option value="Released" <?= $statusFilter==='Released'?'selected':'' ?>>Released</option>
                        <option value="Cancelled" <?= $statusFilter==='Cancelled'?'selected':'' ?>>Cancelled</option>
                    </select>
                    <button class="btn btn-primary" onclick="openModal('requestModal')"><i class="fas fa-plus"></i> New Request</button>
                    <button class="btn btn-success" onclick="openModal('orModal')"><i class="fas fa-receipt"></i> Issue OR</button>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Request #</th><th>Resident</th><th>Document</th><th>OR #</th><th>Fee</th><th>Status</th><th>Date</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr><td colspan="8"><div class="empty-state"><i class="fas fa-file-text"></i><h3>No requests found</h3><p>Create a new document request to get started.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><strong>DR-<?= str_pad($r['id'], 6, '0', STR_PAD_LEFT) ?></strong></td>
                                <td><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                <td><?= esc($r['document_name']) ?></td>
                                <td><?= esc($r['or_number'] ?: '-') ?></td>
                                <td><?= $r['fee'] ? '₱'.number_format($r['fee'],2) : 'Free' ?></td>
                                <td>
                                    <span class="badge badge-<?= $r['status']=='Released'||$r['status']=='Ready for Pickup'?'success':($r['status']=='Pending'?'info':($r['status']=='Processing'?'warning':($r['status']=='Cancelled'?'danger':'secondary'))) ?>"><?= esc($r['status']) ?></span>
                                </td>
                                <td><?= date('M d, Y', strtotime($r['requested_at'])) ?></td>
                                <td>
                                    <div class="actions">
                                        <?php if ($r['status'] !== 'Released' && $r['status'] !== 'Cancelled'): ?>
                                            <form method="POST" style="display:inline;" onchange="this.submit()">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                <select name="status" class="form-control" style="width:auto;padding:6px 8px;font-size:14px;">
                                                    <option value="">Update...</option>
                                                    <option value="Processing">Processing</option>
                                                    <option value="Ready for Pickup">Ready for Pickup</option>
                                                    <option value="Released">Released</option>
                                                    <option value="Cancelled">Cancelled</option>
                                                </select>
                                            </form>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-info" onclick="previewDocument(<?= $r['id'] ?>)"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteRequest(<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- New Request Modal -->
<div class="modal-backdrop" id="requestModal">
    <div class="modal" style="max-width:550px;">
        <div class="modal-header">
            <h3>New Document Request</h3>
            <button class="modal-close" onclick="closeModal('requestModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="add_request">
                <div class="form-group">
                    <label>Resident *</label>
                    <select name="resident_id" class="form-control" required>
                        <option value="">Select Resident</option>
                        <?php foreach ($residents as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= esc($r['first_name'] . ' ' . $r['last_name'] . ($r['middle_name'] ? ' '.substr($r['middle_name'],0,1).'.' : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Document Type *</label>
                    <select name="document_type_id" class="form-control" required>
                        <option value="">Select Document</option>
                        <?php foreach ($docTypes as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= esc($d['document_name']) ?> - ₱<?= number_format($d['fee'],2) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>OR Number (if paid)</label>
                    <input type="text" name="or_number" class="form-control">
                </div>
                <div class="form-group">
                    <label>Purpose</label>
                    <textarea name="purpose" class="form-control" rows="3" placeholder="e.g., Employment, School enrollment..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('requestModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Submit Request</button>
            </div>
        </form>
    </div>
</div>

<!-- Issue OR Modal -->
<div class="modal-backdrop" id="orModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>Issue Official Receipt</h3>
            <button class="modal-close" onclick="closeModal('orModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="add_or">
                <div class="form-group">
                    <label>Resident *</label>
                    <select name="resident_id" class="form-control" required>
                        <option value="">Select Resident</option>
                        <?php foreach ($residents as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Document Type *</label>
                    <select name="document_type_id" class="form-control" required>
                        <option value="">Select Document</option>
                        <?php foreach ($docTypes as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= esc($d['document_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>OR Number *</label>
                    <input type="text" name="or_number" class="form-control" required placeholder="OR-2026-0001">
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" step="0.01" name="amount" class="form-control" value="0.00">
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option>Cash</option><option>GCash</option><option>Bank Transfer</option><option>Others</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('orModal')">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-receipt"></i> Issue Receipt</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-header">
            <h3><i class="fas fa-warning" style="color:var(--danger);"></i> Confirm Delete</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:17px;">Delete this request? This cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
            <form id="deleteForm" method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="delete_request">
                <input type="hidden" name="id" id="deleteId">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function deleteRequest(id) { document.getElementById('deleteId').value = id; openModal('deleteModal'); }
function previewDocument(id) {
    const url = '<?= BASE_URL ?>/documents.php?preview=' + id;
    const win = window.open(url, '_blank', 'width=800,height=600');
    if (!win) alert('Please allow popups for this site to preview documents.');
}
document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});
</script>
<script src="assets/js/main.js"></script>
</body>
</html>

<?php if (isset($_GET['preview'])): ?>
<?php
$req = $pdo->prepare("SELECT dr.*, r.first_name, r.last_name, r.middle_name, r.address, dt.document_name, dt.description, dt.fee FROM document_requests dr LEFT JOIN residents r ON dr.resident_id = r.id LEFT JOIN document_types dt ON dr.document_type_id = dt.id WHERE dr.id = ?");
$req->execute([$_GET['preview']]);
$doc = $req->fetch();
if ($doc):
$barangay = 'Barangay Bidduang';
$municipality = 'Municipality of Talavera';
$province = 'Nueva Ecija';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= esc($doc['document_name']) ?> - Preview</title>
    <style>
        body { font-family: 'Times New Roman', Times, serif; font-size: 14px; line-height: 1.8; max-width: 800px; margin: 40px auto; padding: 0 20px; color: #000; }
        .header { text-align: center; border-bottom: 3px double #000; padding-bottom: 15px; margin-bottom: 25px; }
        .header h1 { font-size: 22px; margin: 0 0 5px; text-transform: uppercase; }
        .header p { margin: 3px 0; font-size: 14px; }
        .content { margin-bottom: 30px; }
        .content p { margin: 8px 0; text-align: justify; }
        .signature { margin-top: 50px; }
        .signature-block { display: inline-block; width: 45%; text-align: center; margin: 0 20px; vertical-align: top; }
        .signature-line { border-bottom: 1px solid #000; height: 60px; margin-bottom: 5px; }
        .no-print { text-align: center; margin-top: 30px; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= esc($barangay) ?></h1>
        <p><?= esc($municipality) ?>, <?= esc($province) ?></p>
        <p><strong>Office of the Barangay Captain</strong></p>
    </div>

    <div class="content">
        <h2 style="text-align:center;text-decoration:underline;"><?= esc($doc['document_name']) ?></h2>
        <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
        <p>
            This is to certify that <strong><?= esc($doc['first_name'] . ' ' . ($doc['middle_name'] ? substr($doc['middle_name'],0,1).'. ' : '') . $doc['last_name']) ?></strong>,
            of legal age, <?= esc($doc['gender'] ?? 'Filipino') ?> citizen, and a resident of
            <?= esc($doc['address'] ?: 'Barangay Bidduang') ?>, <?= esc($municipality) ?>, <?= esc($province) ?>
            is hereby issued this <strong><?= esc($doc['document_name']) ?></strong> upon his/her request.
        </p>
        <p>
            <?= esc($doc['description'] ?: 'This document may be used for legal and official purposes as required.') ?>
        </p>
        <p>This certification is being issued this <strong><?= date('d') ?></strong> day of <strong><?= date('F Y') ?></strong> for whatever legal purpose it may serve.</p>
    </div>

    <div class="signature">
        <div class="signature-block">
            <div class="signature-line"></div>
            <p><strong><?= esc($doc['first_name'] . ' ' . $doc['last_name']) ?></strong><br>Resident</p>
        </div>
        <div class="signature-block">
            <div class="signature-line"></div>
            <p><strong>HON. [BARANGAY CAPTAIN NAME]</strong><br>Barangay Captain</p>
        </div>
    </div>

    <div class="no-print">
        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Document</button>
        <button class="btn btn-outline" onclick="window.close()">Close</button>
    </div>
</body>
</html>
<?php endif; ?>
<?php endif; ?>
