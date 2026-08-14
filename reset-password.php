<?php
require_once __DIR__ . '/config.php';

$csrf_token = generate_csrf_token();
$token = $_GET['token'] ?? '';
$message = '';
$messageType = '';
$tokenValid = false;
$userId = null;

// Validate token
if ($token) {
    $stmt = $pdo->prepare("
        SELECT id, username, email, full_name 
        FROM users 
        WHERE reset_token = ? 
        AND reset_token_expiry > NOW()
        AND role IN ('admin', 'staff')
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        $tokenValid = true;
        $userId = $user['id'];
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters.';
            $messageType = 'error';
        } elseif ($password !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $messageType = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password = ?, reset_token = NULL, reset_token_expiry = NULL, reset_code = NULL, reset_code_expiry = NULL
                WHERE id = ?
            ");
            $stmt->execute([$hash, $userId]);
            
            log_audit('password_reset', 'user', $userId);
            
            $message = 'Password has been reset successfully! You can now log in with your new password.';
            $messageType = 'success';
            $tokenValid = false; // Token consumed
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Barangay Bidduang</title>
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
                <p>Reset your password securely.</p>
                <div class="brand-icons">
                    <i class="fas fa-shield-alt"></i>
                    <i class="fas fa-lock"></i>
                    <i class="fas fa-key"></i>
                </div>
            </div>
        </div>

        <div class="auth-right">
            <div class="form-panel login-panel" id="resetPanel">
                <div class="form-header">
                    <h2><i class="fas fa-key"></i> Reset Password</h2>
                    <p><?= $tokenValid ? 'Enter your new password below.' : 'This reset link is invalid or has expired.' ?></p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?>" id="floatingAlert">
                        <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                        <span><?= esc($message) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($tokenValid): ?>
                <form id="resetForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="token" value="<?= esc($token) ?>">

                    <div class="form-group">
                        <label for="newPassword">
                            <i class="fas fa-lock"></i> New Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" id="newPassword" name="password" required
                                   placeholder="Enter new password (min. 8 characters)" autocomplete="new-password"
                                   minlength="8">
                            <button type="button" class="toggle-password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength" style="display:none;margin-top:8px;">
                            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                            <small class="strength-text" id="strengthText"></small>
                        </div>
                        <span class="error-msg" id="newPasswordError"></span>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">
                            <i class="fas fa-circle-check"></i> Confirm Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" id="confirmPassword" name="confirm_password" required
                                   placeholder="Repeat your new password" autocomplete="new-password">
                            <button type="button" class="toggle-password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="error-msg" id="confirmPasswordError"></span>
                    </div>

                    <button type="submit" class="btn-submit" id="resetSubmitBtn" class="w-full">
                        <i class="fas fa-save"></i> Reset Password
                    </button>
                </form>
                <?php else: ?>
                    <div style="text-align:center;margin-top:20px;">
                        <a href="<?= BASE_URL ?>/login.php" class="btn-submit" style="display:inline-block;">
                            <i class="fas fa-arrow-left"></i> Back to Login
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script src="assets/js/main.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('resetForm');
        const newPassword = document.getElementById('newPassword');
        const confirmPassword = document.getElementById('confirmPassword');
        const strengthBar = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        const strengthContainer = document.getElementById('passwordStrength');

        if (!newPassword || !confirmPassword || !form) return;

        // Password strength meter
        newPassword.addEventListener('input', function() {
            const password = this.value;
            if (strengthContainer) strengthContainer.style.display = password.length > 0 ? 'block' : 'none';

            let strength = 0;
            let feedback = '';

            if (password.length >= 8) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[a-z]/.test(password)) strength += 15;
            if (/[0-9]/.test(password)) strength += 20;
            if (/[^A-Za-z0-9]/.test(password)) strength += 15;

            strength = Math.min(100, strength);

            if (strengthBar) {
                strengthBar.style.width = strength + '%';
                strengthBar.style.backgroundColor =
                    strength < 40 ? '#dc3545' :
                    strength < 70 ? '#ffc107' : '#28a745';
            }

            if (strength < 40) feedback = 'Weak';
            else if (strength < 70) feedback = 'Fair';
            else if (strength < 90) feedback = 'Good';
            else feedback = 'Strong';

            if (strengthText) {
                strengthText.textContent = 'Password strength: ' + feedback;
                strengthText.style.color = strengthBar ? strengthBar.style.backgroundColor : '';
            }
        });

        // Form validation
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const pwd = newPassword.value;
            const confirm = confirmPassword.value;

            if (pwd.length < 8) {
                showError('newPasswordError', 'Password must be at least 8 characters.');
                newPassword.focus();
                return;
            }

            if (pwd !== confirm) {
                showError('confirmPasswordError', 'Passwords do not match.');
                confirmPassword.focus();
                return;
            }

            // Submit form
            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.text())
            .then(html => {
                // Reload page to show result
                window.location.reload();
            })
            .catch(err => {
                showError('confirmPasswordError', 'An error occurred. Please try again.');
            });
        });

        function showError(elementId, message) {
            const el = document.getElementById(elementId);
            if (el) {
                el.textContent = message;
                el.style.display = 'block';
            }
        }
    });
    </script>
</body>
</html>