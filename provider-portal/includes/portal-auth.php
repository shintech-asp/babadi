<?php
// provider-portal/includes/portal-auth.php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['portal_staff_id'])) {
    if (isset($_SESSION['user_id']) && (($_SESSION['user_type'] ?? '') === 'provider')) {
        header('Location: direct-entry.php');
    } else {
        header('Location: ../auth/login.php');
    }
    exit();
}

if (($_SESSION['portal_must_change'] ?? 0) && basename($_SERVER['PHP_SELF']) !== 'change-password.php') {
    header('Location: change-password.php'); exit();
}

if (!empty($require_dept)) {
    $role = $_SESSION['portal_role'] ?? 'hr';
    $dept = $_SESSION['portal_dept'] ?? 'hr';
    if ($role !== 'owner' && $dept !== 'all' && $dept !== $require_dept) {
        header('Location: dashboard.php?error=access_denied'); exit();
    }
}

$portal_provider_id = (int)($_SESSION['portal_provider_id'] ?? 0);
$portal_role        = $_SESSION['portal_role']      ?? 'hr';
$portal_dept        = $_SESSION['portal_dept']      ?? 'hr';
$portal_full_name   = $_SESSION['portal_full_name'] ?? 'Staff';
$portal_company     = $_SESSION['portal_company']   ?? 'Provider';

$root = dirname(dirname(__DIR__));
if (!defined('DB_HOST')) {
    require_once $root . '/config/config.php';
}
