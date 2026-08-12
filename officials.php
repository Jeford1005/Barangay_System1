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
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $suffix = trim($_POST['suffix'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $contact_number = trim($_POST['contact_number'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $term_start = $_POST['term_start'] ?? null;
            $term_end = $_POST['term_end'] ?? null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (!$first_name || !$last_name || !$position) {
                $error = 'First Name, Last Name, and Position are required.';
            } else {
                $oldValues = null;
                if ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("SELECT * FROM officials WHERE id = ?");
                    $stmt->execute([$id]);
                    $oldValues = $stmt->fetch();
                }
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO officials (first_name, middle_name, last_name, suffix, position, contact_number, email, term_start, term_end, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$first_name, $middle_name ?: null, $last_name, $suffix ?: null, $position, $contact_number ?: null, $email ?: null, $term_start ?: null, $term_end ?: null, $is_active]);
                    $newId = $pdo->lastInsertId();
                    log_audit('create', 'official', $newId);
                    $message = 'Official added successfully.';
                } elseif ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("UPDATE officials SET first_name=?, middle_name=?, last_name=?, suffix=?, position=?, contact_number=?, email=?, term_start=?, term_end=?, is_active=? WHERE id=?");
                    $stmt->execute([$first_name, $middle_name ?: null, $last_name, $suffix ?: null, $position, $contact_number ?: null, $email ?: null, $term_start ?: null, $term_end ?: null, $is_active, $id]);
                    log_audit('update', 'official', $id, $oldValues);
                    $message = 'Official updated successfully.';
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM officials WHERE id = ?");
            $stmt->execute([$id]);
            log_audit('delete', 'official', $id);
            $message = 'Official deleted successfully.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$activeFilter = $_GET['active'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = "(o.first_name LIKE ? OR o.last_name LIKE ? OR o.position LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($activeFilter !== '') {
    $where[] = "o.is_active = ?";
    $params[] = $activeFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM officials o WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("SELECT * FROM officials o WHERE $whereSql ORDER BY o.is_active DESC, o.position ASC LIMIT ? OFFSET ?");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$officials = $stmt->fetchAll();

$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Officials - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-user-tie"></i> Officials Directory</h1>
                <p>Barangay Bidduang elected and appointed officials</p>
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
                <div class="stat-icon" style="background:var(--secondary);"><i class="fas fa-user-tie"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM officials WHERE is_active=1")->fetchColumn()) ?></h3><p>Active Officials</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--text-muted);"><i class="fas fa-user-clock"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM officials WHERE is_active=0")->fetchColumn()) ?></h3><p>Inactive Officials</p></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Officials List</h2>
                <div class="toolbar">
                    <form method="GET" class="search-box" style="flex:1;min-width:220px;">
                        <input type="hidden" name="active" value="<?= esc($activeFilter) ?>">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search by name or position..." value="<?= esc($search) ?>">
                    </form>
                    <select class="form-control" style="width:auto;min-width:150px;" onchange="window.location.href='?active='+this.value+'&search=<?= urlencode($search) ?>'">
                        <option value="">All Status</option>
                        <option value="1" <?= $activeFilter==='1'?'selected':'' ?>>Active</option>
                        <option value="0" <?= $activeFilter==='0'?'selected':'' ?>>Inactive</option>
                    </select>
                    <button class="btn btn-primary" onclick="openModal('officialModal')"><i class="fas fa-plus"></i> Add Official</button>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Photo</th><th>Name</th><th>Position</th><th>Contact</th><th>Email</th><th>Term</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($officials)): ?>
                            <tr><td colspan="8"><div class="empty-state"><i class="fas fa-user-tie"></i><h3>No officials found</h3><p>Add an official to get started.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($officials as $o): ?>
                            <tr>
                                <td>
                                    <?php if ($o['photo_path']): ?>
                                        <img src="<?= esc($o['photo_path']) ?>" alt="Photo" style="width:45px;height:45px;border-radius:50%;object-fit:cover;">
                                    <?php else: ?>
                                        <div style="width:45px;height:45px;border-radius:50%;background:var(--light);display:flex;align-items:center;justify-content:center;color:var(--text-muted);"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= esc($o['first_name'] . ' ' . ($o['middle_name'] ? substr($o['middle_name'],0,1).'. ' : '') . $o['last_name'] . ($o['suffix'] ? ', ' . $o['suffix'] : '')) ?></strong></td>
                                <td><?= esc($o['position']) ?></td>
                                <td><?= esc($o['contact_number'] ?: '-') ?></td>
                                <td><?= esc($o['email'] ?: '-') ?></td>
                                <td><?= $o['term_start'] ? date('M d, Y', strtotime($o['term_start'])) : '-' ?> - <?= $o['term_end'] ? date('M d, Y', strtotime($o['term_end'])) : '-' ?></td>
                                <td><span class="badge badge-<?= $o['is_active']?'success':'secondary' ?>"><?= $o['is_active']?'Active':'Inactive' ?></span></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-sm btn-info" onclick="editOfficial(<?= $o['id'] ?>)"><i class="fas fa-pen-to-square"></i></button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteOfficial(<?= $o['id'] ?>, '<?= esc(addslashes($o['first_name'].' '.$o['last_name'])) ?>')"><i class="fas fa-trash"></i></button>
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

<div class="modal-backdrop" id="officialModal">
    <div class="modal" style="max-width:650px;">
        <div class="modal-header">
            <h3 id="modalTitle">Add Official</h3>
            <button class="modal-close" onclick="closeModal('officialModal')">&times;</button>
        </div>
        <form id="officialForm" method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="officialId">
                <div style="display:flex;gap:25px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="text-align:center;flex:0 0 auto;">
                        <img id="photoPreview" src="" alt="Preview" class="photo-preview square" style="display:none;">
                        <div id="photoPlaceholder" class="photo-preview square" style="display:flex;align-items:center;justify-content:center;background:var(--light);color:var(--text-muted);font-size:40px;"><i class="fas fa-user"></i></div>
                        <input type="file" name="photo" id="photoInput" class="form-control" accept="image/*" style="margin-top:8px;">
                        <small class="text-muted">JPG, PNG (Max 2MB)</small>
                    </div>
                    <div style="flex:1;min-width:250px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                            <div class="form-group"><label for="firstName">First Name *</label> <input type="text" name="first_name" id="firstName" class="form-control" required></div>
                            <div class="form-group"><label for="middleName">Middle Name</label> <input type="text" name="middle_name" id="middleName" class="form-control"></div>
                            <div class="form-group"><label for="lastName">Last Name *</label> <input type="text" name="last_name" id="lastName" class="form-control" required></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group">
                                <label for="suffix">Suffix</label> <select name="suffix" id="suffix" class="form-control">
                                    <option value="">None</option><option>Jr.</option><option>Sr.</option><option>II</option><option>III</option>
                                </select>
                            </div>
                            <div class="form-group"><label for="position">Position *</label> <input type="text" name="position" id="position" class="form-control" required placeholder="e.g., Barangay Captain"></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group"><label for="contactNumber">Contact Number</label> <input type="text" name="contact_number" id="contactNumber" class="form-control"></div>
                            <div class="form-group"><label for="email">Email</label> <input type="email" name="email" id="email" class="form-control"></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group"><label for="termStart">Term Start</label> <input type="date" name="term_start" id="termStart" class="form-control"></div>
                            <div class="form-group"><label for="termEnd">Term End</label> <input type="date" name="term_end" id="termEnd" class="form-control"></div>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-group"><input type="checkbox" name="is_active" value="1" checked> Active / Currently Serving</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('officialModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Official</button>
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
            <p style="font-size:17px;">Delete <strong id="deleteName"></strong>? This cannot be undone.</p>
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
document.getElementById('photoInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) { const reader = new FileReader(); reader.onload = ev => { document.getElementById('photoPreview').src = ev.target.result; document.getElementById('photoPreview').style.display='block'; document.getElementById('photoPlaceholder').style.display='none'; }; reader.readAsDataURL(file); }
});
function editOfficial(id) {
    fetch('<?= BASE_URL ?>/api/official.php?id=' + id).then(r => r.json()).then(data => {
        if (!data.id) return alert('Not found');
        document.getElementById('formAction').value = 'edit';
        document.getElementById('officialId').value = data.id;
        document.getElementById('modalTitle').textContent = 'Edit Official';
        document.getElementById('firstName').value = data.first_name || '';
        document.getElementById('middleName').value = data.middle_name || '';
        document.getElementById('lastName').value = data.last_name || '';
        document.getElementById('suffix').value = data.suffix || '';
        document.getElementById('position').value = data.position || '';
        document.getElementById('contactNumber').value = data.contact_number || '';
        document.getElementById('email').value = data.email || '';
        document.getElementById('termStart').value = data.term_start || '';
        document.getElementById('termEnd').value = data.term_end || '';
        document.querySelector('#officialForm input[name=is_active]').checked = data.is_active == 1;
        if (data.photo_path) { document.getElementById('photoPreview').src = data.photo_path; document.getElementById('photoPreview').style.display='block'; document.getElementById('photoPlaceholder').style.display='none'; }
        openModal('officialModal');
    });
}
function deleteOfficial(id, name) { document.getElementById('deleteId').value = id; document.getElementById('deleteName').textContent = name; openModal('deleteModal'); }
document.querySelectorAll('.modal-backdrop').forEach(el => { el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); }); });
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
