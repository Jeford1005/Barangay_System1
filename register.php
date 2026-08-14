    <?php
    require_once __DIR__ . '/config.php';

    $message = '';
    $messageType = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid request. Please try again.';
            $messageType = 'error';
        } else {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            $errors = [];

            if (empty($fullName) || strlen($fullName) < 2) {
                $errors[] = 'Full name is required (minimum 2 characters).';
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid email address is required.';
            }
            if (empty($phone) || strlen($phone) < 10) {
                $errors[] = 'Valid phone number is required (minimum 10 digits).';
            }
            if (empty($address) || strlen($address) < 5) {
                $errors[] = 'Address is required (minimum 5 characters).';
            }
            if (empty($username) || strlen($username) < 3) {
                $errors[] = 'Username is required (minimum 3 characters).';
            }
            if (empty($password) || strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters.';
            }
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }

            if (empty($errors)) {
                try {
                    // Check if username or email already exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
                    $stmt->execute([':username' => $username, ':email' => $email]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        $message = 'Username or email already registered. Please use different credentials.';
                        $messageType = 'error';
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $role = 'resident';
                        $status = 'active';

                        $pdo->beginTransaction();

                        try {
                            // Create user account
                            $stmt = $pdo->prepare("
                                INSERT INTO users (username, email, password, full_name, role, status, created_at, updated_at)
                                VALUES (:username, :email, :password, :full_name, :role, :status, NOW(), NOW())
                            ");
                            $stmt->execute([
                                ':username' => $username,
                                ':email' => $email,
                                ':password' => $hashedPassword,
                                ':full_name' => $fullName,
                                ':role' => $role,
                                ':status' => $status
                            ]);
                            $userId = $pdo->lastInsertId();

                            // Create resident profile linked to user
                            $stmt = $pdo->prepare("
                                INSERT INTO residents (first_name, middle_name, last_name, email, contact_number, status, created_at, updated_at)
                                VALUES (:first_name, :middle_name, :last_name, :email, :contact_number, 'Active', NOW(), NOW())
                            ");
                            $nameParts = explode(' ', trim($fullName), 2);
                            $firstName = $nameParts[0] ?? $fullName;
                            $lastName = $nameParts[1] ?? '';

                            $stmt->execute([
                                ':first_name' => $firstName,
                                ':middle_name' => null,
                                ':last_name' => $lastName,
                                ':email' => $email,
                                ':contact_number' => $phone
                            ]);
                            $residentId = $pdo->lastInsertId();

                            // Link user to resident
                            $stmt = $pdo->prepare("UPDATE users SET resident_id = :rid WHERE id = :uid");
                            $stmt->execute([':rid' => $residentId, ':uid' => $userId]);

                            $pdo->commit();

                            log_audit('register', 'user', $userId, null, ['role' => $role, 'email' => $email]);
                            $message = 'Registration successful! You can now sign in with your credentials.';
                            $messageType = 'success';
                        } catch (PDOException $e) {
                            $pdo->rollBack();
                            error_log('Registration Error: ' . $e->getMessage());
                            $message = 'Registration failed. Please contact the administrator.';
                            $messageType = 'error';
                        }
                    }
                } catch (PDOException $e) {
                    error_log('Registration Error: ' . $e->getMessage());
                    $message = 'Registration failed. Please contact the administrator.';
                    $messageType = 'error';
                }
            } else {
                $message = implode(' ', $errors);
                $messageType = 'error';
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Register - Barangay Bidduang</title>
        <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
        <link rel="stylesheet" href="assets/css/fontawesome.min.css">
        <link rel="stylesheet" href="assets/css/design-system.css?v=<?= ASSET_VERSION ?>">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    </head>
    <body>
        <div class="auth-container">
            <!-- Left Panel: Branding -->
            <div class="auth-left">
                <div class="halftone-bg">
                    <div class="stripe-overlay"></div>
                    <div class="seal-circle">
                        <i class="fas fa-landmark"></i>
                    </div>
                </div>
                <div class="brand-content">
                    <h1>Barangay Bidduang</h1>
                    <p>Create your resident account to access barangay services.</p>
                    <div class="brand-icons">
                        <i class="fas fa-shield-alt"></i>
                        <i class="fas fa-lock"></i>
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Registration Form -->
            <div class="auth-right">
                <div class="form-panel register-panel">
                    <div class="form-header">
                        <h2><i class="fas fa-user-plus"></i> Resident Registration</h2>
                        <p>Fill in your details to create an account.</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="toast-alert toast-<?php echo htmlspecialchars($messageType); ?>" id="floatingAlert">
                            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                            <span><?php echo htmlspecialchars($message); ?></span>
                            <button onclick="this.parentElement.remove()" class="toast-close">&times;</button>
                        </div>
                    <?php endif; ?>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const alertBox = document.getElementById('floatingAlert');
                        if (alertBox) {
                            setTimeout(function() {
                                alertBox.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                                alertBox.style.opacity = '0';
                                alertBox.style.transform = 'translateY(-20px)';
                                setTimeout(function() { alertBox.remove(); }, 400);
                            }, 3000);
                        }
                    });
                    </script>

                    <form id="registerPageForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                        <div class="form-group">
                            <label for="regFullName">
                                <i class="fas fa-id-card"></i> Full Name
                            </label>
                            <input type="text" id="regFullName" name="full_name" required
                                value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                placeholder="Enter your full name" autocomplete="name">
                            <span class="error-msg" id="regFullNameError"></span>
                        </div>

                        <div class="form-group">
                            <label for="regEmail">
                                <i class="fas fa-envelope"></i> Email Address
                            </label>
                            <input type="email" id="regEmail" name="email" required
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                placeholder="Enter your email" autocomplete="email">
                            <span class="error-msg" id="regEmailError"></span>
                        </div>

                        <div class="form-group">
                            <label for="regPhone">
                                <i class="fas fa-phone"></i> Phone Number
                            </label>
                            <input type="tel" id="regPhone" name="phone" required
                                value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                placeholder="09XXXXXXXXX" autocomplete="tel">
                            <span class="error-msg" id="regPhoneError"></span>
                        </div>

                        <div class="form-group">
                            <label for="regAddress">
                                <i class="fas fa-map-marker-alt"></i> Address
                            </label>
                            <input type="text" id="regAddress" name="address" required
                                value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>"
                                placeholder="Your residential address">
                            <span class="error-msg" id="regAddressError"></span>
                        </div>

                        <div class="form-group">
                            <label for="regUsername">
                                <i class="fas fa-user"></i> Username
                            </label>
                            <input type="text" id="regUsername" name="username" required
                                value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
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
                                <a href="login.php" class="link-btn">
                                    <i class="fas fa-sign-in-alt"></i> Sign In
                                </a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
        <script src="assets/js/main.js"></script>
        <?php if ($message): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: '<?php echo $messageType === 'success' ? 'Success' : 'Error'; ?>',
                    text: <?php echo json_encode($message); ?>,
                    icon: '<?php echo $messageType === 'success' ? 'success' : 'error'; ?>',
                    confirmButtonText: 'OK'
                });
            });
        </script>
        <?php endif; ?>
    </body>
    </html>
