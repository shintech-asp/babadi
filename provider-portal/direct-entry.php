<?php
// provider-portal/direct-entry.php
// Bridge from main provider dashboard to provider portal without separate login form.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['user_type'] ?? '') !== 'provider')) {
    header('Location: ../login.php?redirect=' . urlencode('provider-portal/direct-entry.php'));
    exit();
}

$database = new Database();
$db = $database->getConnection();
$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $db->prepare(
        "SELECT p.id AS provider_id, p.company_name, u.first_name, u.last_name
         FROM providers p
         JOIN users u ON u.id = p.user_id
         WHERE p.user_id = :uid
         LIMIT 1"
    );
    $stmt->execute([':uid' => $userId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $provider = false;
}

if (!$provider) {
    header('Location: ../providers-dashboard.php?portal_error=' . urlencode('Provider account mapping was not found.'));
    exit();
}

$providerId = (int)$provider['provider_id'];
$company = trim((string)($provider['company_name'] ?? 'Provider'));
$fullName = trim((string)($provider['first_name'] ?? '') . ' ' . (string)($provider['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = $company !== '' ? $company . ' Owner' : 'Provider Owner';
}

// Keep an existing owner staff id when available (for audit fields in portal actions).
$portalStaffId = null;
try {
    $staffStmt = $db->prepare(
        "SELECT id
         FROM provider_staff
         WHERE provider_id = :pid AND role = 'owner'
         LIMIT 1"
    );
    $staffStmt->execute([':pid' => $providerId]);
    $ownerRow = $staffStmt->fetch(PDO::FETCH_ASSOC);
    if ($ownerRow && isset($ownerRow['id'])) {
        $portalStaffId = (int)$ownerRow['id'];
    }
} catch (Exception $e) {
    $portalStaffId = null;
}

if (!$portalStaffId) {
    // Fallback synthetic id so portal auth can proceed even if owner staff row is missing.
    $portalStaffId = 'owner_' . $providerId;
}

$_SESSION['portal_staff_id'] = $portalStaffId;
$_SESSION['portal_provider_id'] = $providerId;
$_SESSION['portal_role'] = 'owner';
$_SESSION['portal_dept'] = 'all';
$_SESSION['portal_full_name'] = $fullName;
$_SESSION['portal_company'] = $company !== '' ? $company : 'Provider';
$_SESSION['portal_must_change'] = 0;
$_SESSION['portal_account_type'] = 'staff';
$_SESSION['portal_employee_id'] = 0;

header('Location: dashboard.php');
exit();
