<?php
/**
 * logout.php
 * Barangay Bidduang - Secure Logout
 */

require_once __DIR__ . '/config.php';

// Log audit before destroying session
if (isset($_SESSION['user_id'])) {
    log_audit('logout', 'user', $_SESSION['user_id']);
}

// Regenerate session ID and destroy
secure_session_regenerate();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

header('Location: /BARANGAY_MANAGEMENT/login.php');
exit;
