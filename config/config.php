<?php
// config/config.php

// ── Manual access gate (kill-switch) ──────────────────────────────────────
// Checked before anything else — no DB connection, no session, so it works
// even if the database is unreachable or config below is broken. See
// config/access_gate.php for the two flags this reads; that file only ever
// changes via direct server file access (SSH/FTP/file manager), never
// through any page or endpoint in this app.
require_once __DIR__ . '/access_gate.php';
if (SITE_LOCKED_PAYMENT || SITE_LOCKED_MAINTENANCE) {
    $__isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    $__lockTitle = SITE_LOCKED_PAYMENT ? 'Account Suspended' : 'Under Maintenance';
    $__lockMessage = SITE_LOCKED_PAYMENT
        ? 'This service has been temporarily suspended. Please settle your account with your developer to restore access.'
        : "We're performing scheduled maintenance. Please check back shortly.";

    http_response_code(503);

    if ($__isApiRequest) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $__lockMessage, 'locked' => true]);
        exit();
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . htmlspecialchars($__lockTitle) . '</title>'
        . '<style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body {
                font-family: -apple-system, "Segoe UI", Arial, sans-serif;
                background: linear-gradient(135deg, #1a1f3a 0%, #2c3e7a 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            }
            .card {
                background: #fff;
                border-radius: 20px;
                max-width: 440px;
                width: 100%;
                padding: 44px 34px;
                text-align: center;
                box-shadow: 0 24px 64px rgba(0,0,0,.35);
            }
            .icon {
                width: 72px; height: 72px;
                background: #1A1F3A;
                border-radius: 18px;
                display: flex; align-items: center; justify-content: center;
                margin: 0 auto 22px;
                font-size: 34px;
            }
            h1 { color:#1A1F3A; font-size: 22px; margin-bottom: 12px; }
            p { color:#718096; font-size: 14px; line-height: 1.6; }
        </style>
        </head><body>
        <div class="card">
            <div class="icon">🔒</div>
            <h1>' . htmlspecialchars($__lockTitle) . '</h1>
            <p>' . htmlspecialchars($__lockMessage) . '</p>
        </div>
        </body></html>';
    exit();
}

// php.ini's date.timezone is set to Europe/Berlin (XAMPP default), but this
// app is Philippines-only (Cavite-only addresses) and MySQL's server clock
// runs on the OS's actual timezone (Asia/Manila). Left mismatched, PHP's
// date()/time() lag several hours behind MySQL's NOW()/CURDATE(), which
// silently breaks any PHP-side "is it service day yet" check near midnight.
date_default_timezone_set('Asia/Manila');

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'pestify');

// Site configuration
define('SITE_NAME', 'Pestify');
define('SITE_URL', 'http://localhost/pestify');
define('GOOGLE_MAPS_API_KEY', '');

$__sitePath = rtrim((string)parse_url(SITE_URL, PHP_URL_PATH), '/');
define('APP_BASE_PATH', $__sitePath === '' ? '' : $__sitePath);
define('APP_ROOT_DIR', dirname(__DIR__));

// PayMongo configuration
// Get your keys at https://dashboard.paymongo.com/developers
// Use sk_test_... / pk_test_... for testing, sk_live_... / pk_live_... for production
define('PAYMONGO_SECRET_KEY', 'sk_test_APQpFtdoAtcT1YeCRZRHaxCo');  // e.g. sk_test_xxxxxxxxxxxxxxxxxxxx
define('PAYMONGO_PUBLIC_KEY', 'pk_test_9KGkU1Dw7PYvWF7XuwEfvGkQ');  // e.g. pk_test_xxxxxxxxxxxxxxxxxxxx
// system/paymongo-webhook.php reads this — was undefined entirely, so every
// webhook call fatally errored on an "Undefined constant" before it could
// even validate a signature. Empty string is a safe default: validatePaymongoSignature()
// already returns false (rejects) when the secret is empty, so this just stops
// the crash — set your real value from the PayMongo Dashboard → Webhooks page
// once a webhook endpoint is registered there.
define('PAYMONGO_WEBHOOK_SECRET', '');

// Groq (DSS free-text parsing + explanations) — the actual key lives in the
// gitignored config/secrets.php, never here. Feature auto-disables (falls
// back to rule-based parsing + template explanations) when the key is empty.
if (file_exists(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}
if (!defined('GROQ_API_KEY')) {
    define('GROQ_API_KEY', '');
}
define('GROQ_MODEL', 'openai/gpt-oss-20b');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
define('GROQ_ENABLED', GROQ_API_KEY !== '');
define('GROQ_TIMEOUT', 5);
define('GROQ_CONNECT_TIMEOUT', 2);

// Email configuration (NEW - for OTP verification)
define('SMTP_HOST', 'smtp.gmail.com'); // Your SMTP server
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'yhujiinn@gmail.com');
// SMTP_PASSWORD is defined in the gitignored config/secrets.php (Gmail App
// Password — the old plaintext value here was expired/invalid and every
// email send was failing auth). Fallback keeps the app from fatally
// erroring if secrets.php is ever missing on a fresh checkout.
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', '');
}
define('NOREPLY_EMAIL', 'yhujiinn@gmail.com');

// OTP configurations (NEW)
define('OTP_EXPIRY_MINUTES', 15);
define('MAX_OTP_ATTEMPTS', 5);
define('RESEND_OTP_COOLDOWN', 60); // seconds

// Upload directories
define('UPLOAD_DIR', 'uploads/');
define('PROVIDER_IMAGE_DIR', UPLOAD_DIR . 'providers/');
define('SERVICE_IMAGE_DIR', UPLOAD_DIR . 'services/');

// Create upload directories if they don't exist
if (!file_exists(PROVIDER_IMAGE_DIR)) {
    mkdir(PROVIDER_IMAGE_DIR, 0777, true);
}
if (!file_exists(SERVICE_IMAGE_DIR)) {
    mkdir(SERVICE_IMAGE_DIR, 0777, true);
}

// Helper functions

/**
 * Sanitize input data to prevent XSS attacks
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Escape output for safe display in HTML
 */
function escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isProvider() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'provider';
}

function isSeeker() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'seeker';
}

function isAdmin() {
    // Check both old and new admin session variables for compatibility
    return (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') || 
           (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true);
}

function redirect($url) {
    $url = (string)$url;
    if ($url !== '' && !preg_match('/^(?:[a-z][a-z0-9+.-]*:|\\/)/i', $url)) {
        $url = appUrl($url);
    }
    header("Location: " . $url);
    exit();
}

function appPath(string $path = ''): string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return APP_ROOT_DIR;
    }
    return APP_ROOT_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function canonicalAppRoute(string $path = ''): string {
    $path = ltrim(str_replace('\\', '/', (string)$path), '/');
    if ($path === '') {
        return '';
    }

    $fragment = '';
    $fragmentPos = strpos($path, '#');
    if ($fragmentPos !== false) {
        $fragment = substr($path, $fragmentPos);
        $path = substr($path, 0, $fragmentPos);
    }

    $query = '';
    $queryPos = strpos($path, '?');
    if ($queryPos !== false) {
        $query = substr($path, $queryPos);
        $path = substr($path, 0, $queryPos);
    }

    static $routeMap = [
        'avail-service-process.php'     => 'seeker/avail-service-process.php',
        'booking-details.php'           => 'seeker/booking-details.php',
        'get-available-times.php'       => 'seeker/get-available-times.php',
        'my-requests.php'               => 'seeker/my-requests.php',
        'payment-cancel.php'            => 'seeker/payment-cancel.php',
        'payment-failed.php'            => 'seeker/payment-failed.php',
        'payment-redirect.php'          => 'seeker/payment-redirect.php',
        'payment-success.php'           => 'seeker/payment-success-result.php',
        'provider-details.php'          => 'seeker/provider-details.php',
        'providers.php'                 => 'seeker/providers.php',
        'recommend.php'                 => 'seeker/recommend.php',
        'request-service.php'           => 'seeker/request-service.php',
        'seeker-booking-calendar.php'   => 'seeker/seeker-booking-calendar.php',
        'setup-address.php'             => 'seeker/setup-address.php',
        'submit-feedback.php'           => 'seeker/submit-feedback.php',
        'create-listing.php'            => 'provider/create-listing.php',
        'my-services.php'               => 'provider/my-services.php',
        'provider-payment-settings.php' => 'provider/provider-payment-settings.php',
        'provider-setup.php'            => 'provider/provider-setup.php',
        'providers-dashboard.php'       => 'provider/providers-dashboard.php',
        'service-requests.php'          => 'provider/service-requests.php',
        'services.php'                  => 'provider/services.php',
        'update-booking-status.php'     => 'provider/update-booking-status.php',
        'verify-service.php'            => 'provider/verify-service.php',
        'forgot-password.php'           => 'auth/forgot-password.php',
        'login-backup.php'              => 'auth/login-backup.php',
        'login.php'                     => 'auth/login.php',
        'logout.php'                    => 'auth/logout.php',
        'register.php'                  => 'auth/register.php',
        'reset-password.php'            => 'auth/reset-password.php',
        'verify.php'                    => 'auth/verify.php',
        'listing-details.php'           => 'browse/listing-details.php',
        'listings.php'                  => 'browse/listings.php',
        'providers-listings.php'        => 'browse/providers-listings.php',
        'request-details.php'           => 'provider/service-requests.php',
    ];

    if (strpos($path, '/') === false && isset($routeMap[$path])) {
        $path = $routeMap[$path];
    }

    return $path . $query . $fragment;
}

function appUrl(string $path = ''): string {
    $path = canonicalAppRoute($path);
    if ($path === '') {
        return APP_BASE_PATH !== '' ? APP_BASE_PATH . '/' : '/';
    }
    return (APP_BASE_PATH !== '' ? APP_BASE_PATH : '') . '/' . $path;
}

function siteUrl(string $path = ''): string {
    $path = canonicalAppRoute($path);
    return rtrim(SITE_URL, '/') . ($path !== '' ? '/' . $path : '');
}

/**
 * Generate a CSRF token for form security
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require authentication - redirect to login if not logged in
 */
function requireAuth() {
    if (!isLoggedIn()) {
        // Store the current URL to redirect back after login
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        redirect('login.php');
    }
}

/**
 * Require specific user type
 */
function requireUserType($type) {
    requireAuth(); // First check if user is logged in
    
    $allowed_types = is_array($type) ? $type : [$type];
    $user_type = $_SESSION['user_type'] ?? '';
    
    if (!in_array($user_type, $allowed_types)) {
        // User doesn't have the required role
        $_SESSION['error'] = "You don't have permission to access this page.";
        redirect('index.php');
    }
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user type
 */
function getCurrentUserType() {
    return $_SESSION['user_type'] ?? null;
}

/**
 * Display flash messages
 */
function flash($name = '', $message = '') {
    if (!empty($name)) {
        if (!empty($message)) {
            // Set flash message
            $_SESSION[$name] = $message;
        } else if (isset($_SESSION[$name])) {
            // Get and clear flash message
            $message = $_SESSION[$name];
            unset($_SESSION[$name]);
            return $message;
        }
    }
    return '';
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

/**
 * Upload file with validation
 */
function uploadFile($file, $target_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif'], $max_size = 5000000) {
    $errors = [];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $file['error'];
        return ['success' => false, 'errors' => $errors];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        $errors[] = "File is too large. Maximum size is " . ($max_size / 1000000) . "MB.";
    }
    
    // Check file type
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_types)) {
        $errors[] = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $file_extension;
    $target_path = $target_dir . $filename;
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    if (empty($errors)) {
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            return [
                'success' => true,
                'filename' => $filename,
                'path' => $target_path,
                'full_path' => SITE_URL . '/' . $target_path
            ];
        } else {
            $errors[] = "Failed to move uploaded file.";
        }
    }
    
    return ['success' => false, 'errors' => $errors];
}

// Admin-specific functions
function requireAdmin() {
    if (!isAdmin()) {
        header("Location: admin/admin-login.php");
        exit();
    }
}

function adminLog($action, $details = '') {
    global $db;
    $query = "INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at) 
              VALUES (:admin_id, :action, :details, :ip, NOW())";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':admin_id' => $_SESSION['admin_id'],
        ':action' => $action,
        ':details' => $details,
        ':ip' => $_SERVER['REMOTE_ADDR']
    ]);
}

function getAdminSetting($key, $default = '') {
    global $db;
    $query = "SELECT setting_value FROM admin_settings WHERE setting_key = :key";
    $stmt = $db->prepare($query);
    $stmt->execute([':key' => $key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['setting_value'] : $default;
}

function setAdminSetting($key, $value, $description = '') {
    global $db;
    $query = "INSERT INTO admin_settings (setting_key, setting_value, description) 
              VALUES (:key, :value, :description)
              ON DUPLICATE KEY UPDATE 
              setting_value = VALUES(setting_value),
              description = VALUES(description)";
    $stmt = $db->prepare($query);
    return $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':description' => $description
    ]);
}
?>
