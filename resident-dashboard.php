<?php
/**
 * resident-dashboard.php
 * Barangay Bidduang - Resident Self-Service Portal
 * Role: resident
 */

require_once __DIR__ . '/config.php';
require_auth();

$user = current_user();
$csrf = generate_csrf_token();

// Fetch resident profile if linked
$resident = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM residents WHERE id = :rid LIMIT 1");
    $stmt->execute([':rid' => $user['resident_id'] ?? 0]);
    $resident = $stmt->fetch();
} catch (PDOException $e) { /* table may not exist yet */ }

// Fetch resident's document requests
$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT dr.*, r.full_name as resident_name
        FROM document_requests dr
        LEFT JOIN residents r ON dr.resident_id = r.id
        WHERE dr.resident_id = :rid
        ORDER BY dr.requested_at DESC
        LIMIT 20
    ");
    $stmt->execute([':rid' => $resident['id'] ?? 0]);
    $requests = $stmt->fetchAll();
} catch (PDOException $e) { /* table may not exist yet */ }

// Handle document request submission
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

// Handle webcam photo capture
$photoMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['capture_photo'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $photoMsg = 'Invalid request.';
    } else {
        $imgData = $_POST['photo_data'] ?? '';
        if (!empty($imgData) && preg_match('/^data:image\/(\w+);base64,/', $imgData, $type)) {
            $data = substr($imgData, strpos($imgData, ',') + 1);
            $data = base64_decode($data);
            if ($data) {
                $ext = $type[1] === 'jpeg' ? 'jpg' : $type[1];
                $filename = 'photo_' . $user['id'] . '_' . time() . '.' . $ext;
                $path = UPLOAD_PATH . '/photos/' . $filename;
                if (file_put_contents($path, $data)) {
                    if ($resident) {
                        $stmt = $pdo->prepare("UPDATE residents SET photo_path = :p WHERE id = :id");
                        $stmt->execute([':p' => $filename, ':id' => $resident['id']]);
                    }
                    log_audit('update', 'resident_photo', $resident['id'] ?? null, null, ['photo' => $filename]);
                    $photoMsg = 'Photo captured and saved successfully!';
                } else {
                    $photoMsg = 'Failed to save photo.';
                }
            }
        } else {
            $photoMsg = 'No photo data received.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - Barangay Bidduang</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
    <div class="app">
    <!-- Sidebar -->
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">My Dashboard</h1>
                <p class="page-subtitle">Self-service portal for residents</p>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="toast-alert toast-success" id="floatingAlert">
                <i class="fas fa-circle-check"></i>
                <span><?= esc($successMsg) ?></span>
                <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="toast-alert toast-danger" id="floatingAlert">
                <i class="fas fa-exclamation"></i>
                <span><?= esc($errorMsg) ?></span>
                <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($photoMsg): ?>
            <div class="toast-alert <?= strpos($photoMsg, 'successfully') !== false ? 'toast-success' : 'toast-danger' ?>" id="floatingAlert">
                <i class="fas fa-camera"></i>
                <span><?= esc($photoMsg) ?></span>
                <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
            </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px;">
            <!-- Resident Info Card -->
            <div class="info-card" id="my-info">
                <div class="info-card-header">
                    <div class="info-card-avatar">
                        <?php if (!empty($resident['photo_path']) && file_exists(UPLOAD_PATH . '/photos/' . $resident['photo_path'])): ?>
                            <img src="<?php echo BASE_URL; ?>/uploads/photos/<?php echo esc($resident['photo_path']); ?>" alt="Photo">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <h2 style="margin:0; font-size:20px;"><?php echo esc($resident['full_name'] ?? $user['full_name'] ?? 'Resident'); ?></h2>
                    <p style="margin:4px 0 0; opacity:0.9; font-size:14px;"><?php echo esc($resident['address'] ?? 'Address not set'); ?></p>
                </div>
                <div class="info-card-body">
                    <?php if ($resident): ?>
                        <div class="info-row"><span class="label">Full Name</span><span class="value"><?php echo esc($resident['full_name']); ?></span></div>
                        <div class="info-row"><span class="label">Birth Date</span><span class="value"><?php echo esc($resident['birth_date'] ?? 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Gender</span><span class="value"><?php echo esc(ucfirst($resident['gender'] ?? 'N/A')); ?></span></div>
                        <div class="info-row"><span class="label">Civil Status</span><span class="value"><?php echo esc(ucfirst($resident['civil_status'] ?? 'N/A')); ?></span></div>
                        <div class="info-row"><span class="label">Contact</span><span class="value"><?php echo esc($resident['contact_number'] ?? 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Email</span><span class="value"><?php echo esc($resident['email'] ?? $user['email'] ?? 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Voter Status</span><span class="value"><?php echo esc(ucfirst($resident['voter_status'] ?? 'N/A')); ?></span></div>
                    <?php else: ?>
                        <p style="color: #6b7280;">Profile information is not yet linked to your account. Please visit the barangay office to update your resident record.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Document Request Form -->
            <div class="card" id="request-doc">
                <h2 class="card-title"><i class="fas fa-file-text"></i> Request Document</h2>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                    <div class="form-group">
                        <label for="doc_type">Document Type <span style="color:#c0392b;">*</span></label>
                        <select name="doc_type" id="doc_type" required>
                            <option value="">-- Select Document --</option>
                            <option value="Barangay Clearance">Barangay Clearance</option>
                            <option value="Certificate of Residency">Certificate of Residency</option>
                            <option value="Certificate of Indigency">Certificate of Indigency</option>
                            <option value="Business Clearance">Business Clearance</option>
                            <option value="CEDULA">Community Tax Certificate (CEDULA)</option>
                            <option value="Good Moral">Certificate of Good Moral</option>
                            <option value="First Time Job Seeker">First Time Job Seeker ID</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="purpose">Purpose <span style="color:#c0392b;">*</span></label>
                        <input type="text" name="purpose" id="purpose" placeholder="e.g., Employment, School requirement" required>
                    </div>
                    <div class="form-group">
                        <label for="remarks">Additional Remarks</label>
                        <textarea name="remarks" id="remarks" rows="3" placeholder="Any special instructions..."></textarea>
                    </div>
                    <button type="submit" name="submit_document" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </form>
            </div>
        </div>

        <!-- Webcam Photo Capture -->
        <div class="card mt-16" id="photo-capture">
            <h2 class="card-title"><i class="fas fa-camera"></i> Update Photo</h2>
            <p style="color: #6b7280; margin-top:-8px; margin-bottom:16px;">Take a new photo for your resident profile using your webcam.</p>
            <div class="webcam-container" id="webcam-box">
                <video id="webcam" autoplay playsinline style="display:none;"></video>
                <canvas id="webcam-canvas" style="display:none;"></canvas>
                <div class="webcam-placeholder" id="webcam-placeholder">
                    <i class="fas fa-video"></i>
                    <p>Camera preview will appear here</p>
                </div>
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:16px;">
                <button type="button" id="btn-start" class="btn btn-primary">
                    <i class="fas fa-video"></i> Start Camera
                </button>
                <button type="button" id="btn-capture" class="btn btn-secondary" disabled>
                    <i class="fas fa-camera"></i> Capture Photo
                </button>
                <button type="button" id="btn-retake" class="btn btn-danger" style="display:none;">
                    <i class="fas fa-redo"></i> Retake
                </button>
            </div>
            <form method="POST" action="" id="photo-form" style="display:none; margin-top:16px;">
                <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                <input type="hidden" name="photo_data" id="photo-data">
                <button type="submit" name="capture_photo" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Photo
                </button>
            </form>
        </div>

        <!-- Request History -->
        <div class="card mt-16" id="request-history">
            <h2 class="card-title"><i class="fas fa-history"></i> Request History</h2>
            <?php if (empty($requests)): ?>
                <p style="color: #6b7280;">You have no document requests yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Document</th>
                                <th>Purpose</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): 
                                $statusClass = match($req['status']) {
                                    'approved' => 'badge-success',
                                    'rejected' => 'badge-danger',
                                    'processing' => 'badge-warning',
                                    'pending' => 'badge-info',
                                    default => 'badge-info',
                                };
                            ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($req['requested_at'])); ?></td>
                                <td><?php echo esc($req['document_type']); ?></td>
                                <td><?php echo esc($req['purpose']); ?></td>
                                <td><span class="badge <?php echo $statusClass; ?>"><?php echo esc(ucfirst($req['status'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
    (function() {
        const video = document.getElementById('webcam');
        const canvas = document.getElementById('webcam-canvas');
        const ctx = canvas.getContext('2d');
        const btnStart = document.getElementById('btn-start');
        const btnCapture = document.getElementById('btn-capture');
        const btnRetake = document.getElementById('btn-retake');
        const placeholder = document.getElementById('webcam-placeholder');
        const photoForm = document.getElementById('photo-form');
        const photoDataInput = document.getElementById('photo-data');
        let stream = null;

        btnStart.addEventListener('click', async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { width: 480, height: 360 } });
                video.srcObject = stream;
                video.style.display = 'block';
                placeholder.style.display = 'none';
                canvas.style.display = 'none';
                btnStart.style.display = 'none';
                btnCapture.disabled = false;
                btnRetake.style.display = 'none';
            } catch (err) {
                alert('Camera access denied or unavailable. Please allow camera permissions first and try again.');
                console.error(err);
            }
        });

        btnCapture.addEventListener('click', () => {
            canvas.width = video.videoWidth || 480;
            canvas.height = video.videoHeight || 360;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const data = canvas.toDataURL('image/jpeg', 0.9);
            photoDataInput.value = data;

            video.style.display = 'none';
            canvas.style.display = 'block';
            btnCapture.style.display = 'none';
            btnRetake.style.display = 'inline-flex';

            if (stream) {
                stream.getTracks().forEach(t => t.stop());
                stream = null;
            }
        });

        btnRetake.addEventListener('click', () => {
            photoDataInput.value = '';
            canvas.style.display = 'none';
            placeholder.style.display = 'none';
            video.style.display = 'block';
            btnRetake.style.display = 'none';
            btnStart.style.display = 'none';
            btnCapture.disabled = false;
            btnStart.click();
        });

        window.addEventListener('beforeunload', () => {
            if (stream) {
                stream.getTracks().forEach(t => t.stop());
            }
        });
    })();
    </script>
    </div>
<script src="assets/js/main.js"></script>
</body>
</html>
