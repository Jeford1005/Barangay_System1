<?php
/**
 * residents.php
 * Barangay Bidduang - Resident Management
 * Role: admin, staff
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_role(['admin', 'staff']);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
        header('Location: residents.php');
        exit;
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $id = $_POST['id'] ?? null;
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $suffix = trim($_POST['suffix'] ?? '');
            $birth_date = $_POST['birth_date'] ?? '';
            $birth_place = trim($_POST['birth_place'] ?? '');
            $gender = $_POST['gender'] ?? '';
            $civil_status = $_POST['civil_status'] ?? 'Single';
            $citizenship = trim($_POST['citizenship'] ?? 'Filipino');
            $religion = trim($_POST['religion'] ?? '');
            $occupation = trim($_POST['occupation'] ?? '');
            $contact_number = trim($_POST['contact_number'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $voter_status = $_POST['voter_status'] ?? 'Not Registered';
            $cls = $_POST['classification'] ?? '';
            $is_senior = $cls === 'senior' ? 1 : 0;
            $is_pwd = $cls === 'pwd' ? 1 : 0;
            $is_indigent = $cls === 'indigent' ? 1 : 0;
            $fourps_beneficiary = $cls === '4ps' ? 1 : 0;
            $household_id = !empty($_POST['household_id']) ? $_POST['household_id'] : null;
            $purok_id = !empty($_POST['purok_id']) ? $_POST['purok_id'] : null;
            $status = $_POST['status'] ?? 'Active';

            if (!$first_name || !$last_name || !$birth_date || !$gender) {
                $_SESSION['flash_error'] = 'First Name, Last Name, Birth Date, and Gender are required.';
                header('Location: residents.php');
                exit;
            } else {
                $oldValues = null;
                if ($action === 'edit' && $id) {
                    $stmt = $pdo->prepare("SELECT * FROM residents WHERE id = ?");
                    $stmt->execute([$id]);
                    $oldValues = $stmt->fetch();
                }

                $photo_path = null;
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $allowed = ['image/jpeg', 'image/png', 'image/gif'];
                    $imageInfo = @getimagesize($_FILES['photo']['tmp_name']);
                    $mime = $imageInfo['mime'] ?? '';
                    if (in_array($mime, $allowed)) {
                        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                        $filename = 'resident_' . ($id ?? uniqid()) . '_' . time() . '.' . $ext;
                        $dest = UPLOAD_PATH . '/photos/' . $filename;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                            $photo_path = $filename;
                        }
                    }
                }

                if ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO residents (first_name, middle_name, last_name, suffix, birth_date, birth_place, gender, civil_status, citizenship, religion, occupation, contact_number, email, photo_path, voter_status, is_pwd, is_senior, is_indigent, fourps_beneficiary, household_id, purok_id, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$first_name, $middle_name ?: null, $last_name, $suffix ?: null, $birth_date, $birth_place ?: null, $gender, $civil_status, $citizenship, $religion ?: null, $occupation ?: null, $contact_number ?: null, $email ?: null, $photo_path, $voter_status, $is_pwd, $is_senior, $is_indigent, $fourps_beneficiary, $household_id, $purok_id, $status]);
                    $newId = $pdo->lastInsertId();
                    $fullName = trim("$first_name $last_name");
                    $newValues = [
                        'first_name' => $first_name, 'last_name' => $last_name, 'middle_name' => $middle_name,
                        'birth_date' => $birth_date, 'gender' => $gender, 'civil_status' => $civil_status,
                        'contact_number' => $contact_number, 'email' => $email, 'status' => $status
                    ];
                    AuditLogger::create('Resident', $newId, $newValues, null, "Added resident $fullName");

                    $_SESSION['flash_message'] = 'Resident added successfully.';
                    header('Location: residents.php');
                    exit;
                } elseif ($action === 'edit' && $id) {
                    if (!$photo_path && $oldValues) {
                        $photo_path = $oldValues['photo_path'];
                    }
                    $stmt = $pdo->prepare("
                        UPDATE residents SET first_name=?, middle_name=?, last_name=?, suffix=?, birth_date=?, birth_place=?, gender=?, civil_status=?, citizenship=?, religion=?, occupation=?, contact_number=?, email=?, photo_path=?, voter_status=?, is_pwd=?, is_senior=?, is_indigent=?, fourps_beneficiary=?, household_id=?, purok_id=?, status=?
                        WHERE id=?
                    ");
                    $stmt->execute([$first_name, $middle_name ?: null, $last_name, $suffix ?: null, $birth_date, $birth_place ?: null, $gender, $civil_status, $citizenship, $religion ?: null, $occupation ?: null, $contact_number ?: null, $email ?: null, $photo_path, $voter_status, $is_pwd, $is_senior, $is_indigent, $fourps_beneficiary, $household_id, $purok_id, $status, $id]);
                    $fullName = trim("$first_name $last_name");
                    $newValues = [
                        'first_name' => $first_name, 'last_name' => $last_name, 'middle_name' => $middle_name,
                        'birth_date' => $birth_date, 'gender' => $gender, 'civil_status' => $civil_status,
                        'contact_number' => $contact_number, 'email' => $email, 'status' => $status
                    ];
                    AuditLogger::update('Resident', $id, $oldValues, $newValues, null, "Edited resident $fullName");

                    $_SESSION['flash_message'] = 'Resident updated successfully.';
                    header('Location: residents.php');
                    exit;
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("SELECT * FROM residents WHERE id = ?");
            $stmt->execute([$id]);
            $deleted = $stmt->fetch();
            $delName = $deleted ? trim(($deleted['first_name'] ?? '') . ' ' . ($deleted['last_name'] ?? '')) : "ID $id";
            $stmt = $pdo->prepare("DELETE FROM residents WHERE id = ?");
            $stmt->execute([$id]);
            AuditLogger::delete('Resident', $id, $deleted, null, "Deleted resident $delName");

            $_SESSION['flash_message'] = 'Resident deleted successfully.';
            header('Location: residents.php');
            exit;
        }
    }
}

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(r.first_name LIKE ? OR r.middle_name LIKE ? OR r.last_name LIKE ? OR r.contact_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter) {
    if (in_array($statusFilter, ['senior', 'pwd', 'indigent'], true)) {
        $col = $statusFilter === 'senior' ? 'is_senior' : ($statusFilter === 'pwd' ? 'is_pwd' : 'is_indigent');
        $where[] = "r.$col = 1";
    } else {
        $where[] = "r.status = ?";
        $params[] = $statusFilter;
    }
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM residents r WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$stmt = $pdo->prepare("
    SELECT r.*, h.household_number, p.purok_name
    FROM residents r
    LEFT JOIN households h ON r.household_id = h.id
    LEFT JOIN puroks p ON r.purok_id = p.id
    WHERE $whereSql
    ORDER BY r.last_name ASC, r.first_name ASC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$residents = $stmt->fetchAll();

$households = $pdo->query("SELECT id, household_number FROM households ORDER BY household_number")->fetchAll();
$puroks = $pdo->query("SELECT id, purok_name FROM puroks ORDER BY purok_name")->fetchAll();

$currentUser = current_user();
$user = current_user();
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Residents - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
    <div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
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

        <div class="page-header">
            <div>
                <h1 class="page-title">Resident Management</h1>
                <p class="page-subtitle">Profiling and Records of Barangay Bidduang Residents</p>
            </div>
        </div>

        <div class="metrics-grid">
            <?php
            $totalResidents = $pdo->query("SELECT COUNT(*) FROM residents WHERE status='Active'")->fetchColumn();
            $totalSeniors = $pdo->query("SELECT COUNT(*) FROM residents WHERE is_senior=1 AND status='Active'")->fetchColumn();
            $totalPWD = $pdo->query("SELECT COUNT(*) FROM residents WHERE is_pwd=1 AND status='Active'")->fetchColumn();
            $totalIndigent = $pdo->query("SELECT COUNT(*) FROM residents WHERE is_indigent=1 AND status='Active'")->fetchColumn();
            ?>
            <div class="metric-card">
                <div class="metric-icon blue"><i class="fas fa-users"></i></div>
                <div class="metric-info"><h3><?= number_format($totalResidents) ?></h3><p>Total Active Residents</p></div>
            </div>
            <div class="metric-card">
                <div class="metric-icon orange"><i class="fas fa-user-clock"></i></div>
                <div class="metric-info"><h3><?= number_format($totalSeniors) ?></h3><p>Senior Citizens</p></div>
            </div>
            <div class="metric-card">
                <div class="metric-icon red"><i class="fas fa-wheelchair"></i></div>
                <div class="metric-info"><h3><?= number_format($totalPWD) ?></h3><p>PWD</p></div>
            </div>
            <div class="metric-card">
                <div class="metric-icon green"><i class="fas fa-hand-holding-heart"></i></div>
                <div class="metric-info"><h3><?= number_format($totalIndigent) ?></h3><p>Indigent</p></div>
            </div>
        </div>

        <div class="card">
            <div class="card-title" style="justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <span>Resident Records</span>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <form method="GET" class="search-box" style="flex:1;min-width:220px;">
                        <input type="hidden" name="status" value="<?= esc($statusFilter) ?>">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search by name or contact..." value="<?= esc($search) ?>">
                    </form>
                                        <div class="status-filter-native">
                        <i class="fas fa-filter" style="color:#8a94a6;margin-right:6px;"></i>
                        <select class="form-control" style="width:auto;min-width:170px;display:inline-block;" onchange="window.location.href='?status='+this.value+'&search=<?= urlencode($search) ?>'">
                            <option value="" <?= $statusFilter===''?'selected':'' ?>>All Status</option>
                            <option value="Active" <?= $statusFilter==='Active'?'selected':'' ?>>Active</option>
                            <option value="Deceased" <?= $statusFilter==='Deceased'?'selected':'' ?>>Deceased</option>
                            <option value="Moved Out" <?= $statusFilter==='Moved Out'?'selected':'' ?>>Moved Out</option>
                            <option value="senior" <?= $statusFilter==='senior'?'selected':'' ?>>♿ Senior Citizen</option>
                            <option value="pwd" <?= $statusFilter==='pwd'?'selected':'' ?>>🦽 PWD</option>
                            <option value="indigent" <?= $statusFilter==='indigent'?'selected':'' ?>>🤝 Indigent</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Resident</button>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Birth Date</th>
                            <th>Purok</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($residents)): ?>
                            <tr><td colspan="7" style="text-align:center; color:#6b7280;">No residents found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($residents as $r): ?>
                                <tr>
                                    <td>
                                        <div class="name-cell">
                                            <span class="avatar"><?= esc(strtoupper(substr($r['first_name'], 0, 1) . substr($r['last_name'], 0, 1))) ?></span>
                                            <span><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></span>
                                        </div>
                                    </td>
                                    <td><?= esc(ucfirst($r['gender'])) ?></td>
                                    <td><?= !empty($r['birth_date']) ? esc(date('F j, Y', strtotime($r['birth_date']))) : 'N/A' ?></td>
                                    <td><?= esc($r['purok_name'] ?? 'N/A') ?></td>
                                    <td><?= esc($r['contact_number'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge <?= $r['status']==='Active' ? 'badge-success' : ($r['status']==='Deceased' ? 'badge-danger' : 'badge-warning') ?>">
                                            <?= esc($r['status']) ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <button class="btn btn-secondary" onclick="openEditModal(
                                            <?= (int)$r['id'] ?>,
                                            '<?= esc(addslashes($r['first_name'])) ?>',
                                            '<?= esc(addslashes($r['last_name'])) ?>',
                                            '<?= esc($r['birth_date']) ?>',
                                            '<?= esc($r['gender']) ?>',
                                            '<?= (int)($r['purok_id'] ?? 0) ?>',
                                            '<?= esc(addslashes($r['contact_number'] ?? '')) ?>',
                                            '<?= esc($r['status']) ?>',
                                            <?= (int)($r['is_senior'] ?? 0) ?>,
                                            <?= (int)($r['is_pwd'] ?? 0) ?>,
                                            <?= (int)($r['is_indigent'] ?? 0) ?>,
                                            <?= (int)($r['fourps_beneficiary'] ?? 0) ?>
                                        )">Edit</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this resident?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
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

    <div id="addModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:20px; border-radius:12px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h2>Add Resident</h2>
                <button onclick="closeModal('addModal')" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                <div class="form-group">
                    <label>First Name <span style="color:#c0392b;">*</span></label>
                    <input type="text" name="first_name" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span style="color:#c0392b;">*</span></label>
                    <input type="text" name="last_name" required>
                </div>
                <div class="form-group">
                    <label>Birth Date <span style="color:#c0392b;">*</span></label>
                    <input type="date" name="birth_date" required>
                </div>
                <div class="form-group">
                    <label>Gender <span style="color:#c0392b;">*</span></label>
                    <select name="gender" required>
                        <option value="">Select</option>
                        <option>Male</option>
                        <option>Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Purok</label>
                    <select name="purok_id">
                        <option value="">None</option>
                        <?php foreach ($puroks as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= esc($p['purok_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Contact</label>
                    <input type="text" name="contact_number">
                </div>
                <div class="form-group">
                                    <div class="form-group">
                    <label>Classification <span style="font-weight:400;color:#6b7280;font-size:.8rem;">(choose one)</span></label>
                    <div class="chip-group">
                        <label class="chip chip-senior"><input type="radio" name="classification" value="senior"><span class="chip-box"><i class="fas fa-user-clock"></i> <span class="chip-text">Senior Citizen</span> <i class="fas fa-check chip-check"></i></span></label>
                        <label class="chip chip-pwd"><input type="radio" name="classification" value="pwd"><span class="chip-box"><i class="fas fa-wheelchair"></i> <span class="chip-text">PWD</span> <i class="fas fa-check chip-check"></i></span></label>
                        <label class="chip chip-indigent"><input type="radio" name="classification" value="indigent"><span class="chip-box"><i class="fas fa-hand-holding-heart"></i> <span class="chip-text">Indigent</span> <i class="fas fa-check chip-check"></i></span></label>
                        <label class="chip chip-4ps"><input type="radio" name="classification" value="4ps"><span class="chip-box"><i class="fas fa-hand-holding-usd"></i> <span class="chip-text">4Ps Beneficiary</span> <i class="fas fa-check chip-check"></i></span></label>
                    </div>
                </div>
<label>Status</label>
                    <select name="status">
                        <option>Active</option>
                        <option>Deceased</option>
                        <option>Moved Out</option>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit" style="width:100%;"><i class="fas fa-save"></i> Save</button>
            </form>
        </div>
    </div>


    <div id="editModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:20px; border-radius:12px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h2>Edit Resident</h2>
                <button onclick="closeModal('editModal')" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                <div class="form-group">
                    <label>First Name <span style="color:#c0392b;">*</span></label>
                    <input type="text" name="first_name" id="editFirstName" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span style="color:#c0392b;">*</span></label>
                    <input type="text" name="last_name" id="editLastName" required>
                </div>
                <div class="form-group">
                    <label>Birth Date <span style="color:#c0392b;">*</span></label>
                    <input type="date" name="birth_date" id="editBirthDate" required>
                </div>
                <div class="form-group">
                    <label>Gender <span style="color:#c0392b;">*</span></label>
                    <select name="gender" id="editGender" required>
                        <option value="">Select</option>
                        <option>Male</option>
                        <option>Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="editPurok">Purok</label> <select name="purok_id" id="editPurok">
                        <option value="">None</option>
                        <?php foreach ($puroks as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= esc($p['purok_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="editContact">Contact</label> <input type="text" name="contact_number" id="editContact">
                </div>
                <div class="form-group">
                                    <div class="form-group">
                    <label>Classification <span style="font-weight:400;color:#6b7280;font-size:.8rem;">(choose one)</span></label>
                    <div class="chip-group">
                        <label class="chip chip-senior"><input type="radio" name="classification" value="senior"><span class="chip-box"><i class="fas fa-user-clock"></i> <span class="chip-text">Senior Citizen</span> <i class="fas fa-check chip-check"></i></span></label>
                        <label class="chip chip-pwd"><input type="radio" name="classification" value="pwd"><span class="chip-box"><i class="fas fa-wheelchair"></i> <span class="chip-text">PWD</span> <i class="fas fa-check chip-check"></i></span></label>
                        <label class="chip chip-indigent"><input type="radio" name="classification" value="indigent"><span class="chip-box"><i class="fas fa-hand-holding-heart"></i> <span class="chip-text">Indigent</span> <i class="fas fa-check chip-check"></i></span></label>
                        <label class="chip chip-4ps"><input type="radio" name="classification" value="4ps"><span class="chip-box"><i class="fas fa-hand-holding-usd"></i> <span class="chip-text">4Ps Beneficiary</span> <i class="fas fa-check chip-check"></i></span></label>
                    </div>
                </div>
<label for="editStatus">Status</label> <select name="status" id="editStatus">
                        <option>Active</option>
                        <option>Deceased</option>
                        <option>Moved Out</option>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit" style="width:100%;"><i class="fas fa-save"></i> Update</button>
            </form>
        </div>
    </div>

    <script>
    function openModal(id) { 
        document.getElementById(id).style.display = 'flex'; 
    }

    function closeModal(id) { 
        document.getElementById(id).style.display = 'none'; 
    }

    function openEditModal(id, first, last, birthDate, gender, purokId, contact, status, isSenior, isPwd, isIndigent, fourps) {
        document.getElementById('editId').value = id;
        document.getElementById('editFirstName').value = first;
        document.getElementById('editLastName').value = last;
        document.getElementById('editBirthDate').value = birthDate;

        document.getElementById('editGender').value = gender || '';
        document.getElementById('editPurok').value = (purokId && purokId > 0) ? purokId : '';
        document.getElementById('editContact').value = contact || '';
        document.getElementById('editStatus').value = status || 'Active';

        var cls = isSenior ? 'senior' : (isPwd ? 'pwd' : (isIndigent ? 'indigent' : (fourps ? '4ps' : '')));
        var em = document.getElementById('editModal');
        var radios = em.querySelectorAll('input[name="classification"]');
        radios.forEach(function(rd){ rd.checked = (rd.value === cls); });

        openModal('editModal');
    }
</script>
<script src="assets/js/main.js"></script>
</body>
</html>