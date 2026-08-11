<?php
/**
 * config.php
 * Barangay Bidduang Portal - Configuration & Session Security
 * 
 * Database: barangay_bidduang_db
 * Server:   XAMPP (localhost)
 * 
 * SECURITY NOTES:
 * - All PDO connections use parameterized queries (no SQL injection)
 * - Session security: HTTP-only, SameSite=Strict, secure cookie flags
 * - Session ID regenerated on auth state changes
 * - No database errors exposed to end users
 */

// ============================================================
// ERROR REPORTING (Production: log only, no display)
// ============================================================
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Ensure logs directory exists
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0755, true);
}

// ============================================================
// SESSION SECURITY CONFIGURATION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    // Set secure session cookie parameters BEFORE session_start()
    $cookieParams = session_get_cookie_params();
    
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'] ?? 0,
        'path'     => $cookieParams['path'] ?? '/',
        'domain'   => $cookieParams['domain'] ?? '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_start();
    
    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } elseif (time() - $_SESSION['created'] > 1800) {
        // Regenerate every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
    
    // Prevent session hijacking: store User-Agent hash
    if (!isset($_SESSION['ua_hash'])) {
        $_SESSION['ua_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}

// ============================================================
// DATABASE CONNECTION (XAMPP / Railway Ready)
// ============================================================

// Check for Railway's DATABASE_URL / MYSQL_URL connection string first
$dbUrl = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');

if ($dbUrl) {
    // Production/Railway Setup (Parse standard mysql://user:***@host:port/dbname URL)
    $dbParts = parse_url($dbUrl);
    
    define('DB_HOST', $dbParts['host'] ?? 'localhost');
    define('DB_PORT', $dbParts['port'] ?? 3306);
    define('DB_NAME', ltrim($dbParts['path'] ?? '', '/'));
    define('DB_USER', $dbParts['user'] ?? 'root');
    define('DB_PASS', $dbParts['pass'] ?? '');
} else {
    // Local / XAMPP Development Defaults
    // Also supports Railway individual variable format (DB_PASSWORD instead of DB_PASS)
    // Environment-aware defaults:
    //  - Railway web service sets DB_HOST=mysql.railway.internal
    //  - Vercel project sets DB_HOST=altaria.proxy.rlwy.net
    //  - Local XAMPP has no DB_HOST -> fallback to 127.0.0.1
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('DB_PORT') ?: 3306);
    define('DB_NAME', getenv('DB_NAME') ?: 'barangay_bidduang_db');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '');
}

define('DB_CHARSET', 'utf8mb4');
define('BROADCAST_AUTO_DISPATCH', getenv('BROADCAST_AUTO_DISPATCH') !== 'false');

// Build DSN with port
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Log error without exposing details
    error_log('Database Connection Error: ' . $e->getMessage());
    
    // Show generic error page
    if (!headers_sent()) {
        http_response_code(500);
    }
    die('<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center;">
            <h1 style="color: #c0392b;">System Unavailable</h1>
            <p style="font-size: 18px; color: #2c3e50;">The database connection failed. Please contact the system administrator.</p>
            <p style="font-size: 14px; color: #7f8c8d;">Error Code: DB_CONN_FAILED</p>
         </div>');
}

// ============================================================
// SESSION SECURITY VALIDATION
// ============================================================

/**
 * Validate current session against stored User-Agent hash
 * Call this at the top of every protected page
 */
function validate_session() {
    if (!isset($_SESSION['ua_hash'])) {
        session_destroy();
        redirect_to_login();
    }
    
    $currentUA = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!hash_equals($_SESSION['ua_hash'], $currentUA)) {
        session_destroy();
        redirect_to_login();
    }
}

/**
 * Regenerate session ID on privilege level change (login/logout)
 */
function secure_session_regenerate() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Redirect unauthorized users to login
 */
function redirect_to_login() {
    if (!headers_sent()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    die('<script>window.location.href = \'' . BASE_URL . '/login.php\';</script>');
}

/**
 * Redirect already-authenticated users away from login page
 */
function redirect_if_authenticated() {
    if (isset($_SESSION['user_id']) && validate_session()) {
        $role = $_SESSION['user_role'] ?? 'resident';
        if ($role === 'admin' || $role === 'staff') {
            header('Location: ' . BASE_URL . '/dashboard.php');
        } else {
            header('Location: ' . BASE_URL . '/resident-dashboard.php');
        }
        exit;
    }
}

/**
 * Require authentication - call at top of protected pages
 */
function require_auth() {
    validate_session();
    
    if (!isset($_SESSION['user_id'])) {
        redirect_to_login();
    }
    
    if (!isset($_SESSION['user_role'])) {
        redirect_to_login();
    }
}

/**
 * Require specific role(s)
 */
function require_role($roles = []) {
    require_auth();
    
    $userRole = $_SESSION['user_role'] ?? '';
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    if (!in_array($userRole, $roles)) {
        http_response_code(403);
        die('<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center;">
                <h1 style="color: #c0392b;">Access Denied</h1>
                <p style="font-size: 18px; color: #2c3e50;">You do not have permission to view this page.</p>
                <p style="font-size: 14px; color: #7f8c8d;">Required Role: ' . htmlspecialchars(implode(', ', $roles)) . '</p>
             </div>');
    }
}

// ============================================================
// CSRF TOKEN MANAGEMENT
// ============================================================

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Initialize CSRF token for the session
generate_csrf_token();

// ============================================================
// SANITIZATION UTILITIES
// ============================================================

/**
 * Sanitize string output for HTML display
 */
function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Get current authenticated user info
 */
function current_user() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id'       => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['full_name'] ?? '',
            'role'     => $_SESSION['user_role'] ?? 'resident',
            'email'    => $_SESSION['email'] ?? ''
        ];
    }
    return null;
}

// =============================================================
// AUDIT LOGGING (using AuditLogger service)
// ============================================================

/**
 * Load the AuditLogger class - a reusable, decoupled logging service.
 * Provides structured logging with sensitive data masking, severity levels,
 * and non-blocking insert operations.
 */
require_once __DIR__ . '/lib/AuditLogger.php';

/**
 * Legacy-compatible wrapper for AuditLogger.
 * Logs an action to audit_logs table.
 *
 * @deprecated Since v2.0 - Use AuditLogger::log() directly instead.
 */
function log_audit($action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null) {
    $actionMap = [
        'login'                => ['AUTH', 'Auth', ['event' => 'login'], 'INFO'],
        'logout'               => ['AUTH', 'Auth', ['event' => 'logout'], 'INFO'],
        'password_reset'       => ['AUTH', 'Auth', ['event' => 'password_reset'], 'INFO'],
        'password_reset_request' => ['AUTH', 'Auth', ['event' => 'password_reset_request'], 'INFO'],
        'login_failed'           => ['AUTH', 'Auth', ['event' => 'login_failed'], 'WARN'],
        'role_update'          => ['UPDATE', 'Users', null, 'WARN'],
        'create'               => ['CREATE', null, null, 'INFO'],
        'read'                 => ['READ', null, null, 'INFO'],
        'update'               => ['UPDATE', null, null, 'WARN'],
        'delete'               => ['DELETE', null, null, 'CRITICAL'],
        'export'               => ['EXPORT', null, null, 'WARN'],
    ];

    if (isset($actionMap[$action])) {
        $mapped = $actionMap[$action];
        $module = $mapped[1] ?? ($entityType ?? 'System');
        AuditLogger::log($mapped[0], $module, $entityId, $oldValues, $newValues ?? $mapped[2], $mapped[3]);
    } else {
        $actionType = strtoupper($action);
        $validActions = ['CREATE', 'READ', 'UPDATE', 'DELETE', 'EXPORT', 'AUTH'];
        if (!in_array($actionType, $validActions)) {
            $actionType = 'READ';
        }
        AuditLogger::log($actionType, $entityType ?? 'System', $entityId, $oldValues, $newValues, 'INFO');
    }
}

// ============================================================
// BASE PATH FOR REDIRECTS
// ============================================================
// Localhost serves from /BARANGAY_MANAGEMENT subfolder.
// Vercel & Railway serve from root, so set BASE_PATH='' as an env var there.
define('BASE_URL', rtrim(getenv('BASE_PATH') ?: '/BARANGAY_MANAGEMENT', '/'));
define('ADMIN_EMAIL', 'noreply@bidduang.gov.ph');
define('APP_NAME', 'Barangay Bidduang Management Portal');
define('UPLOAD_PATH', __DIR__ . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');

// Ensure upload directories exist
$requiredDirs = [
    UPLOAD_PATH,
    UPLOAD_PATH . '/photos',
    UPLOAD_PATH . '/attachments'
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ============================================================
// TIMEZONE & LOCALE
// ============================================================
date_default_timezone_set('Asia/Manila');
