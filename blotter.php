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

        if ($action === 'add' || $action === 'edit') {
            $id = $_POST['id'] ?? null;
            $case_number = trim($_POST['case_number'] ?? '');
            $case_type = $_POST['case_type'] ?? 'Dispute';
            $status = $_POST['status'] ?? 'Open';
            $filing_date = $_POST['filing_date'] ?? '';
            $incident_date = $_POST['incident_date'] ?? '';
            $incident_time = $_POST['incident_time'] ?? null;
            $incident_location = trim($_POST['incident_location'] ?? '');
            $involved_parties = trim($_POST['involved_parties'] ?? '');
            $narrative = trim($_POST['narrative'] ?? '');
            $complainant_id = !empty($_POST['complainant_id']) ? $_POST['complainant_id'] : null;
            $respondent_id = !empty($_POST['respondent_id']) ? $_POST['respondent_id'] : null;
            $assigned_official_id = !empty($_POST['assigned_official_id']) ? $_POST['assigned_official_id'] : null;
            $resolution = trim($_POST['resolution'] ?? '');
            $closed_at = ($status === 'Closed' && !empty($_POST['closed_at'])) ? $_POST['closed_at'] : null;
            $hearing_date = trim($_POST['hearing_date'] ?? '');
            $hearing_time = trim($_POST['hearing_time'] ?? '');
            $lupon_desk = trim($_POST['lupon_desk'] ?? 'Lupon Desk');

            if (!$case_number || !$filing_date || !$incident_date || !$incident_location || !$involved_parties || !$narrative) {
                $error = 'Case Number, Filing Date, Incident Date, Location, Involved Parties, and Narrative are required.';
            } else {
                $oldValues = null;
                if ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("SELECT * FROM blotter_cases WHERE id = ?");
                    $stmt->execute([$id]);
                    $oldValues = $stmt->fetch();
                }
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO blotter_cases (case_number, case_type, status, filing_date, incident_date, incident_time, incident_location, involved_parties, narrative, complainant_id, respondent_id, assigned_official_id, resolution, closed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$case_number, $case_type, $status, $filing_date, $incident_date, $incident_time ?: null, $incident_location, $involved_parties, $narrative, $complainant_id, $respondent_id, $assigned_official_id, $resolution ?: null, $closed_at ?: null]);
                    $newId = $pdo->lastInsertId();
                    log_audit('create', 'blotter_case', $newId);
                    $message = 'Blotter case filed successfully.';
                } elseif ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("UPDATE blotter_cases SET case_number=?, case_type=?, status=?, filing_date=?, incident_date=?, incident_time=?, incident_location=?, involved_parties=?, narrative=?, complainant_id=?, respondent_id=?, assigned_official_id=?, resolution=?, closed_at=? WHERE id=?");
                    $stmt->execute([$case_number, $case_type, $status, $filing_date, $incident_date, $incident_time ?: null, $incident_location, $involved_parties, $narrative, $complainant_id, $respondent_id, $assigned_official_id, $resolution ?: null, $closed_at ?: null, $id]);
                    if ($hearing_date) {
                        $pdo->prepare("UPDATE blotter_cases SET hearing_date=?, hearing_time=?, lupon_desk=? WHERE id=?")
                            ->execute([$hearing_date, $hearing_time ?: null, $lupon_desk, $id]);
                        try { SmsTriggers::hearingScheduled((int)$id, $hearing_date, $hearing_time ?: 'TBD', $lupon_desk); } catch (Throwable $e) { error_log('SMS hearing trigger: ' . $e->getMessage()); }
                    }
                    log_audit('update', 'blotter_case', $id, $oldValues);
                    $message = 'Blotter case updated successfully.';
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM blotter_cases WHERE id = ?");
            $stmt->execute([$id]);
            log_audit('delete', 'blotter_case', $id);
            $message = 'Blotter case deleted successfully.';
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
    $where[] = "(bc.case_number LIKE ? OR bc.incident_location LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter) {
    $where[] = "bc.status = ?";
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM blotter_cases bc LEFT JOIN residents r ON bc.complainant_id = r.id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT bc.*, 
        CONCAT(r1.first_name, ' ', r1.last_name) as complainant_name,
        CONCAT(r2.first_name, ' ', r2.last_name) as respondent_name,
        CONCAT(o.first_name, ' ', o.last_name) as official_name
    FROM blotter_cases bc
    LEFT JOIN residents r1 ON bc.complainant_id = r1.id
    LEFT JOIN residents r2 ON bc.respondent_id = r2.id
    LEFT JOIN officials o ON bc.assigned_official_id = o.id
    WHERE $whereSql
    ORDER BY bc.filing_date DESC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$cases = $stmt->fetchAll();

$residents = $pdo->query("SELECT id, first_name, last_name FROM residents WHERE status='Active' ORDER BY last_name, first_name")->fetchAll();
$officials = $pdo->query("SELECT id, first_name, last_name FROM officials WHERE is_active=1 ORDER BY last_name, first_name")->fetchAll();

$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blotter - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-scale-balanced"></i> Blotter & Mediation</h1>
                <p>Incident tracking under Katarungang Pambarangay (Lupong Tagapamayapa)</p>
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

        <!-- Katarungang Stages Legend -->
        <div class="card" style="background:linear-gradient(135deg,#f0f8ff,#fff);border-left:5px solid var(--secondary);">
            <h2 style="font-size:18px;margin:0 0 12px;"><i class="fas fa-info"></i> Katarungang Pambarangay Stages</h2>
            <div style="display:flex;gap:15px;flex-wrap:wrap;">
                <span class="badge badge-info">1. Open</span>
                <span class="badge badge-warning">2. Under Mediation</span>
                <span class="badge badge-success">3. Conciliated</span>
                <span class="badge badge-secondary">4. Arbitrated</span>
                <span class="badge badge-danger">5. Escalated</span>
                <span class="badge badge-success">6. Closed</span>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--info);"><i class="fas fa-folder-open"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM blotter_cases WHERE status='Open'")->fetchColumn()) ?></h3><p>Open Cases</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--warning);"><i class="fas fa-handshake"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM blotter_cases WHERE status='Under Mediation'")->fetchColumn()) ?></h3><p>Under Mediation</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--accent);"><i class="fas fa-circle-check"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM blotter_cases WHERE status IN ('Conciliated','Closed')")->fetchColumn()) ?></h3><p>Resolved</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--danger);"><i class="fas fa-exclamation"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM blotter_cases WHERE status='Escalated'")->fetchColumn()) ?></h3><p>Escalated</p></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Case Records</h2>
                <div class="toolbar">
                    <form method="GET" class="search-box" style="flex:1;min-width:220px;">
                        <input type="hidden" name="status" value="<?= esc($statusFilter) ?>">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search cases..." value="<?= esc($search) ?>">
                    </form>
                    <select class="form-control" style="width:auto;min-width:150px;" onchange="window.location.href='?status='+this.value+'&search=<?= urlencode($search) ?>'">
                        <option value="">All Status</option>
                        <option value="Open" <?= $statusFilter==='Open'?'selected':'' ?>>Open</option>
                        <option value="Under Mediation" <?= $statusFilter==='Under Mediation'?'selected':'' ?>>Under Mediation</option>
                        <option value="Conciliated" <?= $statusFilter==='Conciliated'?'selected':'' ?>>Conciliated</option>
                        <option value="Arbitrated" <?= $statusFilter==='Arbitrated'?'selected':'' ?>>Arbitrated</option>
                        <option value="Escalated" <?= $statusFilter==='Escalated'?'selected':'' ?>>Escalated</option>
                        <option value="Closed" <?= $statusFilter==='Closed'?'selected':'' ?>>Closed</option>
                    </select>
                    <button class="btn btn-primary" onclick="openModal('caseModal')"><i class="fas fa-plus"></i> File Case</button>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Case #</th><th>Type</th><th>Complainant</th><th>Respondent</th><th>Incident Date</th><th>Location</th><th>Status</th><th>Assigned To</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cases)): ?>
                            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-scale-balanced"></i><h3>No cases found</h3><p>File a new case to begin tracking.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($cases as $c): ?>
                            <tr>
                                <td><strong><?= esc($c['case_number']) ?></strong></td>
                                <td><?= esc($c['case_type']) ?></td>
                                <td><?= esc($c['complainant_name'] ?? '-') ?></td>
                                <td><?= esc($c['respondent_name'] ?? '-') ?></td>
                                <td><?= date('M d, Y', strtotime($c['incident_date'])) ?></td>
                                <td><?= esc($c['incident_location']) ?></td>
                                <td><span class="badge badge-<?= $c['status']=='Open'?'info':($c['status']=='Under Mediation'?'warning':($c['status']=='Closed'?'success':($c['status']=='Escalated'?'danger':'secondary'))) ?>"><?= esc($c['status']) ?></span></td>
                                <td><?= esc($c['official_name'] ?? '-') ?></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-sm btn-info" onclick="editCase(<?= $c['id'] ?>)"><i class="fas fa-pen-to-square"></i></button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteCase(<?= $c['id'] ?>, '<?= esc(addslashes($c['case_number'])) ?>')"><i class="fas fa-trash"></i></button>
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
</div>

<div class="modal-backdrop" id="caseModal">
    <div class="modal" style="max-width:750px;">
        <div class="modal-header">
            <h3 id="modalTitle">File New Case</h3>
            <button class="modal-close" onclick="closeModal('caseModal')">&times;</button>
        </div>
        <form id="caseForm" method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="caseId">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group"><label for="caseNumber">Case Number *</label> <input type="text" name="case_number" id="caseNumber" class="form-control" required></div>
                    <div class="form-group">
                        <label for="caseType">Case Type *</label> <select name="case_type" id="caseType" class="form-control" required>
                            <option>Dispute</option><option>Complaint</option><option>Incident</option><option>Disturbance</option><option>Theft</option><option>Others</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label> <select name="status" id="status" class="form-control">
                            <option>Open</option><option>Under Mediation</option><option>Conciliated</option><option>Arbitrated</option><option>Escalated</option><option>Closed</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group"><label for="filingDate">Filing Date *</label> <input type="date" name="filing_date" id="filingDate" class="form-control" required></div>
                    <div class="form-group"><label for="incidentDate">Incident Date *</label> <input type="date" name="incident_date" id="incidentDate" class="form-control" required></div>
                    <div class="form-group"><label for="incidentTime">Incident Time</label> <input type="time" name="incident_time" id="incidentTime" class="form-control"></div>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group"><label for="hearingDate">Hearing Date</label> <input type="date" name="hearing_date" id="hearingDate" class="form-control"></div>
                    <div class="form-group"><label for="hearingTime">Hearing Time</label> <input type="time" name="hearing_time" id="hearingTime" class="form-control"></div>
                    <div class="form-group"><label for="luponDesk">Lupon Desk</label> <input type="text" name="lupon_desk" id="luponDesk" class="form-control" value="Lupon Desk"></div>
                </div>
                <div class="form-group"><label for="incidentLocation">Incident Location *</label> <input type="text" name="incident_location" id="incidentLocation" class="form-control" required></div>
                <div class="form-group"><label for="involvedParties">Involved Parties *</label> <textarea name="involved_parties" id="involvedParties" class="form-control" rows="2" required placeholder="Names and roles of involved parties"></textarea></div>
                <div class="form-group"><label for="narrative">Narrative *</label> <textarea name="narrative" id="narrative" class="form-control" rows="4" required placeholder="Detailed account of the incident"></textarea></div>
                <div class="form-group"><label for="resolution">Resolution</label> <textarea name="resolution" id="resolution" class="form-control" rows="3" placeholder="Resolution details if available"></textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label for="complainantId">Complainant</label> <select name="complainant_id" id="complainantId" class="form-control">
                            <option value="">Select Complainant</option>
                            <?php foreach ($residents as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="respondentId">Respondent</label> <select name="respondent_id" id="respondentId" class="form-control">
                            <option value="">Select Respondent</option>
                            <?php foreach ($residents as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="assignedOfficialId">Assigned Official</label> <select name="assigned_official_id" id="assignedOfficialId" class="form-control">
                            <option value="">Select Official</option>
                            <?php foreach ($officials as $o): ?>
                                <option value="<?= $o['id'] ?>"><?= esc($o['first_name'] . ' ' . $o['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label for="closedAt">Closed At (if closed)</label> <input type="date" name="closed_at" id="closedAt" class="form-control"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('caseModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Case</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="deleteModal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-header">
            <h3><i class="fas fa-warning" style="color:var(--danger);"></i> Confirm Delete</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:17px;">Delete case <strong id="deleteName"></strong>? This cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
            <form id="deleteForm" method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function editCase(id) {
    fetch('<?= BASE_URL ?>/api/case.php?id=' + id).then(r => r.json()).then(data => {
        if (!data.id) return alert('Not found');
        document.getElementById('formAction').value = 'edit';
        document.getElementById('caseId').value = data.id;
        document.getElementById('modalTitle').textContent = 'Edit Blotter Case';
        document.getElementById('caseNumber').value = data.case_number || '';
        document.getElementById('caseType').value = data.case_type || 'Dispute';
        document.getElementById('status').value = data.status || 'Open';
        document.getElementById('filingDate').value = data.filing_date || '';
        document.getElementById('incidentDate').value = data.incident_date || '';
        document.getElementById('incidentTime').value = data.incident_time || '';
        document.getElementById('incidentLocation').value = data.incident_location || '';
        document.getElementById('involvedParties').value = data.involved_parties || '';
        document.getElementById('narrative').value = data.narrative || '';
        document.getElementById('resolution').value = data.resolution || '';
        document.getElementById('complainantId').value = data.complainant_id || '';
        document.getElementById('respondentId').value = data.respondent_id || '';
        document.getElementById('assignedOfficialId').value = data.assigned_official_id || '';
        document.getElementById('closedAt').value = data.closed_at || '';
        openModal('caseModal');
    });
}
function deleteCase(id, name) { document.getElementById('deleteId').value = id; document.getElementById('deleteName').textContent = name; openModal('deleteModal'); }
document.querySelectorAll('.modal-backdrop').forEach(el => { el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); }); });
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
