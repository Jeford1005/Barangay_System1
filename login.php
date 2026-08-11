<?php
require_once __DIR__ . '/config.php';
redirect_if_authenticated();
$csrf_token = generate_csrf_token();

// Handle AJAX login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $response = ['status' => 'error', 'message' => 'Invalid request.'];
    
    try {
        $inputRole = $_POST['role'] ?? 'administrator';
        $inputUsername = trim($_POST['username'] ?? '');
        $inputPassword = $_POST['password'] ?? '';
        $postCsrf = $_POST['csrf_token'] ?? '';
        
        if (!verify_csrf_token($postCsrf)) {
            $response['message'] = 'Invalid security token. Please refresh and try again.';
            echo json_encode($response);
            exit;
        }
        
        if (empty($inputUsername) || empty($inputPassword)) {
            $response['message'] = 'Please enter both username and password.';
            echo json_encode($response);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT id, username, email, password, full_name, role, status, twofa_secret, resident_id
            FROM users
            WHERE (username = :username OR email = :email)
            AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([':username' => $inputUsername, ':email' => $inputUsername]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($inputPassword, $user['password'])) {
            log_audit('login_failed', 'Auth', null, null, ['username' => $inputUsername, 'role' => $inputRole]);
            $response['message'] = 'Invalid username or password. Please try again.';
            echo json_encode($response);
            exit;
        }
        
        $roleMap = [
            'administrator' => ['admin', 'staff'],
            'resident' => ['resident']
        ];
        $allowedRoles = $roleMap[$inputRole] ?? [];
        
        if (!in_array($user['role'], $allowedRoles)) {
            $roleLabels = [
                'administrator' => 'Administrator/Staff',
                'resident' => 'Resident'
            ];
            $response['message'] = 'Access denied. This account is not authorized for ' . ($roleLabels[$inputRole] ?? $inputRole) . ' access.';
            echo json_encode($response);
            exit;
        }
        
        if (!empty($user['twofa_secret'])) {
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_role'] = $user['role'];
            $_SESSION['pending_2fa_full_name'] = $user['full_name'];
            echo json_encode([
                'status' => '2fa_required',
                'message' => 'Two-factor authentication required.',
                'redirect' => BASE_URL . '/2fa-verify.php'
            ]);
            exit;
        }
        
        secure_session_regenerate();
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['ua_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
        
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")->execute([':id' => $user['id']]);
        log_audit('login', 'user', $user['id']);
        
        $redirectUrl = ($user['role'] === 'admin' || $user['role'] === 'staff')
            ? BASE_URL . '/dashboard.php'
            : BASE_URL . '/resident-dashboard.php';
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful! Redirecting...',
            'redirect' => $redirectUrl
        ]);
        exit;
    } catch (Exception $e) {
        error_log('Login Error: ' . $e->getMessage());
        $response['message'] = 'An error occurred. Please try again later.';
        echo json_encode($response);
        exit;
    }
}

// Handle Forgot Password AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request.'];
    
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token. Please refresh and try again.';
        echo json_encode($response);
        exit;
    }
    
    $identifier = trim($_POST['identifier'] ?? '');
    $contactMethod = $_POST['contact_method'] ?? 'email';
    
    if (empty($identifier)) {
        $response['message'] = 'Please enter your username or email address.';
        echo json_encode($response);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT id, username, email, full_name, role, phone_number, twofa_secret
        FROM users
        WHERE (username = :identifier1 OR email = :identifier2)
        AND status = 'active'
        AND role IN ('admin', 'staff', 'resident')
        LIMIT 1
    ");
    $stmt->execute([':identifier1' => $identifier, ':identifier2' => $identifier]);
    $user = $stmt->fetch();
    
    if ($user) {
        $resetToken = bin2hex(random_bytes(32));
        $resetExpiry = date('Y-m-d H:i:s', time() + 3600);
        $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?")->execute([$resetToken, $resetExpiry, $user['id']]);
        
        if ($contactMethod === 'email' && $user['email']) {
            $isLocal = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1');
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $resetUrl = $protocol . $_SERVER['HTTP_HOST'] . BASE_URL . '/reset-password.php?token=' . $resetToken;
            $subject = 'Password Reset - ' . APP_NAME;
            $message = "
                <html><body>
                    <h2>Password Reset Request</h2>
                    <p>Hello {$user['full_name']},</p>
                    <p>You requested a password reset for your Barangay Bidduang Management Portal account.</p>
                    <p><a href='$resetUrl' style='background:#1a5c38;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;'>Reset Password</a></p>
                    <p>This link expires in 1 hour.</p>
                    <p>If you didn't request this, please ignore this email.</p>
                </body></html>";
            $headers = "From: " . ADMIN_EMAIL . "\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            if (!mail($user['email'], $subject, $message, $headers)) {
                error_log("Password reset email failed to send to {$user['email']}. Reset URL: {$resetUrl}");
            }
        }
        
        if ($contactMethod === 'sms' && !empty($user['phone_number'])) {
            $resetCode = substr($resetToken, 0, 6);
            $pdo->prepare("UPDATE users SET reset_code = ?, reset_code_expiry = ? WHERE id = ?")->execute([$resetCode, $resetExpiry, $user['id']]);

            require_once __DIR__ . '/lib/sms/SmsTriggers.php';
            require_once __DIR__ . '/lib/sms/SmsLogger.php';
            require_once __DIR__ . '/lib/sms/SmsService.php';
            require_once __DIR__ . '/lib/sms/SemaphoreSmsProvider.php';
            try {
                SmsTriggers::sendNow(
                    $user['phone_number'],
                    'PasswordReset',
                    "Your Barangay Bidduang password reset code is: $resetCode. Expires in 1 hour."
                );
            } catch (Throwable $e) {
                error_log('SMS reset code failed: ' . $e->getMessage());
            }
        }
        
        log_audit('password_reset_request', 'user', $user['id'], null, ['method' => $contactMethod]);
    }
    
    $response = [
        'status' => 'success',
        'message' => 'If the account exists, a password reset link has been sent to your email/SMS.'
    ];
    
    if ($user && $resetToken) {
        $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1');
        if ($isLocalDev) {
            $response['debug_reset_url'] = 'http://localhost' . BASE_URL . '/reset-password.php?token=' . $resetToken;
        }
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
    <title>Sign In - Barangay Bidduang</title>
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/css/login.css?v=<?= filemtime(__DIR__ . '/assets/css/login.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <style>
        .auth-container { min-height: 100vh; display: flex; }
        .auth-left, .auth-right { display: flex; flex-direction: column; }
        .form-group input, .password-wrapper input, .form-group select { min-height: 52px; }
        .btn-submit, .btn-outline, .role-btn { min-height: 52px; }
        @media (max-width: 1024px) {
            html, body { overflow: auto !important; height: auto !important; }
            .auth-container { display: block; min-height: 100vh; }
            .auth-left, .auth-right { height: auto; display: block; }
        }
    </style>
</head>
<body>
    <div class="auth-container" id="authContainer">

        <!-- Left: Branding / Identity Panel -->
        <div class="auth-left">
            <div class="brand-content">
                <div class="seal-circle">
                    <img src="assets/img/Brgy_Bidduang.png" alt="Barangay Bidduang Seal">
                </div>
                <h1>BARANGAY BIDDUANG</h1>
                <div class="brand-sub">Barangay Records and Reporting Management System</div>

                <div class="feature-grid">
                    <div class="feature-badge"><i class="fas fa-shield-alt"></i><span>Secure Access</span></div>
                    <div class="feature-badge"><i class="fas fa-users"></i><span>Reliable Records</span></div>
                    <div class="feature-badge"><i class="fas fa-file-alt"></i><span>Accurate Reports</span></div>
                    <div class="feature-badge"><i class="fas fa-hand-holding-heart"></i><span>Better Service</span></div>
                </div>

                <div class="tagline-banner">"Makabagong pamamahala, maasahang serbisyo."</div>

                <div class="security-footer">
                    <i class="fas fa-lock"></i>
                    <span>Your data is protected and encrypted.</span>
                </div>
            </div>
        </div>

        <!-- Right: Authentication Forms -->
        <div class="auth-right">
            <!-- Login View -->
            <div class="form-panel login-panel" id="loginPanel">
                <div class="form-header">
                    <h2><i class="fas fa-sign-in-alt"></i> Welcome Back!</h2>
                    <p>Sign in to continue to your account</p>
                </div>

                <div class="role-switcher">
                    <button type="button" class="role-btn active" data-role="administrator" id="roleAdmin">
                        <i class="fas fa-user-shield"></i> Administrator
                    </button>
                    <button type="button" class="role-btn" data-role="resident" id="roleResident">
                        <i class="fas fa-user"></i> Resident
                    </button>
                </div>

                <form id="loginForm" novalidate>
                    <input type="hidden" name="role" value="administrator" id="loginRoleInput">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token); ?>">

                    <div class="form-group">
                        <label for="loginUsername"><i class="fas fa-user"></i> Username / Email</label>
                        <input type="text" id="loginUsername" name="username" required
                               placeholder="Enter your username or email" autocomplete="username">
                        <span class="error-msg" id="loginUsernameError"></span>
                    </div>

                    <div class="form-group">
                        <label for="loginPassword"><i class="fas fa-lock"></i> Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="loginPassword" name="password" required
                                   placeholder="Enter your password" autocomplete="current-password">
                            <button type="button" class="toggle-password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="error-msg" id="loginPasswordError"></span>
                    </div>

                    <button type="submit" class="btn-submit" id="loginSubmitBtn">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>

                    <div class="form-divider">or</div>

                    <button type="button" class="btn-outline" id="showRegisterBtn">
                        Create Account <i class="fas fa-arrow-right"></i>
                    </button>

                    <p style="margin-top: .9rem; text-align:center;">
                        <button type="button" class="link-btn" id="showForgotBtn">
                            <i class="fas fa-key"></i> Forgot Password?
                        </button>
                    </p>

                    <div class="security-callout">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <h4>Secure &amp; Confidential</h4>
                            <p>This system is for authorized personnel only. All activities are monitored and recorded.</p>
                        </div>
                    </div>

                    <div class="alert" id="loginAlert" style="display:none;"></div>
                </form>
            </div>
        </div>

        <!-- Forgot Password Overlay -->
        <div class="forgot-overlay" id="forgotOverlay">
            <div class="forgot-panel">
                <div class="forgot-header">
                    <h2><i class="fas fa-key"></i> Forgot Password?</h2>
                    <p>Enter your username or email to receive a password reset link.</p>
                    <button type="button" class="close-forgot" id="closeForgotBtn" aria-label="Close"><i class="fas fa-times"></i></button>
                </div>
                <form id="forgotForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="forgot_password">
                    <div class="form-group">
                        <label for="forgotIdentifier"><i class="fas fa-user"></i> Username or Email</label>
                        <input type="text" id="forgotIdentifier" name="identifier" required
                               placeholder="Enter your username or email" autocomplete="username">
                        <span class="error-msg" id="forgotIdentifierError"></span>
                    </div>
                    <div class="form-group">
                        <label>Reset Method</label>
                        <div class="reset-method-options" style="display:flex; gap:.5rem;">
                            <label class="radio-option" style="flex:1; display:flex; align-items:center; gap:.4rem; border:1.5px solid #E2E8F0; border-radius:12px; padding:.6rem .8rem; cursor:pointer;">
                                <input type="radio" name="contact_method" value="email" checked> <span><i class="fas fa-envelope"></i> Email</span>
                            </label>
                            <label class="radio-option" style="flex:1; display:flex; align-items:center; gap:.4rem; border:1.5px solid #E2E8F0; border-radius:12px; padding:.6rem .8rem; cursor:pointer;">
                                <input type="radio" name="contact_method" value="sms"> <span><i class="fas fa-sms"></i> SMS</span>
                            </label>
                        </div>
                        <span class="error-msg" id="contactMethodError"></span>
                    </div>
                    <button type="submit" class="btn-submit" id="forgotSubmitBtn">
                        <i class="fas fa-paper-plane"></i> Send Reset Link
                    </button>
                    <div class="form-footer">
                        <p>Remember your password?
                            <button type="button" class="link-btn" id="backToLoginBtn"><i class="fas fa-arrow-left"></i> Back to Login</button>
                        </p>
                    </div>
                </form>
                <div class="alert" id="forgotAlert" style="display:none;"></div>
            </div>
        </div>

        <!-- Register Overlay -->
        <div class="register-overlay" id="registerOverlay">
            <div class="register-panel">
                <div class="register-header">
                    <h2><i class="fas fa-user-plus"></i> Create Account</h2>
                    <p>Fill in the details to create your account</p>
                    <button type="button" class="close-register" id="closeRegisterBtn" aria-label="Close registration"><i class="fas fa-times"></i></button>
                </div>
                <form id="registerForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token); ?>">
                    <div class="register-grid">
                        <div class="form-group">
                            <label for="regFullName"><i class="fas fa-id-card"></i> Full Name</label>
                            <input type="text" id="regFullName" name="full_name" required placeholder="Enter your full name" autocomplete="name">
                            <span class="error-msg" id="regFullNameError"></span>
                        </div>
                        <div class="form-group">
                            <label for="regUsername"><i class="fas fa-user"></i> Username</label>
                            <input type="text" id="regUsername" name="username" required placeholder="Choose a username" autocomplete="username">
                            <span class="error-msg" id="regUsernameError"></span>
                        </div>
                        <div class="form-group">
                            <label for="regEmail"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="regEmail" name="email" required placeholder="Example@Gmail.Com" autocomplete="email">
                            <span class="error-msg" id="regEmailError"></span>
                        </div>
                        <div class="form-group">
                            <label for="regPhone"><i class="fas fa-phone"></i> Phone Number</label>
                            <input type="tel" id="regPhone" name="phone" required placeholder="09XXXXXXXXX" autocomplete="tel">
                            <span class="error-msg" id="regPhoneError"></span>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="regAddress"><i class="fas fa-map-marker-alt"></i> Address</label>
                            <input type="text" id="regAddress" name="address" required placeholder="Your residential address" autocomplete="street-address">
                            <span class="error-msg" id="regAddressError"></span>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="regRole"><i class="fas fa-user-tag"></i> Position / Role</label>
                            <select id="regRole" name="role" required>
                                <option value="">Select position / role</option>
                                <option value="resident">Resident</option>
                                <option value="staff">Staff</option>
                                <option value="official">Barangay Official</option>
                            </select>
                            <span class="error-msg" id="regRoleError"></span>
                        </div>
                        <div class="form-group">
                            <label for="regPassword"><i class="fas fa-lock"></i> Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="regPassword" name="password" required placeholder="Create a password" autocomplete="new-password">
                                <button type="button" class="toggle-password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                            <span class="error-msg" id="regPasswordError"></span>
                        </div>
                        <div class="form-group">
                            <label for="regConfirmPassword"><i class="fas fa-circle-check"></i> Confirm Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="regConfirmPassword" name="confirm_password" required placeholder="Repeat your password" autocomplete="new-password">
                                <button type="button" class="toggle-password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                            <span class="error-msg" id="regConfirmPasswordError"></span>
                        </div>
                    </div>

                    <div class="pw-strength">
                        <strong>Password must contain:</strong>
                        <ul>
                            <li><i class="fas fa-check-circle"></i> At least 8 characters</li>
                            <li><i class="fas fa-check-circle"></i> Uppercase &amp; lowercase letters</li>
                            <li><i class="fas fa-check-circle"></i> At least one number</li>
                            <li><i class="fas fa-check-circle"></i> At least one special character</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn-submit" id="registerSubmitBtn">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>

                    <div class="form-footer">
                        <p>Already have an account?
                            <button type="button" class="link-btn" id="showLoginBtn"><i class="fas fa-arrow-left"></i> Sign In</button>
                        </p>
                    </div>
                </form>
                <div class="alert" id="registerAlert" style="display:none;"></div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const roleAdmin = document.getElementById('roleAdmin');
        const roleResident = document.getElementById('roleResident');
        const loginRoleInput = document.getElementById('loginRoleInput');
        [roleAdmin, roleResident].forEach(btn => {
            if (btn) btn.addEventListener('click', function() {
                roleAdmin.classList.remove('active');
                roleResident.classList.remove('active');
                this.classList.add('active');
                loginRoleInput.value = this.dataset.role;
            });
        });

        // Forgot
        const forgotBtn = document.getElementById('showForgotBtn');
        const forgotOverlay = document.getElementById('forgotOverlay');
        const closeForgotBtn = document.getElementById('closeForgotBtn');
        const backToLoginBtn = document.getElementById('backToLoginBtn');
        const forgotForm = document.getElementById('forgotForm');
        const forgotAlert = document.getElementById('forgotAlert');
        function showForgot(){ document.getElementById('loginPanel').style.display='none'; forgotOverlay.classList.add('active'); }
        function hideForgot(){ forgotOverlay.classList.remove('active'); setTimeout(()=>{ if(!forgotOverlay.classList.contains('active')) document.getElementById('loginPanel').style.display='block'; },300); forgotAlert.style.display='none'; forgotForm.reset(); }
        forgotBtn?.addEventListener('click', showForgot);
        closeForgotBtn?.addEventListener('click', hideForgot);
        backToLoginBtn?.addEventListener('click', hideForgot);
        forgotForm?.addEventListener('submit', function(e){
            e.preventDefault();
            const identifier = document.getElementById('forgotIdentifier').value.trim();
            const contactMethod = document.querySelector('input[name="contact_method"]:checked')?.value || 'email';
            if (!identifier){ showAlert(forgotAlert,'Please enter your username or email.','error'); return; }
            const submitBtn = document.getElementById('forgotSubmitBtn');
            submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            const fd = new FormData();
            fd.append('action','forgot_password'); fd.append('identifier',identifier); fd.append('contact_method',contactMethod);
            fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            fetch(window.location.href, { method:'POST', body: fd })
                .then(r=>r.json()).then(data=>{
                    submitBtn.disabled=false; submitBtn.innerHTML='<i class="fas fa-paper-plane"></i> Send Reset Link';
                    if (data.status==='success'){
                        var cfg={icon:'success',title:'Reset Link Sent!',text:data.message,confirmButtonColor:'#1E5631'};
                        if (data.debug_reset_url) cfg.footer='<a href="'+data.debug_reset_url+'" style="color:#1E5631;">Click here to reset password (local dev)</a>';
                        Swal.fire(cfg).then(()=>hideForgot());
                    } else showAlert(forgotAlert,data.message,'error');
                }).catch(()=>{ submitBtn.disabled=false; submitBtn.innerHTML='<i class="fas fa-paper-plane"></i> Send Reset Link'; showAlert(forgotAlert,'An error occurred. Please try again.','error'); });
        });

        // Register
        const showRegisterBtn = document.getElementById('showRegisterBtn');
        const registerOverlay = document.getElementById('registerOverlay');
        const closeRegisterBtn = document.getElementById('closeRegisterBtn');
        const showLoginBtn = document.getElementById('showLoginBtn');
        const registerForm = document.getElementById('registerForm');
        const registerAlert = document.getElementById('registerAlert');
        function showRegister(){ document.getElementById('loginPanel').style.display='none'; registerOverlay.classList.add('active'); }
        function hideRegister(){ registerOverlay.classList.remove('active'); document.getElementById('loginPanel').style.display='block'; registerAlert.style.display='none'; registerForm?.reset(); }
        showRegisterBtn?.addEventListener('click', showRegister);
        closeRegisterBtn?.addEventListener('click', hideRegister);
        showLoginBtn?.addEventListener('click', hideRegister);
        registerForm?.addEventListener('submit', function(e){
            e.preventDefault();
            const submitBtn = document.getElementById('registerSubmitBtn');
            submitBtn.disabled=true; submitBtn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Creating...';
            const fd = new FormData(registerForm);
            fetch(window.location.href, { method:'POST', body: fd })
                .then(r=>r.json()).then(data=>{
                    submitBtn.disabled=false; submitBtn.innerHTML='<i class="fas fa-user-plus"></i> Create Account';
                    if (data.status==='success'){
                        Swal.fire({icon:'success',title:'Account Created!',text:data.message,confirmButtonColor:'#1E5631'}).then(()=>hideRegister());
                    } else showAlert(registerAlert,data.message,'error');
                }).catch(()=>{ submitBtn.disabled=false; submitBtn.innerHTML='<i class="fas fa-user-plus"></i> Create Account'; showAlert(registerAlert,'An error occurred. Please try again.','error'); });
        });

        function showAlert(element, message, type){
            element.textContent = message;
            element.className = 'alert alert-'+type;
            element.style.display = 'block';
        }
    });
    </script>
</body>
</html>
