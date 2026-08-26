<?php
// provider-portal/login.php — Redirects to the central Pestify login page.
if (session_status() === PHP_SESSION_NONE) session_start();

// Already authenticated as portal staff
if (isset($_SESSION['portal_staff_id'])) {
    header('Location: dashboard.php'); exit();
}

// Provider logged into main app → fast-track to portal
if (isset($_SESSION['user_id']) && ($_SESSION['user_type'] ?? '') === 'provider') {
    header('Location: direct-entry.php'); exit();
}

// All other users → central login
require_once '../config/config.php';
header('Location: ' . appUrl('auth/login.php' . (isset($_GET['logout']) ? '?logout=1' : '')));
exit();
