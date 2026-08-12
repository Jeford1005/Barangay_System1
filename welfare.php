<?php
require_once __DIR__ . '/config.php';
require_role(['admin', 'staff', 'official']);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_program' || $action === 'edit_program') {
            $id = $_POST['id'] ?? null;
            $program_name = trim($_POST['program_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $start_date = $_POST['start_date'] ?? null;
            $end_date = $_POST['end_date'] ?? null;
            $beneficiary_type = trim($_POST['beneficiary_type'] ?? '');
            $status = $_POST['status'] ?? 'Upcoming';

            if (!$program_name) {
                $error = 'Program name is required.';
            } else {
                $oldValues = null;
                if ($action === 'edit_program' && $id) {
                    $stmt = $pdo->prepare("SELECT * FROM welfare_programs WHERE id = ?");
                    $stmt->execute([$id]);
                    $oldValues = $stmt->fetch();
                }
                if ($action === 'add_program') {
                    $stmt = $pdo->prepare("INSERT INTO welfare_programs (program_name, description, start_date, end_date, beneficiary_type, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$program_name, $description ?: null, $start_date ?: null, $end_date ?: null, $beneficiary_type ?: null, $status]);
                    $newId = $pdo->lastInsertId();
                    log_audit('create', 'welfare_program', $newId);
                    $message = 'Program added successfully.';
                } elseif ($action === 'edit_program' && $id) {
                    $stmt = $pdo->prepare("UPDATE welfare_programs SET program_name=?, description=?, start_date=?, end_date=?, beneficiary_type=?, status=? WHERE id=?");
                    $stmt->execute([$program_name, $description ?: null, $start_date ?: null, $end_date ?: null, $beneficiary_type ?: null, $status, $id]);
                    log_audit('update', 'welfare_program', $id, $oldValues);
                    $message = 'Program updated successfully.';
                }
            }
        } elseif ($action === 'delete_program' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM welfare_programs WHERE id = ?");
            $stmt->execute([$id]);
            log_audit('delete', 'welfare_program', $id);
            $message = 'Program deleted successfully.';
        } elseif ($action === 'add_beneficiary') {
            $program_id = $_POST['program_id'] ?? null;
            $resident_id = $_POST['resident_id'] ?? null;
            $enrollment_date = $_POST['enrollment_date'] ?? '';
            $notes = trim($_POST['notes'] ?? '');

            if (!$program_id || !$resident_id || !$enrollment_date) {
                $error = 'Program, Resident, and Enrollment Date are required.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO welfare_beneficiaries (program_id, resident_id, enrollment_date, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$program_id, $resident_id, $enrollment_date, $notes ?: null]);
                $newId = $pdo->lastInsertId();
                log_audit('create', 'welfare_beneficiary', $newId);
                $message = 'Beneficiary enrolled successfully.';
            }
        } elseif ($action === 'delete_beneficiary' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM welfare_beneficiaries WHERE id = ?");
            $stmt->execute([$id]);
            log_audit('delete', 'welfare_beneficiary', $id);
            $message = 'Beneficiary removed.';
        }
    }
}

$currentUser = current_user();
$programs = $pdo->query("SELECT * FROM welfare_programs ORDER BY start_date DESC, created_at DESC")->fetchAll();
$residents = $pdo->query("SELECT id, first_name, last_name FROM residents WHERE status='Active' ORDER BY last_name, first_name")->fetchAll();

// Get beneficiaries with program and resident info
$beneficiaries = $pdo->query("
    SELECT wb.*, wp.program_name, CONCAT(r.first_name, ' ', r.last_name) as resident_name
    FROM welfare_beneficiaries wb
    LEFT JOIN welfare_programs wp ON wb.program_id = wp.id
    LEFT JOIN residents r ON wb.resident_id = r.id
    ORDER BY wb.enrollment_date DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welfare - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-hand-holding-heart"></i> Welfare & Assistance</h1>
                <p>Manage assistance programs and beneficiary enrollment</p>
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
                <div class="stat-icon" style="background:var(--secondary);"><i class="fas fa-project-diagram"></i></div>
                <div class="stat-info"><h3><?= number_format(count($programs)) ?></h3><p>Total Programs</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--accent);"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3><?= number_format(count($beneficiaries)) ?></h3><p>Total Beneficiaries</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--warning);"><i class="fas fa-spinner"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM welfare_programs WHERE status='Ongoing'")->fetchColumn()) ?></h3><p>Ongoing Programs</p></div>
            </div>
        </div>

        <!-- Programs -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-project-diagram"></i> Assistance Programs</h2>
                <button class="btn btn-primary" onclick="openModal('programModal')"><i class="fas fa-plus"></i> Add Program</button>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Program Name</th><th>Beneficiary Type</th><th>Start Date</th><th>End Date</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($programs)): ?>
                            <tr><td colspan="6"><div class="empty-state"><i class="fas fa-project-diagram"></i><h3>No programs yet</h3><p>Add your first assistance program.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($programs as $p): ?>
                            <tr>
                                <td><strong><?= esc($p['program_name']) ?></strong><br><small class="text-muted"><?= esc($p['description'] ? substr($p['description'],0,80).'...' : '') ?></small></td>
                                <td><?= esc($p['beneficiary_type'] ?: '-') ?></td>
                                <td><?= $p['start_date'] ? date('M d, Y', strtotime($p['start_date'])) : '-' ?></td>
                                <td><?= $p['end_date'] ? date('M d, Y', strtotime($p['end_date'])) : '-' ?></td>
                                <td><span class="badge badge-<?= $p['status']=='Ongoing'?'success':($p['status']=='Completed'?'secondary':'warning') ?>"><?= esc($p['status']) ?></span></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-sm btn-info" onclick="editProgram(<?= $p['id'] ?>)"><i class="fas fa-pen-to-square"></i></button>
                                        <form method="POST" style="display:inline;" data-confirm-title="Delete Program" data-confirm-msg="Delete program '<?= esc(addslashes($p['program_name'])) ?>'? This cannot be undone." onsubmit="return handleDelete(this);">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete_program">
                                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Beneficiaries -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-users"></i> Beneficiaries</h2>
                <button class="btn btn-primary" onclick="openModal('beneficiaryModal')"><i class="fas fa-plus"></i> Enroll Resident</button>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Resident</th><th>Program</th><th>Enrollment Date</th><th>Status</th><th>Notes</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($beneficiaries)): ?>
                            <tr><td colspan="6"><div class="empty-state"><i class="fas fa-users"></i><h3>No beneficiaries enrolled</h3><p>Enroll residents in assistance programs.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($beneficiaries as $b): ?>
                            <tr>
                                <td><strong><?= esc($b['resident_name'] ?? '-') ?></strong></td>
                                <td><?= esc($b['program_name'] ?? '-') ?></td>
                                <td><?= date('M d, Y', strtotime($b['enrollment_date'])) ?></td>
                                <td><span class="badge badge-<?= $b['status']=='Enrolled'?'success':($b['status']=='Completed'?'info':'secondary') ?>"><?= esc($b['status']) ?></span></td>
                                <td><?= esc($b['notes'] ?: '-') ?></td>
                                <td>
                                    <form method="POST" style="display:inline;" data-confirm-title="Remove Beneficiary" data-confirm-msg="Remove beneficiary '<?= esc(addslashes(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?>'? This cannot be undone." onsubmit="return handleDelete(this);">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="action" value="delete_beneficiary">
                                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
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

<!-- Program Modal -->
<div class="modal-backdrop" id="programModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle">Add Program</h3>
            <button class="modal-close" onclick="closeModal('programModal')">&times;</button>
        </div>
        <form id="programForm" method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" id="formAction" value="add_program">
                <input type="hidden" name="id" id="programId">
                <div class="form-group"><label for="programName">Program Name *</label> <input type="text" name="program_name" id="programName" class="form-control" required></div>
                <div class="form-group"><label for="programDesc">Description</label> <textarea name="description" id="programDesc" class="form-control" rows="3"></textarea></div>
                <div class="form-group"><label for="beneficiaryType">Beneficiary Type</label> <input type="text" name="beneficiary_type" id="beneficiaryType" class="form-control" placeholder="e.g., Senior Citizens, PWD, Indigent"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group"><label for="startDate">Start Date</label> <input type="date" name="start_date" id="startDate" class="form-control"></div>
                    <div class="form-group"><label for="endDate">End Date</label> <input type="date" name="end_date" id="endDate" class="form-control"></div>
                </div>
                <div class="form-group">
                    <label for="programStatus">Status</label> <select name="status" id="programStatus" class="form-control">
                        <option>Upcoming</option><option>Ongoing</option><option>Completed</option><option>Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('programModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Program</button>
            </div>
        </form>
    </div>
</div>

<!-- Beneficiary Modal -->
<div class="modal-backdrop" id="beneficiaryModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>Enroll Resident</h3>
            <button class="modal-close" onclick="closeModal('beneficiaryModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="add_beneficiary">
                <div class="form-group">
                    <label>Program *</label>
                    <select name="program_id" class="form-control" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= esc($p['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Resident *</label>
                    <select name="resident_id" class="form-control" required>
                        <option value="">Select Resident</option>
                        <?php foreach ($residents as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Enrollment Date *</label><input type="date" name="enrollment_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('beneficiaryModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enroll</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function editProgram(id) {
    fetch('<?= BASE_URL ?>/api/program.php?id=' + id).then(r => r.json()).then(data => {
        if (!data.id) return alert('Not found');
        document.getElementById('formAction').value = 'edit_program';
        document.getElementById('programId').value = data.id;
        document.getElementById('modalTitle').textContent = 'Edit Program';
        document.getElementById('programName').value = data.program_name || '';
        document.getElementById('programDesc').value = data.description || '';
        document.getElementById('beneficiaryType').value = data.beneficiary_type || '';
        document.getElementById('startDate').value = data.start_date || '';
        document.getElementById('endDate').value = data.end_date || '';
        document.getElementById('programStatus').value = data.status || 'Upcoming';
        openModal('programModal');
    });
}
document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
