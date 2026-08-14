<?php
require_once __DIR__ . '/config.php';

// Check if there's a pending 2FA verification
if (!isset($_SESSION['pending_2fa_user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = $_SESSION['pending_2fa_user_id'];
$csrf_token = generate_csrf_token();

// Fetch user info
$stmt = $pdo->prepare("SELECT id, username, email, full_name, role, twofa_secret FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    unset($_SESSION['pending_2fa_user_id']);
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Handle 2FA verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request.'];
    
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token.';
        echo json_encode($response);
        exit;
    }
    
    $code = trim($_POST['totp_code'] ?? '');
    
    if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
        $response['message'] = 'Please enter a valid 6-digit code.';
        echo json_encode($response);
        exit;
    }
    
    // Verify TOTP code
    require_once __DIR__ . '/lib/TOTP.php'; // We'll create this
    $totp = new TOTP($user['twofa_secret']);
    
    if ($totp->verify($code)) {
        // 2FA verified - complete login
        secure_session_regenerate();
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['ua_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
        
        // Update last login
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
        
        // Audit log
        log_audit('login', 'user', $user['id']);
        
        // Clear pending 2FA session
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_role'], $_SESSION['pending_2fa_full_name']);
        
        $response = [
            'status' => 'success',
            'message' => 'Two-factor authentication verified! Redirecting...',
            'redirect' => BASE_URL . '/dashboard.php'
        ];
    } else {
        $response['message'] = 'Invalid or expired code. Please try again.';
    }
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - Barangay Bidduang</title>
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
</head>
<body>
    <div class="auth-container" id="authContainer">
        <div class="auth-left">
            <div class="halftone-bg">
                <div class="stripe-overlay"></div>
            </div>
            <div class="brand-content">
                <div class="seal-circle">
                    <img src="assets/img/Brgy_Bidduang.png" alt="Barangay Bidduang Seal" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                </div>
                <h1>Barangay Bidduang</h1>
                <p>Two-Factor Authentication Required</p>
                <div class="brand-icons">
                    <i class="fas fa-shield-alt"></i>
                    <i class="fas fa-lock"></i>
                    <i class="fas fa-mobile-alt"></i>
                </div>
            </div>
        </div>

        <div class="auth-right">
            <div class="form-panel login-panel" id="totpPanel">
                <div class="form-header">
                    <h2><i class="fas fa-shield-alt"></i> Two-Factor Authentication</h2>
                    <p>Enter the 6-digit code from your authenticator app.</p>
                </div>

                <div class="user-info" style="text-align:center;margin-bottom:20px;padding:15px;background:#f5f7fa;border-radius:8px;">
                    <div class="avatar" style="margin:0 auto 10px;width:60px;height:60px;font-size:24px;">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <strong id="userName"><?= esc($user['full_name']) ?></strong><br>
                    <small class="text-muted">@<?= esc($user['username']) ?> (<?= ucfirst($user['role']) ?>)</small>
                </div>

                <form id="totpForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="form-group">
                        <label for="totpCode">
                            <i class="fas fa-mobile-alt"></i> 6-Digit Code
                        </label>
                        <div class="totp-input-wrapper">
                            <input type="text" id="totpCode" name="totp_code" required
                                   placeholder="000000" autocomplete="one-time-code" maxlength="6"
                                   inputmode="numeric" pattern="\d{6}" style="letter-spacing:8px;text-align:center;font-size:24px;font-weight:bold;">
                        </div>
                        <span class="error-msg" id="totpCodeError"></span>
                    </div>

                    <div class="totp-hint">
                        <i class="fas fa-info"></i>
                        <span>Enter the 6-digit code from your authenticator app (Google Authenticator, Authy, Microsoft Authenticator, etc.)</span>
                    </div>

                    <button type="submit" class="btn-submit" id="totpSubmitBtn" style="width:100%;margin-top:20px;">
                        <i class="fas fa-check"></i> Verify & Sign In
                    </button>

                    <div class="form-footer" class="mt-20">
                        <button type="button" class="link-btn" id="resendCodeBtn">
                            <i class="fas fa-sync-alt"></i> Resend Code (if using email/SMS backup)
                        </button>
                    </div>
                </form>

                <div class="alert" id="totpAlert" style="display:none;"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script src="assets/js/main.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('totpForm');
        const codeInput = document.getElementById('totpCode');
        const submitBtn = document.getElementById('totpSubmitBtn');
        const alertBox = document.getElementById('totpAlert');
        
        // Auto-format TOTP input
        codeInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value.length === 6) {
                submitBtn.focus();
            }
        });
        
        // Auto-submit on 6 digits (optional)
        codeInput.addEventListener('keyup', function() {
            if (this.value.length === 6) {
                // form.submit(); // Uncomment to auto-submit
            }
        });
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const code = codeInput.value.trim();
            
            if (!code || code.length !== 6 || !/^\d{6}$/.test(code)) {
                showAlert('Please enter a valid 6-digit code.', 'error');
                codeInput.focus();
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            
            const formData = new FormData();
            formData.append('totp_code', code);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check"></i> Verify & Sign In';
                
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Verified!',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = data.redirect;
                    });
                } else {
                    showAlert(data.message, 'error');
                    codeInput.value = '';
                    codeInput.focus();
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check"></i> Verify & Sign In';
                showAlert('An error occurred. Please try again.', 'error');
            });
        });
        
        function showAlert(message, type) {
            const alertBox = document.getElementById('totpAlert');
            alertBox.textContent = message;
            alertBox.className = 'alert alert-' + type;
            alertBox.style.display = 'block';
        }
        
        // Focus on input
        document.getElementById('totpCode').focus();
    });
    </script>
</body>
</html>