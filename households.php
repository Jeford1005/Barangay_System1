<?php
require_once __DIR__ . '/config.php';
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
            $household_number = trim($_POST['household_number'] ?? '');
            $head_id = !empty($_POST['head_id']) ? $_POST['head_id'] : null;
            $address = trim($_POST['address'] ?? '');
            $purok_id = $_POST['purok_id'] ?? '';
            $number_of_members = max(1, (int)($_POST['number_of_members'] ?? 1));
            $house_type = trim($_POST['house_type'] ?? '');

            if (!$household_number || !$address || !$purok_id) {
                $error = 'Household Number, Address, and Purok are required.';
            } else {
                $oldValues = null;
                if ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("SELECT * FROM households WHERE id = ?");
                    $stmt->execute([$id]);
                    $oldValues = $stmt->fetch();
                }
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO households (household_number, head_id, address, purok_id, number_of_members, house_type) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$household_number, $head_id, $address, $purok_id, $number_of_members, $house_type ?: null]);
                    $newId = $pdo->lastInsertId();
                    log_audit('create', 'household', $newId);
                    $message = 'Household added successfully.';
                } elseif ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("UPDATE households SET household_number=?, head_id=?, address=?, purok_id=?, number_of_members=?, house_type=? WHERE id=?");
                    $stmt->execute([$household_number, $head_id, $address, $purok_id, $number_of_members, $house_type ?: null, $id]);
                    log_audit('update', 'household', $id, $oldValues);
                    $message = 'Household updated successfully.';
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM households WHERE id = ?");
            $stmt->execute([$id]);
            log_audit('delete', 'household', $id);
            $message = 'Household deleted successfully.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = "(h.household_number LIKE ? OR h.address LIKE ? OR p.purok_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM households h LEFT JOIN puroks p ON h.purok_id = p.id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT h.*, p.purok_name,
        CONCAT(r.first_name, ' ', r.last_name) as head_name
    FROM households h
    LEFT JOIN puroks p ON h.purok_id = p.id
    LEFT JOIN residents r ON h.head_id = r.id
    WHERE $whereSql
    ORDER BY h.household_number ASC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$householdsList = $stmt->fetchAll();

$puroks = $pdo->query("SELECT id, purok_name FROM puroks ORDER BY purok_name")->fetchAll();
$residents = $pdo->query("SELECT id, first_name, last_name, suffix FROM residents WHERE status='Active' ORDER BY last_name, first_name")->fetchAll();

$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Households - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-house"></i> Household Management</h1>
                <p>Group residents into households and track family units</p>
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
                <div class="stat-icon" style="background:var(--secondary);"><i class="fas fa-house"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM households")->fetchColumn()) ?></h3><p>Total Households</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--accent);"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT SUM(number_of_members) FROM households")->fetchColumn() ?: 0) ?></h3><p>Total Members</p></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Household Records</h2>
                <div class="toolbar">
                    <form method="GET" class="search-box" style="flex:1;min-width:220px;">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search households..." value="<?= esc($search) ?>">
                    </form>
                    <button class="btn btn-primary" onclick="openModal('householdModal')"><i class="fas fa-plus"></i> Add Household</button>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Household #</th><th>Head of Family</th><th>Address</th><th>Purok</th><th>Members</th><th>House Type</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($householdsList)): ?>
                            <tr><td colspan="7"><div class="empty-state"><i class="fas fa-house"></i><h3>No households found</h3><p>Click Add Household to create one.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($householdsList as $h): ?>
                            <tr>
                                <td><strong><?= esc($h['household_number']) ?></strong></td>
                                <td><?= esc($h['head_name'] ?? 'N/A') ?></td>
                                <td><?= esc($h['address']) ?></td>
                                <td><?= esc($h['purok_name'] ?? '-') ?></td>
                                <td><?= $h['number_of_members'] ?></td>
                                <td><?= esc($h['house_type'] ?: '-') ?></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-sm btn-info" onclick="editHousehold(<?= $h['id'] ?>)"><i class="fas fa-pen-to-square"></i></button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteHousehold(<?= $h['id'] ?>, '<?= esc(addslashes($h['household_number'])) ?>')"><i class="fas fa-trash"></i></button>
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

<!-- Modal -->
<div class="modal-backdrop" id="householdModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle">Add Household</h3>
            <button class="modal-close" onclick="closeModal('householdModal')">&times;</button>
        </div>
        <form id="householdForm" method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="householdId">
                <div class="form-group">
                    <label for="householdNumber">Household Number *</label> <input type="text" name="household_number" id="householdNumber" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="headId">Head of Family</label> <select name="head_id" id="headId" class="form-control">
                        <option value="">Select Resident</option>
                        <?php foreach ($residents as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= esc($r['first_name'] . ' ' . $r['last_name'] . ($r['suffix'] ? ', ' . $r['suffix'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="address">Address *</label> <input type="text" name="address" id="address" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="purokId">Purok *</label> <select name="purok_id" id="purokId" class="form-control" required>
                        <option value="">Select Purok</option>
                        <?php foreach ($puroks as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= esc($p['purok_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="members">Number of Members</label> <input type="number" name="number_of_members" id="members" class="form-control" min="1" value="1">
                </div>
                <div class="form-group">
                    <label for="houseType">House Type</label> <input type="text" name="house_type" id="houseType" class="form-control" placeholder="e.g., Concrete, Wood, Mixed">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('householdModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Household</button>
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
            <p style="font-size:17px;">Delete household <strong id="deleteName"></strong>? This cannot be undone.</p>
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
function editHousehold(id) {
    fetch('<?= BASE_URL ?>/api/household.php?id=' + id).then(r => r.json()).then(data => {
        if (!data.id) return alert('Not found');
        document.getElementById('formAction').value = 'edit';
        document.getElementById('householdId').value = data.id;
        document.getElementById('modalTitle').textContent = 'Edit Household';
        document.getElementById('householdNumber').value = data.household_number || '';
        document.getElementById('headId').value = data.head_id || '';
        document.getElementById('address').value = data.address || '';
        document.getElementById('purokId').value = data.purok_id || '';
        document.getElementById('members').value = data.number_of_members || 1;
        document.getElementById('houseType').value = data.house_type || '';
        openModal('householdModal');
    });
}
function deleteHousehold(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    openModal('deleteModal');
}
document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
