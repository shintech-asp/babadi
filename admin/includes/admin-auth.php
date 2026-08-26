<?php
// admin/includes/admin-auth.php
// Include at the top of every admin page BEFORE any output.
//
// Management pages:
//   $allowed_roles = ['admin'];
//   require_once 'includes/admin-auth.php';
//
// Dept pages (legacy pattern, now strict):
//   $require_dept = 'hr';
//   require_once '../includes/admin-auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Detect if we are one level deep (admin/hr/ or admin/finance/)
$_auth_in_sub = (
    strpos($_SERVER['PHP_SELF'] ?? '', '/hr/')      !== false ||
    strpos($_SERVER['PHP_SELF'] ?? '', '/finance/') !== false
);
$_auth_prefix = $_auth_in_sub ? '../' : '';

function _adminRoleHome(string $role, string $prefix): string {
    switch ($role) {
        case 'super_admin': return $prefix . 'super-admin-dashboard.php';
        case 'admin':       return $prefix . 'dashboard.php';
        case 'hr':          return $prefix . 'hr/dashboard.php';
        case 'finance':     return $prefix . 'finance/dashboard.php';
        default:            return $prefix . 'admin-login.php';
    }
}

// 1. Login check
if (!isset($_SESSION['admin_id']) || !($_SESSION['admin_logged_in'] ?? false)) {
    header('Location: ' . $_auth_prefix . 'admin-login.php');
    exit();
}

$_auth_role = (string)($_SESSION['admin_role'] ?? 'admin');

// 2. $allowed_roles whitelist — explicit per-page role restriction
if (isset($allowed_roles) && is_array($allowed_roles)) {
    if (!in_array($_auth_role, $allowed_roles, true)) {
        $_SESSION['admin_error'] = "You don't have permission to access that page.";
        header('Location: ' . _adminRoleHome($_auth_role, $_auth_prefix));
        exit();
    }
}
// 3. $require_dept — legacy dept pattern, now strict (no super_admin/admin bypass)
elseif (!empty($require_dept)) {
    if ($_auth_role !== (string)$require_dept) {
        $_SESSION['admin_error'] = "You don't have permission to access that page.";
        header('Location: ' . _adminRoleHome($_auth_role, $_auth_prefix));
        exit();
    }
}

// 4. Force password change on first login
if (($_SESSION['must_change_password'] ?? 0) && basename($_SERVER['PHP_SELF']) !== 'change-password.php') {
    header('Location: ' . $_auth_prefix . 'change-password.php');
    exit();
}
