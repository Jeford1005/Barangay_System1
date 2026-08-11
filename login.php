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
        
        // Build query: match username OR email
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
        
        // Role check: strict role matching
        $selectedRole = $inputRole;
        $userRole = $user['role'];
        
        // Map role values
        $roleMap = [
            'administrator' => ['admin', 'staff'],
            'resident' => ['resident']
        ];
        
        $allowedRoles = $roleMap[$selectedRole] ?? [];
        
        if (!in_array($userRole, $allowedRoles)) {
            $roleLabels = [
                'administrator' => 'Administrator/Staff',
                'resident' => 'Resident'
            ];
            $response['message'] = 'Access denied. This account is not authorized for ' . ($roleLabels[$selectedRole] ?? $selectedRole) . ' access.';
            echo json_encode($response);
            exit;
        }
        
        // Check if 2FA is enabled for this user
        if (!empty($user['twofa_secret'])) {
            // Store pending 2FA verification
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_role'] = $user['role'];
            $_SESSION['pending_2fa_full_name'] = $user['full_name'];
            
            $response = [
                'status' => '2fa_required',
                'message' => 'Two-factor authentication required.',
                'redirect' => BASE_URL . '/2fa-verify.php'
            ];
            echo json_encode($response);
            exit;
        }
        
        // Successful login - set session
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
    
    $identifier = trim($_POST['identifier'] ?? ''); // username or email
    $contactMethod = $_POST['contact_method'] ?? 'email'; // email or sms
    
    if (empty($identifier)) {
        $response['message'] = 'Please enter your username or email address.';
        echo json_encode($response);
        exit;
    }
    
    // Find user
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
    
    // Always return success to prevent user enumeration
    if ($user) {
        // Generate reset token (valid for 1 hour)
        $resetToken = bin2hex(random_bytes(32));
        $resetExpiry = date('Y-m-d H:i:s', time() + 3600);
        
        $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
        $stmt->execute([$resetToken, $resetExpiry, $user['id']]);
        
        // Send reset link via email
        if ($contactMethod === 'email' && $user['email']) {
            // Build absolute reset URL
            $isLocal = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || $_SERVER['HTTP_HOST'] === '127.0.0.1');
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $resetUrl = $protocol . $_SERVER['HTTP_HOST'] . BASE_URL . '/reset-password.php?token=' . $resetToken;
            
            $subject = 'Password Reset - ' . APP_NAME;
            $message = "
                <html>
                <body>
                    <h2>Password Reset Request</h2>
                    <p>Hello {$user['full_name']},</p>
                    <p>You requested a password reset for your Barangay Bidduang Management Portal account.</p>
                    <p><a href='$resetUrl' style='background:#1a5c38;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;'>Reset Password</a></p>
                    <p>This link expires in 1 hour.</p>
                    <p>If you didn't request this, please ignore this email.</p>
                    <hr>
                    <p style='font-size:12px;color:#666;'>Barangay Bidduang Management Portal</p>
                </body>
                </html>
            ";
            
            $headers = "From: " . ADMIN_EMAIL . "\r\n";
            $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            // Send email
            $mailSent = mail($user['email'], $subject, $message, $headers);
            
            if (!$mailSent) {
                // Log the reset URL for local development/debugging
                // since mail() may not work on XAMPP without a mail server
                error_log("Password reset email failed to send to {$user['email']}. Reset URL: {$resetUrl}");
            }
        }
        
        // Send reset code via SMS (if phone_number exists)
        if ($contactMethod === 'sms' && !empty($user['phone_number'])) {
            $resetCode = substr($resetToken, 0, 6);
            $stmt = $pdo->prepare("UPDATE users SET reset_code = ?, reset_code_expiry = ? WHERE id = ?");
            $stmt->execute([$resetCode, $resetExpiry, $user['id']]);
            
            // TODO: Integrate with SMS provider (e.g., Twilio, Semaphore, etc.)
            // sendSMS($user['phone_number'], "Your Barangay Portal reset code: $resetCode. Expires in 1 hour.");
        }
        
        log_audit('password_reset_request', 'user', $user['id'], null, ['method' => $contactMethod]);
    }
    
    // Always return success to prevent user enumeration
    $response = [
        'status' => 'success',
        'message' => 'If the account exists, a password reset link has been sent to your email/SMS.'
    ];
    
    // In local development, include the reset URL for testing
    // since mail() may not be configured on XAMPP
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
    <link rel="stylesheet" href="assets/css/login-fix.css?v=<?= filemtime(__DIR__ . '/assets/css/login-fix.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <style>
        /* Fix height issues */
        .auth-container {
            min-height: 100vh;
            height: 100vh;
            display: flex;
        }
        .auth-left, .auth-right {
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .auth-left {
            flex: 0 0 50%;
            justify-content: center;
        }
        .auth-right {
            flex: 0 0 50%;
            justify-content: center;
        }
        .form-panel {
            width: 100%;
            max-width: 440px;
        }
        /* Ensure form elements have proper height */
        .form-group input, .password-wrapper input {
            min-height: 52px;
        }
        .btn-submit {
            min-height: 52px;
        }
        .role-btn {
            min-height: 48px;
        }
    </style>
</head>
<body>
    <div class="auth-container" id="authContainer">
        <!-- Left Panel: Branding -->
        <div class="auth-left">
            <div class="halftone-bg">
                <div class="stripe-overlay"></div>
            </div>
            <div class="brand-content">
                <div class="seal-circle">
                    <img src="assets/img/Brgy_Bidduang.png" alt="Barangay Bidduang Seal" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                </div>
                <h1>Barangay Bidduang</h1>
                <p>Secure access to barangay services and records.</p>
                <div class="brand-icons">
                    <i class="fas fa-shield-alt"></i>
                    <i class="fas fa-lock"></i>
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
        </div>

        <!-- Right Panel: Forms -->
        <div class="auth-right">
            <!-- Login Form -->
            <div class="form-panel login-panel" id="loginPanel">
                <div class="form-header">
                </div>

                <!-- Role Switcher -->
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
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="form-group">
                        <label for="loginUsername">
                            <i class="fas fa-user"></i> Username / Email
                        </label>
                        <input type="text" id="loginUsername" name="username" required
                               placeholder="Enter your username or email" autocomplete="username">
                        <span class="error-msg" id="loginUsernameError"></span>
                    </div>

                    <div class="form-group">
                        <label for="loginPassword">
                            <i class="fas fa-lock"></i> Password
                        </label>
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

                    <div class="form-footer">
                        <p>Don't have an account? 
                            <button type="button" class="link-btn" id="showRegisterBtn">
                                <i class="fas fa-user-plus"></i> Create Account
                            </button>
                        </p>
                        <p style="margin-top: 0.75rem;">
                            <button type="button" class="link-btn" id="showForgotBtn">
                                <i class="fas fa-key"></i> Forgot Password?
                            </button>
                        </p>
                    </div>
                </form>

                <div class="alert" id="loginAlert" style="display:none;"></div>
            </div>
        </div>

        <!-- Forgot Password Overlay -->
        <div class="forgot-overlay" id="forgotOverlay">
            <div class="forgot-panel">
                <div class="forgot-header">
                    <h2><i class="fas fa-key"></i> Forgot Password?</h2>
                    <p>Enter your username or email to receive a password reset link.</p>
                    <button type="button" class="close-forgot" id="closeForgotBtn" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form id="forgotForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="forgot_password">

                    <div class="form-group">
                        <label for="forgotIdentifier">
                            <i class="fas fa-user"></i> Username or Email
                        </label>
                        <input type="text" id="forgotIdentifier" name="identifier" required
                               placeholder="Enter your username or email" autocomplete="username">
                        <span class="error-msg" id="forgotIdentifierError"></span>
                    </div>

                    <div class="form-group">
                        <label>Reset Method</label>
                        <div class="reset-method-options">
                            <label class="radio-option">
                                <input type="radio" name="contact_method" value="email" checked>
                                <span><i class="fas fa-envelope"></i> Email</span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="contact_method" value="sms">
                                <span><i class="fas fa-sms"></i> SMS</span>
                            </label>
                        </div>
                        <span class="error-msg" id="contactMethodError"></span>
                    </div>

                    <button type="submit" class="btn-submit" id="forgotSubmitBtn">
                        <i class="fas fa-paper-plane"></i> Send Reset Link
                    </button>

                    <div class="form-footer">
                        <p>Remember your password? 
                            <button type="button" class="link-btn" id="backToLoginBtn">
                                <i class="fas fa-arrow-left"></i> Back to Login
                            </button>
                        </p>
                    </div>
                </form>

                <div class="alert" id="forgotAlert" style="display:none;"></div>
            </div>
        </div>

        <!-- Register Overlay (slides in from right) -->
        <div class="register-overlay" id="registerOverlay">
            <div class="register-panel">
                <div class="register-header">
                    <h2><i class="fas fa-user-plus"></i> Resident Registration</h2>
                    <p>Create your resident account to access services.</p>
                    <button type="button" class="close-register" id="closeRegisterBtn" aria-label="Close registration">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form id="registerForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="form-group">
                        <label for="regFullName">
                            <i class="fas fa-id-card"></i> Full Name
                        </label>
                        <input type="text" id="regFullName" name="full_name" required
                               placeholder="Enter your full name" autocomplete="name">
                        <span class="error-msg" id="regFullNameError"></span>
                    </div>

                    <div class="form-group">
                        <label for="regEmail">
                            <i class="fas fa-envelope"></i> Email Address
                        </label>
                        <input type="email" id="regEmail" name="email" required
                               placeholder="Example@Gmail.Com" autocomplete="email">
                        <span class="error-msg" id="regEmailError"></span>
                    </div>

                    <div class="form-group">
                        <label for="regPhone">
                            <i class="fas fa-phone"></i> Phone Number
                        </label>
                        <input type="tel" id="regPhone" name="phone" required
                               placeholder="09XXXXXXXXX" autocomplete="tel">
                        <span class="error-msg" id="regPhoneError"></span>
                    </div>

                    <div class="form-group">
                        <label for="regAddress">
                            <i class="fas fa-map-marker-alt"></i> Address
                        </label>
                        <input type="text" id="regAddress" name="address" required
                               placeholder="Your residential address" autocomplete="street-address">
                        <span class="error-msg" id="regAddressError"></span>
                    </div>

                    <div class="form-group">
                        <label for="regUsername">
                            <i class="fas fa-user"></i> Username
                        </label>
                        <input type="text" id="regUsername" name="username" required
                               placeholder="Choose a username" autocomplete="username">
                        <span class="error-msg" id="regUsernameError"></span>
                    </div>

                    <div class="form-group">
                        <label for="regPassword">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" id="regPassword" name="password" required
                                   placeholder="Create a password" autocomplete="new-password">
                            <button type="button" class="toggle-password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="error-msg" id="regPasswordError"></span>
                    </div>

                    <div class="form-group">
                        <label for="regConfirmPassword">
                            <i class="fas fa-circle-check"></i> Confirm Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" id="regConfirmPassword" name="confirm_password" required
                                   placeholder="Repeat your password" autocomplete="new-password">
                            <button type="button" class="toggle-password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="error-msg" id="regConfirmPasswordError"></span>
                    </div>

                    <button type="submit" class="btn-submit" id="registerSubmitBtn">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>

                    <div class="form-footer">
                        <p>Already have an account? 
                            <button type="button" class="link-btn" id="showLoginBtn">
                                <i class="fas fa-sign-in-alt"></i> Sign In
                            </button>
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
    // Role switcher logic
    document.addEventListener('DOMContentLoaded', function() {
        const roleAdmin = document.getElementById('roleAdmin');
        const roleResident = document.getElementById('roleResident');
        const loginRoleInput = document.getElementById('loginRoleInput');
        
        // Role switcher logic
        [roleAdmin, roleResident].forEach(btn => {
            if (btn) {
                btn.addEventListener('click', function() {
                    roleAdmin.classList.remove('active');
                    roleResident.classList.remove('active');
                    this.classList.add('active');
                    loginRoleInput.value = this.dataset.role;
                });
            }
        });
        
        // Role validation on submit
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                const role = loginRoleInput.value;
                const username = document.getElementById('loginUsername').value.trim();
                // Client-side hint - actual validation is server-side
                if (role === 'administrator') {
                    // Server will validate role matches
                }
            });
        }
    });
    
    // Forgot password modal handling
    document.addEventListener('DOMContentLoaded', function() {
        const forgotBtn = document.getElementById('showForgotBtn');
        const forgotOverlay = document.getElementById('forgotOverlay');
        const closeForgotBtn = document.getElementById('closeForgotBtn');
        const backToLoginBtn = document.getElementById('backToLoginBtn');
        const forgotForm = document.getElementById('forgotForm');
        const forgotAlert = document.getElementById('forgotAlert');
        
        function showForgot() {
            document.getElementById('loginPanel').style.display = 'none';
            forgotOverlay.classList.add('active');
        }
        
        function hideForgot() {
            forgotOverlay.classList.remove('active');
            setTimeout(function() {
                if (!forgotOverlay.classList.contains('active')) {
                    document.getElementById('loginPanel').style.display = 'block';
                }
            }, 300);
            forgotAlert.style.display = 'none';
            forgotForm.reset();
        }
        
        forgotBtn?.addEventListener('click', showForgot);
        closeForgotBtn?.addEventListener('click', hideForgot);
        backToLoginBtn?.addEventListener('click', hideForgot);
        
        // Forgot form submission
        forgotForm?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const identifier = document.getElementById('forgotIdentifier').value.trim();
            const contactMethod = document.querySelector('input[name="contact_method"]:checked')?.value || 'email';
            
            if (!identifier) {
                showAlert(forgotAlert, 'Please enter your username or email.', 'error');
                return;
            }
            
            const submitBtn = document.getElementById('forgotSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            const formData = new FormData();
            formData.append('action', 'forgot_password');
            formData.append('identifier', identifier);
            formData.append('contact_method', contactMethod);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reset Link';
                
                if (data.status === 'success') {
                    var swalConfig = {
                        icon: 'success',
                        title: 'Reset Link Sent!',
                        text: data.message,
                        confirmButtonColor: '#1a5c38'
                    };
                    // In local dev, show the reset URL for testing
                    if (data.debug_reset_url) {
                        swalConfig.footer = '<a href="' + data.debug_reset_url + '" style="color:#1a5c38;">Click here to reset password (local dev)</a>';
                    }
                    Swal.fire(swalConfig).then(() => hideForgot());
                } else {
                    showAlert(forgotAlert, data.message, 'error');
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reset Link';
                showAlert(forgotAlert, 'An error occurred. Please try again.', 'error');
            });
        });
        
        function showAlert(element, message, type) {
            element.textContent = message;
            element.className = 'alert alert-' + type;
            element.style.display = 'block';
        }
    });
    
    // Register overlay handling
    document.addEventListener('DOMContentLoaded', function() {
        const showRegisterBtn = document.getElementById('showRegisterBtn');
        const registerOverlay = document.getElementById('registerOverlay');
        const closeRegisterBtn = document.getElementById('closeRegisterBtn');
        const showLoginBtn = document.getElementById('showLoginBtn');
        const registerForm = document.getElementById('registerForm');
        const registerAlert = document.getElementById('registerAlert');
        
        function showRegister() {
            document.getElementById('loginPanel').style.display = 'none';
            registerOverlay.style.display = 'flex';
        }
        
        function hideRegister() {
            registerOverlay.style.display = 'none';
            document.getElementById('loginPanel').style.display = 'block';
            registerAlert.style.display = 'none';
            registerForm?.reset();
        }
        
        showRegisterBtn?.addEventListener('click', showRegister);
        closeRegisterBtn?.addEventListener('click', hideRegister);
        showLoginBtn?.addEventListener('click', hideRegister);
        
        // Register form submission
        registerForm?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('registerSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
            
            const formData = new FormData(registerForm);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
                
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Account Created!',
                        text: data.message,
                        confirmButtonColor: '#1a5c38'
                    }).then(() => hideRegister());
                } else {
                    showAlert(registerAlert, data.message, 'error');
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
                showAlert(registerAlert, 'An error occurred. Please try again.', 'error');
            });
        });
        
        function showAlert(element, message, type) {
            element.textContent = message;
            element.className = 'alert alert-' + type;
            element.style.display = 'block';
        }
    });
    </script>
</body>
</html>