<?php
require_once __DIR__ . '/config.php';
require_role('admin');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $id = $_POST['id'] ?? null;
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            $role = $_POST['role'] ?? 'staff';
            $status = $_POST['status'] ?? 'active';
            $password = $_POST['password'] ?? '';
            $resident_id = !empty($_POST['resident_id']) ? $_POST['resident_id'] : null;

            if (!$username || !$email || !$full_name) {
                $error = 'Username, Email, and Full Name are required.';
            } elseif ($action === 'add' && !$password) {
                $error = 'Password is required for new accounts.';
            } else {
                $oldValues = null;
                if ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    $oldValues = $stmt->fetch();
                }

                if ($action === 'add') {
                    $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                    $check->execute([$username, $email]);
                    if ($check->fetch()) {
                        $error = 'Username or email already exists.';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, resident_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, $hash, $full_name, $role, $resident_id, $status]);
                        $newId = $pdo->lastInsertId();
                        log_audit('create', 'user', $newId, null, ['username' => $username, 'role' => $role]);
                        $message = 'Account created successfully.';
                    }
                } elseif ($action === 'edit' && $id) {
                    $check = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                    $check->execute([$username, $email, $id]);
                    if ($check->fetch()) {
                        $error = 'Username or email already exists.';
                    } else {
                        if ($password) {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, password=?, full_name=?, role=?, resident_id=?, status=? WHERE id=?");
                            $stmt->execute([$username, $email, $hash, $full_name, $role, $resident_id, $status, $id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, full_name=?, role=?, resident_id=?, status=? WHERE id=?");
                            $stmt->execute([$username, $email, $full_name, $role, $resident_id, $status, $id]);
                        }
                        log_audit('update', 'user', $id, $oldValues, ['username' => $username, 'role' => $role]);
                        $message = 'Account updated successfully.';
                    }
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = $_POST['id'];
            if ($id == $_SESSION['user_id']) {
                $error = 'You cannot delete your own account.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                log_audit('delete', 'user', $id);
                $message = 'Account deleted successfully.';
            }
        } elseif ($action === 'reset_password' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $newPassword = 'Pass@123';
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->execute([$hash, $id]);
            log_audit('update', 'user', $id, null, ['action' => 'password_reset']);
            $message = 'Password reset to: Pass@123 (Please inform user to change immediately).';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ["u.role != 'resident'"];
$params = [];
if ($search) {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter) {
    $where[] = "u.role = ?";
    $params[] = $roleFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT u.*, r.first_name, r.last_name
    FROM users u
    LEFT JOIN residents r ON u.resident_id = r.id
    WHERE $whereSql
    ORDER BY u.role ASC, u.username ASC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$users = $stmt->fetchAll();

$residents = $pdo->query("SELECT id, first_name, last_name FROM residents WHERE status='Active' ORDER BY last_name, first_name")->fetchAll();

$currentUser = current_user();
$user = current_user();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-user-gear"></i> Account Management</h1>
                <p>Manage official and staff user accounts</p>
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
                <div class="stat-icon" style="background:var(--secondary);"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM users WHERE role != 'resident'")->fetchColumn()) ?></h3><p>Total Accounts</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--accent);"><i class="fas fa-user-shield"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()) ?></h3><p>Admins</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--info);"><i class="fas fa-user-tie"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM users WHERE role='official'")->fetchColumn()) ?></h3><p>Officials</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--info);"><i class="fas fa-user"></i></div>
                <div class="stat-info"><h3><?= number_format($pdo->query("SELECT COUNT(*) FROM users WHERE role='staff'")->fetchColumn()) ?></h3><p>Staff</p></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>User Accounts</h2>
                <div class="toolbar">
                    <form method="GET" class="search-box" style="flex:1;min-width:220px;">
                        <input type="hidden" name="role" value="<?= esc($roleFilter) ?>">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search accounts..." value="<?= esc($search) ?>">
                    </form>
                    <select class="form-control" style="width:auto;min-width:130px;" onchange="window.location.href='?role='+this.value+'&search=<?= urlencode($search) ?>'">
                        <option value="">All Roles</option>
                        <option value="admin" <?= $roleFilter==='admin'?'selected':'' ?>>Admin</option>
                        <option value="official" <?= $roleFilter==='official'?'selected':'' ?>>Official</option>
                        <option value="staff" <?= $roleFilter==='staff'?'selected':'' ?>>Staff</option>
                    </select>
                    <button class="btn btn-primary" onclick="openModal('accountModal')"><i class="fas fa-plus"></i> Add Account</button>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="7"><div class="empty-state"><i class="fas fa-user-gear"></i><h3>No accounts found</h3><p>Add an official or staff account.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><strong><?= esc($u['username']) ?></strong></td>
                                <td><?= esc($u['full_name']) ?></td>
                                <td><?= esc($u['email']) ?></td>
                                <td><span class="badge badge-<?= $u['role']=='admin'?'danger':($u['role']=='staff'?'info':'secondary') ?>"><?= ucfirst($u['role']) ?></span></td>
                                <td><span class="badge badge-<?= $u['status']=='active'?'success':'secondary' ?>"><?= ucfirst($u['status']) ?></span></td>
                                <td><?= $u['last_login'] ? date('M d, Y h:i A', strtotime($u['last_login'])) : 'Never' ?></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-sm btn-info" onclick="editAccount(<?= $u['id'] ?>)"><i class="fas fa-pen-to-square"></i></button>
                                        <button class="btn btn-sm btn-warning" onclick="resetPassword(<?= $u['id'] ?>, '<?= esc(addslashes($u['username'])) ?>')"><i class="fas fa-key"></i></button>
                                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-sm btn-danger" onclick="deleteAccount(<?= $u['id'] ?>, '<?= esc(addslashes($u['username'])) ?>')"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
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

<div class="modal-backdrop" id="accountModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3 id="modalTitle">Add Account</h3>
            <button class="modal-close" onclick="closeModal('accountModal')">&times;</button>
        </div>
        <form id="accountForm" method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="accountId">
                <div class="form-group"><label for="username">Username *</label><input type="text" name="username" id="username" class="form-control" required autocomplete="off"></div>
                                <div class="form-group"><label for="fullName">Full Name *</label><input type="text" name="full_name" id="fullName" class="form-control" required autocomplete="name"></div>
                                <div class="form-group"><label for="email">Email *</label><input type="email" name="email" id="email" class="form-control" required autocomplete="email"></div>
                                <div class="form-group"><label for="password">Password <?= $action==='add'?'*':'(leave blank to keep current)' ?></label><input type="password" name="password" id="password" class="form-control" <?= $action==='add'?'required':'' ?> autocomplete="new-password"></div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div class="form-group">
                                        <label for="role">Role *</label>
                                        <select name="role" id="role" class="form-control" required>
                                                                    <option value="admin">Admin</option><option value="official">Official</option><option value="staff">Staff</option>
                                                                </select>
                                                            </div>
                                                            <div class="form-group">
                                                                <label for="accountStatus">Status</label>
                                                                <select name="status" id="accountStatus" class="form-control">
                                                                    <option value="active">Active</option><option value="inactive">Inactive</option><option value="pending">Pending</option>
                                                                </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Linked Resident</label>
                    <select name="resident_id" id="residentId" class="form-control">
                        <option value="">None</option>
                        <?php foreach ($residents as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('accountModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Account</button>
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
            <p style="font-size:17px;">Delete account <strong id="deleteName"></strong>? This cannot be undone.</p>
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
function editAccount(id) {
    fetch('<?= BASE_URL ?>/api/user.php?id=' + id).then(r => r.json()).then(data => {
        if (!data.id) return alert('Not found');
        document.getElementById('formAction').value = 'edit';
        document.getElementById('accountId').value = data.id;
        document.getElementById('modalTitle').textContent = 'Edit Account';
        document.getElementById('username').value = data.username || '';
        document.getElementById('fullName').value = data.full_name || '';
        document.getElementById('email').value = data.email || '';
        document.getElementById('password').required = false;
        document.getElementById('password').value = '';
        document.getElementById('role').value = data.role || 'staff';
        document.getElementById('accountStatus').value = data.status || 'active';
        document.getElementById('residentId').value = data.resident_id || '';
        openModal('accountModal');
    });
}
function deleteAccount(id, name) { document.getElementById('deleteId').value = id; document.getElementById('deleteName').textContent = name; openModal('deleteModal'); }
function resetPassword(id, username) {
    if (confirm('Reset password for ' + username + '?\nNew password will be: Pass@123')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
