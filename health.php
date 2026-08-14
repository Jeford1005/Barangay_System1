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

        if ($action === 'add' || $action === 'edit') {
            $id = $_POST['id'] ?? null;
            $resident_id = !empty($_POST['resident_id']) ? (int)$_POST['resident_id'] : null;
            $blood_type = !empty($_POST['blood_type']) ? $_POST['blood_type'] : 'Unknown';
            $height_cm = $_POST['height_cm'] !== '' ? (int)$_POST['height_cm'] : null;
            $weight_kg = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
            $bmi = null;
            if ($height_cm && $weight_kg) {
                $h = $height_cm / 100;
                $bmi = round($weight_kg / ($h * $h), 2);
            }
            $vaccination_status = !empty($_POST['vaccination_status']) ? $_POST['vaccination_status'] : 'Unknown';
            $medical_conditions = trim($_POST['medical_conditions'] ?? '');
            $allergies = trim($_POST['allergies'] ?? '');
            $last_checkup = $_POST['last_checkup'] ?: null;
            $notes = trim($_POST['notes'] ?? '');

            if (!$resident_id) {
                $error = 'Resident is required.';
            } else {
                try {
                $oldValues = null;
                if ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("SELECT * FROM health_records WHERE id = ?");
                    $stmt->execute([$id]);
                    $oldValues = $stmt->fetch();
                }
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO health_records (resident_id, blood_type, height_cm, weight_kg, bmi, vaccination_status, medical_conditions, allergies, last_checkup, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$resident_id, $blood_type ?: null, $height_cm, $weight_kg, $bmi, $vaccination_status ?: null, $medical_conditions ?: null, $allergies ?: null, $last_checkup, $notes ?: null]);
                    $newId = $pdo->lastInsertId();
                    log_audit('create', 'health_record', $newId);
                    $message = 'Health record added successfully.';
                } elseif ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("UPDATE health_records SET resident_id=?, blood_type=?, height_cm=?, weight_kg=?, bmi=?, vaccination_status=?, medical_conditions=?, allergies=?, last_checkup=?, notes=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$resident_id, $blood_type ?: null, $height_cm, $weight_kg, $bmi, $vaccination_status ?: null, $medical_conditions ?: null, $allergies ?: null, $last_checkup, $notes ?: null, $id]);
                    log_audit('update', 'health_record', $id, $oldValues);
                    $message = 'Health record updated successfully.';
                }
                } catch (PDOException $e) {
                    $error = 'Could not save health record. The selected resident may no longer be valid.';
                    error_log('Health save error: ' . $e->getMessage());
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("SELECT * FROM health_records WHERE id = ?");
            $stmt->execute([$id]);
            $oldValues = $stmt->fetch();
            if ($oldValues) {
                $pdo->prepare("DELETE FROM health_records WHERE id = ?")->execute([$id]);
                log_audit('delete', 'health_record', $id, $oldValues);
                $message = 'Health record deleted.';
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$where = [];
$params = [];
if ($search) {
    $where[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR r.middle_name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT h.*, r.first_name, r.middle_name, r.last_name
    FROM health_records h
    LEFT JOIN residents r ON h.resident_id = r.id
    $whereSql
    ORDER BY r.last_name, r.first_name
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$residents = $pdo->query("SELECT id, first_name, middle_name, last_name FROM residents WHERE status='Active' ORDER BY last_name, first_name")->fetchAll();

$total = $pdo->query("SELECT COUNT(*) FROM health_records")->fetchColumn();
$vax = $pdo->query("SELECT COUNT(*) FROM health_records WHERE vaccination_status='Fully Vaccinated'")->fetchColumn();
$checkups = $pdo->query("SELECT COUNT(*) FROM health_records WHERE last_checkup >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/design-system.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <style>
        .bmi-display{flex-direction:column;align-items:flex-start;gap:2px;margin:6px 0 14px;padding:10px 14px;border-radius:8px;background:#F8F9FA;border-left:4px solid #1E5631;}
        .bmi-display .bmi-value{font-size:22px;font-weight:700;color:#1E5631;line-height:1;}
        .bmi-display .bmi-label{font-size:12px;color:#5b6b62;font-weight:600;}
        .bmi-display.bmi-under{border-left-color:#F4C430;}.bmi-display.bmi-under .bmi-value{color:#b8860b;}
        .bmi-display.bmi-over{border-left-color:#e08e0b;}.bmi-display.bmi-over .bmi-value{color:#e08e0b;}
        .bmi-display.bmi-obese{border-left-color:#c0392b;}.bmi-display.bmi-obese .bmi-value{color:#c0392b;}
    </style>
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>
        

    <main class="main-content">
        <?php $variant = 'admin'; include __DIR__ . '/views/mobile-topbar.php'; ?>
        <div class="page-header">
            <div>
                <h1><i class="fas fa-heartbeat"></i> Health Records</h1>
                <p>Track resident health profiles, vaccinations, and medical information</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="toast-alert toast-success" id="floatingAlert">
                <i class="fas fa-circle-check"></i>
                <span><?= esc($message) ?></span>
                <button onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="toast-alert toast-danger" id="floatingAlert">
                <i class="fas fa-exclamation"></i>
                <span><?= esc($error) ?></span>
                <button onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--secondary);"><i class="fas fa-notes-medical"></i></div>
                <div class="stat-info"><h3><?= number_format($total) ?></h3><p>Total Records</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--accent);"><i class="fas fa-syringe"></i></div>
                <div class="stat-info"><h3><?= number_format($vax) ?></h3><p>Fully Vaccinated</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--info);"><i class="fas fa-stethoscope"></i></div>
                <div class="stat-info"><h3><?= number_format($checkups) ?></h3><p>Checkups (6 mo)</p></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-notes-medical"></i> Resident Health Profiles</h2>
                <button class="btn btn-create" onclick="openAddHealth()"><i class="fas fa-plus"></i> Add Record</button>
            </div>
            <form method="GET" class="search-box" style="margin:12px 16px;max-width:320px;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search resident..." value="<?= esc($search) ?>">
            </form>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Resident</th><th>Blood Type</th><th>BMI</th>
                            <th>Vaccination</th><th>Last Checkup</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr><td colspan="6"><div class="empty-state"><i class="fas fa-heartbeat"></i><h3>No health records</h3><p>Add the first resident health profile.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $h): ?>
                                <?php
                                $name = trim($h['first_name'] . ' ' . ($h['middle_name'] ? substr($h['middle_name'],0,1).'. ' : '') . $h['last_name']);
                                $bmi = $h['bmi'] ? $h['bmi'] : '-';
                                $vaxBadge = [
                                    'Fully Vaccinated' => 'success',
                                    'Partially Vaccinated' => 'warning',
                                    'Not Vaccinated' => 'danger',
                                    'Unknown' => 'secondary'
                                ][$h['vaccination_status'] ?? 'Unknown'] ?? 'secondary';
                                ?>
                                <tr>
                                    <td><?= esc($name) ?></td>
                                    <td><?= esc($h['blood_type'] ?? '-') ?></td>
                                    <td><?= esc($bmi) ?></td>
                                    <td><span class="status-badge status-<?= $vaxBadge ?>"><?= esc($h['vaccination_status'] ?? 'Unknown') ?></span></td>
                                    <td><?= $h['last_checkup'] ? esc($h['last_checkup']) : '-' ?></td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-sm btn-update" onclick="editRecord(<?= $h['id'] ?>)"><i class="fas fa-pen-to-square"></i></button>
                                            <form method="POST" onsubmit="return handleDelete(this)" data-confirm-title="Delete Health Record" data-confirm-msg="Delete the health record for <?= esc(addslashes($name)) ?>? This cannot be undone."" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $h['id'] ?>">
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
    </main>
</div>

<div class="modal-backdrop" id="healthModal">
    <div class="modal" class="max-600">
        <div class="modal-header">
            <h3 id="healthModalTitle">Add Health Record</h3>
            <button class="modal-close" onclick="closeModal('healthModal')">&times;</button>
        </div>
        <form id="healthForm" method="POST" onsubmit="event.preventDefault(); submitHealthForm(this);">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" id="healthAction" value="add">
                <input type="hidden" name="id" id="healthId">
                <div class="form-group">
                    <label for="healthResident">Resident *</label>
                    <select name="resident_id" id="healthResident" class="form-control" required>
                        <option value="">Select resident</option>
                        <?php foreach ($residents as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= esc(trim($r['first_name'] . ' ' . ($r['middle_name'] ? substr($r['middle_name'],0,1).'. ' : '') . $r['last_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="blood_type">Blood Type</label>
                        <select name="blood_type" id="blood_type" class="form-control">
                            <option value="">Unknown</option>
                            <option>A+</option><option>A-</option><option>B+</option><option>B-</option>
                            <option>AB+</option><option>AB-</option><option>O+</option><option>O-</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="vaccination_status">Vaccination</label>
                        <select name="vaccination_status" id="vaccination_status" class="form-control">
                            <option value="">Unknown</option>
                            <option>Fully Vaccinated</option>
                            <option>Partially Vaccinated</option>
                            <option>Not Vaccinated</option>
                        </select>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="height_cm">Height (cm)</label>
                        <input type="number" name="height_cm" id="height_cm" class="form-control" min="0" oninput="updateBMI()">
                    </div>
                    <div class="form-group">
                        <label for="weight_kg">Weight (kg)</label>
                        <input type="number" name="weight_kg" id="weight_kg" class="form-control" min="0" step="0.01" oninput="updateBMI()">
                    </div>
                </div>
                <div id="bmiDisplay" class="bmi-display" style="display:none;"></div>
                <div class="form-group">
                    <label for="last_checkup">Last Checkup</label>
                    <input type="date" name="last_checkup" id="last_checkup" class="form-control">
                </div>
                <div class="form-group">
                    <label for="medical_conditions">Medical Conditions</label>
                    <textarea name="medical_conditions" id="medical_conditions" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label for="allergies">Allergies</label>
                    <textarea name="allergies" id="allergies" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('healthModal')">Cancel</button>
                <button type="submit" class="btn btn-create"><i class="fas fa-save"></i> Save Record</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateBMI() {
    const h = parseFloat(document.getElementById('height_cm').value);
    const w = parseFloat(document.getElementById('weight_kg').value);
    const box = document.getElementById('bmiDisplay');
    if (!h || !w || h <= 0) { box.style.display = 'none'; return; }
    const bmi = (w / Math.pow(h / 100, 2));
    let cat = 'Normal', cls = 'bmi-normal';
    if (bmi < 18.5) { cat = 'Underweight'; cls = 'bmi-under'; }
    else if (bmi >= 25 && bmi < 30) { cat = 'Overweight'; cls = 'bmi-over'; }
    else if (bmi >= 30) { cat = 'Obese'; cls = 'bmi-obese'; }
    box.className = 'bmi-display ' + cls;
    box.style.display = 'flex';
    box.innerHTML = '<span class="bmi-value">' + bmi.toFixed(1) + '</span><span class="bmi-label">BMI &middot; ' + cat + '</span>';
}
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function submitHealthForm(form) {
    const fd = new FormData();
    fd.append('csrf_token', form.querySelector('[name=csrf_token]').value);
    fd.append('action', form.querySelector('[name=action]').value);
    fd.append('id', form.querySelector('[name=id]').value);
    fd.append('resident_id', form.querySelector('[name=resident_id]').value);
    fd.append('blood_type', form.querySelector('[name=blood_type]').value);
    fd.append('vaccination_status', form.querySelector('[name=vaccination_status]').value);
    fd.append('height_cm', form.querySelector('[name=height_cm]').value);
    fd.append('weight_kg', form.querySelector('[name=weight_kg]').value);
    fd.append('last_checkup', form.querySelector('[name=last_checkup]').value);
    fd.append('medical_conditions', form.querySelector('[name=medical_conditions]').value);
    fd.append('allergies', form.querySelector('[name=allergies]').value);
    fd.append('notes', form.querySelector('[name=notes]').value);
    setTimeout(() => {
        fetch('health.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(() => location.reload())
            .catch(() => location.reload());
    }, 0);
    return false;
}
function openAddHealth() {
    document.getElementById('healthForm').reset();
    document.getElementById('healthAction').value = 'add';
    document.getElementById('healthId').value = '';
    document.getElementById('healthModalTitle').textContent = 'Add Health Record';
    document.getElementById('bmiDisplay').style.display = 'none';
    openModal('healthModal');
}
function editRecord(id) {
    fetch('<?= BASE_URL ?>/api/health.php?id=' + id).then(r => r.json()).then(d => {
        if (!d || !d.id) return alert('Record not found');
        document.getElementById('healthAction').value = 'edit';
        document.getElementById('healthId').value = d.id;
        document.getElementById('healthModalTitle').textContent = 'Edit Health Record';
        document.getElementById('healthResident').value = d.resident_id || '';
        document.getElementById('blood_type').value = d.blood_type || '';
        document.getElementById('vaccination_status').value = d.vaccination_status || '';
        document.getElementById('height_cm').value = d.height_cm || '';
        document.getElementById('weight_kg').value = d.weight_kg || '';
        document.getElementById('last_checkup').value = d.last_checkup || '';
        document.getElementById('medical_conditions').value = d.medical_conditions || '';
        document.getElementById('allergies').value = d.allergies || '';
        document.getElementById('notes').value = d.notes || '';
        openModal('healthModal');
        updateBMI();
    });
}
</script>
<script src="assets/js/main.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
