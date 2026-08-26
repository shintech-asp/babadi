<?php
// dashboard.php - Main Dashboard
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

// Route based on user type
if ($_SESSION['user_type'] == 'provider') {
    redirect('providers-dashboard.php');
} elseif ($_SESSION['user_type'] == 'admin') {
    redirect('admin/dashboard.php');
} else {
    // Seeker dashboard
    redirect('index.php');
}
?>
