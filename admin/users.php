<?php
// admin/users.php
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle user actions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        switch ($_POST['action']) {
            case 'edit':
                // Update user information
                $first_name = trim($_POST['first_name']);
                $last_name = trim($_POST['last_name']);
                $email = trim($_POST['email']);
                $phone = trim($_POST['phone']);
                $user_type = $_POST['user_type'];
                
                // Check if email is already taken by another user
                $check_query = "SELECT id FROM users WHERE email = :email AND id != :id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':email', $email);
                $check_stmt->bindParam(':id', $user_id);
                $check_stmt->execute();
                
                if ($check_stmt->rowCount() > 0) {
                    $error_message = "Email address is already in use by another user.";
                } else {
                    // Start building the update query
                    $update_fields = [
                        "first_name = :first_name",
                        "last_name = :last_name",
                        "email = :email",
                        "phone = :phone",
                        "user_type = :user_type"
                    ];
                    
                    $params = [
                        ':first_name' => $first_name,
                        ':last_name' => $last_name,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':user_type' => $user_type,
                        ':id' => $user_id
                    ];
                    
                    // Check if password needs to be updated
                    if (!empty($_POST['new_password'])) {
                        $new_password = $_POST['new_password'];
                        $confirm_password = $_POST['confirm_password'];
                        
                        if ($new_password === $confirm_password) {
                            if (strlen($new_password) >= 8) {
                                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                                $update_fields[] = "password = :password";
                                $params[':password'] = $password_hash;
                            } else {
                                $error_message = "Password must be at least 8 characters long.";
                            }
                        } else {
                            $error_message = "Passwords do not match.";
                        }
                    }
                    
                    if (empty($error_message)) {
                        $query = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = :id";
                        $stmt = $db->prepare($query);
                        
                        if ($stmt->execute($params)) {
                            // Log the action
                            $log_query = "INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at) 
                                          VALUES (:admin_id, 'EDIT_USER', :details, :ip, NOW())";
                            $log_stmt = $db->prepare($log_query);
                            $admin_id = $_SESSION['admin_id'];
                            $details = "Updated user ID: " . $user_id . " (" . $email . ")";
                            $ip = $_SERVER['REMOTE_ADDR'];
                            $log_stmt->bindParam(':admin_id', $admin_id);
                            $log_stmt->bindParam(':details', $details);
                            $log_stmt->bindParam(':ip', $ip);
                            $log_stmt->execute();
                            
                            $success_message = "User updated successfully!";
                        } else {
                            $error_message = "Failed to update user.";
                        }
                    }
                }
                break;
                
            case 'archive':
                // Soft delete - set is_archived to 1
                $query = "UPDATE users SET is_archived = 1 WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $user_id);
                if ($stmt->execute()) {
                    // Log the action
                    $log_query = "INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at) 
                                  VALUES (:admin_id, 'ARCHIVE_USER', :details, :ip, NOW())";
                    $log_stmt = $db->prepare($log_query);
                    $admin_id = $_SESSION['admin_id'];
                    $details = "Archived user ID: " . $user_id;
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_stmt->bindParam(':admin_id', $admin_id);
                    $log_stmt->bindParam(':details', $details);
                    $log_stmt->bindParam(':ip', $ip);
                    $log_stmt->execute();
                    
                    $success_message = "User archived successfully!";
                } else {
                    $error_message = "Failed to archive user.";
                }
                break;
                
            case 'unarchive':
                // Restore archived user
                $query = "UPDATE users SET is_archived = 0 WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $user_id);
                if ($stmt->execute()) {
                    // Log the action
                    $log_query = "INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at) 
                                  VALUES (:admin_id, 'UNARCHIVE_USER', :details, :ip, NOW())";
                    $log_stmt = $db->prepare($log_query);
                    $admin_id = $_SESSION['admin_id'];
                    $details = "Restored user ID: " . $user_id;
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_stmt->bindParam(':admin_id', $admin_id);
                    $log_stmt->bindParam(':details', $details);
                    $log_stmt->bindParam(':ip', $ip);
                    $log_stmt->execute();
                    
                    $success_message = "User restored successfully!";
                } else {
                    $error_message = "Failed to restore user.";
                }
                break;
                
            case 'toggle_status':
                $new_status = $_POST['new_status'];
                $query = "UPDATE users SET status = :status WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $new_status);
                $stmt->bindParam(':id', $user_id);
                if ($stmt->execute()) {
                    // Log the action
                    $log_query = "INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at) 
                                  VALUES (:admin_id, 'UPDATE_USER_STATUS', :details, :ip, NOW())";
                    $log_stmt = $db->prepare($log_query);
                    $admin_id = $_SESSION['admin_id'];
                    $details = "Changed user ID $user_id status to: " . $new_status;
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_stmt->bindParam(':admin_id', $admin_id);
                    $log_stmt->bindParam(':details', $details);
                    $log_stmt->bindParam(':ip', $ip);
                    $log_stmt->execute();
                    
                    $success_message = "User status updated to " . ucfirst($new_status) . "!";
                } else {
                    $error_message = "Failed to update user status.";
                }
                break;
        }
    }
}

// Get filter parameters
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'active';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query - exclude archived users by default
$query = "SELECT * FROM users WHERE (is_archived IS NULL OR is_archived = 0)";

// Add archived filter if requested
if ($filter_status == 'archived') {
    $query = "SELECT * FROM users WHERE is_archived = 1";
}

if ($filter_type != 'all' && $filter_status != 'archived') {
    $query .= " AND user_type = :user_type";
}

if (!empty($search)) {
    $query .= " AND (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search)";
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);

if ($filter_type != 'all' && $filter_status != 'archived') {
    $stmt->bindParam(':user_type', $filter_type);
}

if (!empty($search)) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count users by type (excluding archived)
$count_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN user_type = 'seeker' THEN 1 ELSE 0 END) as seekers,
                    SUM(CASE WHEN user_type = 'provider' THEN 1 ELSE 0 END) as providers,
                    SUM(CASE WHEN user_type = 'admin' THEN 1 ELSE 0 END) as admins
                FROM users 
                WHERE (is_archived IS NULL OR is_archived = 0)";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute();
$counts = $count_stmt->fetch(PDO::FETCH_ASSOC);

// Count archived users
$archived_query = "SELECT COUNT(*) as archived FROM users WHERE is_archived = 1";
$archived_stmt = $db->prepare($archived_query);
$archived_stmt->execute();
$archived_count = $archived_stmt->fetch(PDO::FETCH_ASSOC)['archived'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - Pestify Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --primary-light: #C6F6D5;
            --primary-soft: #F0FFF4;
            --secondary: #4C51BF;
            --neutral-dark: #1a1f3a;
            --neutral-gray: #718096;
            --neutral-light: #E2E8F0;
            --neutral-soft: #F7FAFC;
            --success: #38A169;
            --warning: #D69E2E;
            --danger: #E53E3E;
            --info: #3182CE;
            --radius: 10px;
            --radius-lg: 18px;
            --radius-full: 9999px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.09);
            --transition: 0.3s ease;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--neutral-soft);
            color: var(--neutral-dark);
            line-height: 1.6;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
            width: 260px;
            background: var(--neutral-dark);
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform var(--transition);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .logo-text h3 {
            margin: 0;
            font-size: 1rem;
            color: #fff;
        }

        .logo-text p {
            margin: 0.25rem 0 0;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .sidebar-menu {
            padding: 1.5rem 0;
        }

        .menu-section {
            margin-bottom: 1.5rem;
        }

        .menu-section h4 {
            padding: 0 1.5rem 0.75rem;
            margin: 0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgba(255, 255, 255, 0.5);
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all var(--transition);
            border-left: 3px solid transparent;
        }

        .menu-item:hover,
        .menu-item.active {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border-left-color: var(--primary);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        .menu-badge {
            margin-left: auto;
            background: var(--primary);
            color: #fff;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .admin-main {
            flex: 1;
            margin-left: 260px;
            padding: 2rem;
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            gap: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
        }
        
        .page-title h1 {
            margin: 0 0 0.5rem;
            font-size: 1.75rem;
            color: var(--neutral-dark);
        }
        
        .page-title p {
            margin: 0;
            color: var(--neutral-gray);
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--neutral-gray);
        }
        
        .search-input {
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid var(--neutral-light);
            border-radius: var(--radius);
            width: 300px;
            font-size: 0.9375rem;
            transition: all var(--transition);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1);
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.9375rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 2px 8px rgba(46, 139, 87, 0.25);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 139, 87, 0.3);
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: all var(--transition);
        }

        .admin-profile:hover {
            background: var(--neutral-soft);
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .profile-info h4 {
            margin: 0;
            font-size: 0.9375rem;
            color: var(--neutral-dark);
        }

        .profile-info p {
            margin: 0.125rem 0 0;
            font-size: 0.75rem;
            color: var(--neutral-gray);
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: #D1FAE5;
            border: 1px solid #6EE7B7;
            color: #065F46;
        }
        
        .alert-success i {
            color: #10B981;
        }
        
        .alert-danger {
            background: #FEE2E2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
        }
        
        .alert-danger i {
            color: #DC2626;
        }
        
        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--neutral-light);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-icon.primary {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
        }
        
        .stat-icon.info {
            background: linear-gradient(135deg, #4FACFE 0%, #00F2FE 100%);
        }
        
        .stat-icon.warning {
            background: linear-gradient(135deg, #FA709A 0%, #FEE140 100%);
        }
        
        .stat-icon.danger {
            background: linear-gradient(135deg, #F093FB 0%, #F5576C 100%);
        }
        
        .stat-content h3 {
            margin: 0 0 0.25rem;
            font-size: 0.875rem;
            color: var(--neutral-gray);
            font-weight: 500;
        }
        
        .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--neutral-dark);
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            background: white;
            padding: 0.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--neutral-light);
            overflow-x: auto;
        }
        
        .filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius);
            color: var(--neutral-gray);
            text-decoration: none;
            font-size: 0.9375rem;
            font-weight: 500;
            transition: all var(--transition);
            white-space: nowrap;
        }
        
        .filter-tab:hover {
            background: var(--neutral-soft);
            color: var(--primary);
        }
        
        .filter-tab.active {
            background: var(--primary);
            color: white;
        }
        
        .filter-tab i {
            font-size: 1rem;
        }
        
        /* Users Container */
        .users-container {
            background: white;
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-light);
            overflow: hidden;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .users-table thead {
            background: var(--neutral-soft);
        }
        
        .users-table th {
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-gray);
            border-bottom: 2px solid var(--neutral-light);
        }
        
        .users-table td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
            color: var(--neutral-dark);
            font-size: 0.9375rem;
        }
        
        .users-table tr:last-child td {
            border-bottom: none;
        }
        
        .users-table tbody tr {
            transition: all var(--transition);
        }
        
        .users-table tbody tr:hover {
            background: var(--neutral-soft);
        }
        
        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .user-details h4 {
            margin: 0 0 0.125rem;
            font-size: 0.9375rem;
            color: var(--neutral-dark);
        }
        
        .user-details p {
            margin: 0;
            font-size: 0.8125rem;
            color: var(--neutral-gray);
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .badge-seeker {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        .badge-provider {
            background: #FCE7F3;
            color: #9F1239;
        }
        
        .badge-admin {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .badge-active {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .badge-suspended {
            background: #FED7AA;
            color: #9A3412;
        }
        
        .badge-banned {
            background: #FEE2E2;
            color: #991B1B;
        }
        
        .badge-archived {
            background: #E5E7EB;
            color: #374151;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: var(--radius);
            border: none;
            background: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition);
            font-size: 0.9375rem;
        }
        
        .action-view {
            color: var(--info);
        }
        
        .action-view:hover {
            background: #DBEAFE;
        }
        
        .action-edit {
            color: var(--warning);
        }
        
        .action-edit:hover {
            background: #FEF3C7;
        }
        
        .action-status {
            color: var(--success);
        }
        
        .action-status:hover {
            background: #D1FAE5;
        }
        
        .action-archive {
            color: var(--danger);
        }
        
        .action-archive:hover {
            background: #FEE2E2;
        }
        
        .action-restore {
            color: var(--success);
        }
        
        .action-restore:hover {
            background: #D1FAE5;
        }
        
        /* No Users State */
        .no-users {
            padding: 4rem 2rem;
            text-align: center;
            color: var(--neutral-gray);
        }
        
        .no-users i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        
        .no-users h3 {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
            color: var(--neutral-dark);
        }
        
        .no-users p {
            margin: 0;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            overflow-y: auto;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            max-width: 600px;
            width: 100%;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease;
            margin: 2rem auto;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: var(--neutral-dark);
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: none;
            cursor: pointer;
            color: var(--neutral-gray);
            font-size: 1.25rem;
            transition: all var(--transition);
        }
        
        .modal-close:hover {
            color: var(--neutral-dark);
        }
        
        .modal-body {
            margin-bottom: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--neutral-dark);
            font-size: 0.875rem;
        }
        
        .form-group label .required {
            color: var(--danger);
            margin-left: 0.25rem;
        }
        
        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--neutral-light);
            border-radius: var(--radius);
            font-size: 0.9375rem;
            transition: all var(--transition);
            font-family: inherit;
        }
        
        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1);
        }
        
        .form-input:disabled {
            background: var(--neutral-soft);
            cursor: not-allowed;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-help {
            display: block;
            margin-top: 0.375rem;
            font-size: 0.8125rem;
            color: var(--neutral-gray);
        }
        
        .password-toggle {
            position: relative;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--neutral-gray);
            cursor: pointer;
            padding: 0.25rem;
            transition: color var(--transition);
        }
        
        .password-toggle-btn:hover {
            color: var(--primary);
        }
        
        .modal-footer {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
        
        .btn-secondary {
            padding: 0.75rem 1.5rem;
            background: white;
            color: var(--neutral-gray);
            border: 1px solid var(--neutral-light);
            border-radius: var(--radius);
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }
        
        .btn-secondary:hover {
            background: var(--neutral-soft);
            color: var(--neutral-dark);
        }
        
        /* Detail Modal Styles */
        .no-data {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--neutral-gray);
            font-size: 0.9375rem;
        }
        
        .detail-request-item {
            background: var(--neutral-soft);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 3px solid var(--primary);
        }
        
        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        
        .request-header h5 {
            margin: 0 0 0.25rem;
            font-size: 0.9375rem;
            color: var(--neutral-dark);
        }
        
        .request-provider {
            margin: 0;
            font-size: 0.8125rem;
            color: var(--neutral-gray);
        }
        
        .request-status {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .request-status.status-pending {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .request-status.status-confirmed {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        .request-status.status-completed {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .request-status.status-cancelled {
            background: #FEE2E2;
            color: #991B1B;
        }
        
        .request-details {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.8125rem;
            color: var(--neutral-gray);
        }
        
        .request-details span {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .detail-activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--neutral-soft);
            border-radius: var(--radius);
            margin-bottom: 0.75rem;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1rem;
        }
        
        .activity-content h5 {
            margin: 0 0 0.25rem;
            font-size: 0.9375rem;
            color: var(--neutral-dark);
            text-transform: capitalize;
        }
        
        .activity-content p {
            margin: 0 0 0.5rem;
            font-size: 0.8125rem;
            color: var(--neutral-gray);
        }
        
        .activity-content small {
            display: block;
            font-size: 0.75rem;
            color: var(--neutral-gray);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            .admin-main {
                margin-left: 0;
            }
        }
        
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
            }
            
            .header-actions {
                width: 100%;
                flex-direction: column;
            }
            
            .search-input {
                width: 100%;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .filter-tabs {
                overflow-x: auto;
            }
            
            .users-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <div class="logo-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Pestify Admin</h3>
                        <p>Control Panel</p>
                    </div>
                </div>
            </div>
            
            <nav class="sidebar-menu">
                <div class="menu-section">
                    <h4>Dashboard</h4>
                    <a href="dashboard.php" class="menu-item">
                        <i class="fas fa-chart-line"></i>
                        <span>Overview</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <h4>Content Management</h4>
                    <a href="users.php" class="menu-item active">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                        <span class="menu-badge"><?php echo $counts['total']; ?></span>
                    </a>
                    <a href="providers.php" class="menu-item">
                        <i class="fas fa-building"></i>
                        <span>Providers</span>
                    </a>
                    <a href="verify-providers.php" class="menu-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Verify Providers</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <h4>System</h4>
                    <a href="settings.php" class="menu-item">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                <a href="<?php echo appUrl('logout.php'); ?>" class="menu-item">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Users Management</h1>
                    <p>Manage and monitor all registered users</p>
                </div>
                
                <div class="header-actions">
                    <form method="GET" action="" class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="search-input" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                        <?php if($filter_type != 'all'): ?>
                            <input type="hidden" name="type" value="<?php echo $filter_type; ?>">
                        <?php endif; ?>
                        <?php if($filter_status != 'active'): ?>
                            <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                        <?php endif; ?>
                    </form>
                    <a href="dashboard.php" class="btn-primary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Dashboard
                    </a>
                    <div class="admin-profile">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)); ?>
                        </div>
                        <div class="profile-info">
                            <h4><?php echo $_SESSION['admin_username'] ?? 'Admin'; ?></h4>
                            <p>Super Administrator</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Users</h3>
                        <div class="stat-number"><?php echo number_format($counts['total']); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Seekers</h3>
                        <div class="stat-number"><?php echo number_format($counts['seekers']); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Providers</h3>
                        <div class="stat-number"><?php echo number_format($counts['providers']); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Archived</h3>
                        <div class="stat-number"><?php echo number_format($archived_count); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?type=all&status=active<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_type == 'all' && $filter_status == 'active' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                    <span>All Users (<?php echo $counts['total']; ?>)</span>
                </a>
                
                <a href="?type=seeker&status=active<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_type == 'seeker' && $filter_status == 'active' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i>
                    <span>Seekers (<?php echo $counts['seekers']; ?>)</span>
                </a>
                
                <a href="?type=provider&status=active<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_type == 'provider' && $filter_status == 'active' ? 'active' : ''; ?>">
                    <i class="fas fa-building"></i>
                    <span>Providers (<?php echo $counts['providers']; ?>)</span>
                </a>
                
                <a href="?status=archived<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="filter-tab <?php echo $filter_status == 'archived' ? 'active' : ''; ?>">
                    <i class="fas fa-archive"></i>
                    <span>Archived (<?php echo $archived_count; ?>)</span>
                </a>
            </div>
            
            <!-- Users Table -->
            <div class="users-container">
                <?php if(count($users) > 0): ?>
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Email</th>
                                <th>User Type</th>
                                <th>Joined Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): ?>
                                <tr>
                                    <td><strong>#<?php echo $user['id']; ?></strong></td>
                                    
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="user-details">
                                                <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                                                <p><?php echo htmlspecialchars($user['phone'] ?? 'No phone'); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    
                                    <td>
                                        <span class="badge badge-<?php echo $user['user_type']; ?>">
                                            <?php echo ucfirst($user['user_type']); ?>
                                        </span>
                                    </td>
                                    
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    
                                    <td>
                                        <?php if(isset($user['is_archived']) && $user['is_archived'] == 1): ?>
                                            <span class="badge badge-archived">Archived</span>
                                        <?php else: ?>
                                            <span class="badge badge-<?php echo $user['status'] ?? 'active'; ?>">
                                                <?php echo ucfirst($user['status'] ?? 'Active'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <div class="action-buttons">
                                            <?php if(isset($user['is_archived']) && $user['is_archived'] == 1): ?>
                                                <!-- Archived user - show restore option -->
                                                <button class="action-btn action-restore" 
                                                        onclick="restoreUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')" 
                                                        title="Restore User">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            <?php else: ?>
                                                <!-- Active user - show normal actions -->
                                                <button class="action-btn action-view" 
                                                        onclick="viewUser(<?php echo $user['id']; ?>)" 
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-btn action-edit" 
                                                        onclick="editUser(<?php echo $user['id']; ?>)" 
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="action-btn action-status" 
                                                        onclick="changeStatus(<?php echo $user['id']; ?>, '<?php echo $user['status'] ?? 'active'; ?>', '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')" 
                                                        title="Change Status">
                                                    <i class="fas fa-toggle-on"></i>
                                                </button>
                                                <button class="action-btn action-archive" 
                                                        onclick="archiveUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')" 
                                                        title="Archive User">
                                                    <i class="fas fa-archive"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-users">
                        <i class="fas fa-users"></i>
                        <h3>No Users Found</h3>
                        <p>There are no users matching your filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Status Change Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change User Status</h3>
                <button type="button" class="modal-close" onclick="closeStatusModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" id="status_user_id">
                    
                    <div class="form-group">
                        <label for="new_status">Select New Status</label>
                        <select name="new_status" id="new_status" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="banned">Banned</option>
                        </select>
                    </div>
                    
                    <p style="color: var(--neutral-gray); font-size: 0.875rem; margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        Changing the user status will affect their ability to access the platform.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- User Detail Modal -->
    <div id="detailModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <div>
                    <h3>User Details</h3>
                    <p style="margin: 0.5rem 0 0; color: var(--neutral-gray); font-size: 0.875rem;" id="detail_user_id"></p>
                </div>
                <button type="button" class="modal-close" onclick="closeDetailModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Personal Information Section -->
                <div style="margin-bottom: 2rem;">
                    <h4 style="margin: 0 0 1rem; font-size: 1rem; color: var(--neutral-dark); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-user-circle"></i> Personal Information
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Full Name</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_full_name"></p>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Email</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_email"></p>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Phone</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_phone"></p>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Address</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_address"></p>
                        </div>
                    </div>
                </div>
                
                <hr style="border: none; border-top: 1px solid var(--neutral-light); margin: 1.5rem 0;">
                
                <!-- Account Details Section -->
                <div style="margin-bottom: 2rem;">
                    <h4 style="margin: 0 0 1rem; font-size: 1rem; color: var(--neutral-dark); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-shield-alt"></i> Account Details
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">User Type</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_user_type"></p>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Status</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_status"></p>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Joined Date</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_joined_date"></p>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: var(--neutral-gray); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Last Login</label>
                            <p style="margin: 0; font-size: 0.9375rem; color: var(--neutral-dark);" id="detail_last_login"></p>
                        </div>
                    </div>
                </div>
                
                <hr style="border: none; border-top: 1px solid var(--neutral-light); margin: 1.5rem 0;">
                
                <!-- Service Requests Section -->
                <div style="margin-bottom: 2rem;">
                    <h4 style="margin: 0 0 1rem; font-size: 1rem; color: var(--neutral-dark); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-tasks"></i> Service Requests
                    </h4>
                    <div id="detail_service_requests" style="max-height: 300px; overflow-y: auto;">
                        <p class="no-data">Loading...</p>
                    </div>
                </div>
                
                <hr style="border: none; border-top: 1px solid var(--neutral-light); margin: 1.5rem 0;">
                
                <!-- Activity History Section -->
                <div>
                    <h4 style="margin: 0 0 1rem; font-size: 1rem; color: var(--neutral-dark); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-history"></i> Activity History
                    </h4>
                    <div id="detail_activity_history" style="max-height: 300px; overflow-y: auto;">
                        <p class="no-data">Loading...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeDetailModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit User Account</h3>
                <button type="button" class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="" id="editUserForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_first_name">
                                First Name<span class="required">*</span>
                            </label>
                            <input type="text" name="first_name" id="edit_first_name" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_last_name">
                                Last Name<span class="required">*</span>
                            </label>
                            <input type="text" name="last_name" id="edit_last_name" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">
                            Email Address<span class="required">*</span>
                        </label>
                        <input type="email" name="email" id="edit_email" class="form-input" required>
                        <small class="form-help">This will be used for login</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_phone">Phone Number</label>
                        <input type="tel" name="phone" id="edit_phone" class="form-input" placeholder="e.g., 09123456789">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_user_type">
                            User Type<span class="required">*</span>
                        </label>
                        <select name="user_type" id="edit_user_type" class="form-select" required>
                            <option value="seeker">Seeker</option>
                            <option value="provider">Provider</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <hr style="border: none; border-top: 1px solid var(--neutral-light); margin: 1.5rem 0;">
                    
                    <h4 style="margin: 0 0 1rem; font-size: 1rem; color: var(--neutral-dark);">
                        <i class="fas fa-key"></i> Change Password (Optional)
                    </h4>
                    
                    <div class="form-group">
                        <label for="edit_new_password">New Password</label>
                        <div class="password-toggle">
                            <input type="password" name="new_password" id="edit_new_password" class="form-input" placeholder="Leave blank to keep current password">
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('edit_new_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="form-help">Minimum 8 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_confirm_password">Confirm New Password</label>
                        <div class="password-toggle">
                            <input type="password" name="confirm_password" id="edit_confirm_password" class="form-input" placeholder="Re-enter new password">
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('edit_confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <p style="color: var(--neutral-gray); font-size: 0.875rem; margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        Changes will take effect immediately. The user will need to use the new credentials to log in.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function viewUser(id) {
            // Fetch user details via AJAX
            fetch('get_user_details.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error: ' + data.error);
                        return;
                    }
                    
                    const user = data.user;
                    const requests = data.service_requests;
                    const activities = data.activity_logs;
                    
                    // Populate personal information
                    document.getElementById('detail_user_id').textContent = '#' + user.id;
                    document.getElementById('detail_full_name').textContent = user.first_name + ' ' + user.last_name;
                    document.getElementById('detail_email').textContent = user.email;
                    document.getElementById('detail_phone').textContent = user.phone || 'Not provided';
                    document.getElementById('detail_address').textContent = (user.address || 'Not provided') + 
                        (user.city ? ', ' + user.city : '') + 
                        (user.state ? ', ' + user.state : '') + 
                        (user.zip_code ? ' ' + user.zip_code : '');
                    
                    // Populate account details
                    document.getElementById('detail_user_type').textContent = user.user_type.charAt(0).toUpperCase() + user.user_type.slice(1);
                    document.getElementById('detail_status').textContent = (user.status || 'active').charAt(0).toUpperCase() + (user.status || 'active').slice(1);
                    document.getElementById('detail_joined_date').textContent = new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                    document.getElementById('detail_last_login').textContent = user.last_login ? new Date(user.last_login).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Never';
                    
                    // Populate service requests
                    const requestsContainer = document.getElementById('detail_service_requests');
                    if (requests.length > 0) {
                        requestsContainer.innerHTML = requests.map(req => `
                            <div class="detail-request-item">
                                <div class="request-header">
                                    <div>
                                        <h5>${req.service_title || 'General Service'}</h5>
                                        <p class="request-provider">${req.company_name || 'Not assigned'}</p>
                                    </div>
                                    <span class="request-status status-${req.status}">${req.status.charAt(0).toUpperCase() + req.status.slice(1)}</span>
                                </div>
                                <div class="request-details">
                                    <span><i class="fas fa-calendar"></i> ${new Date(req.created_at).toLocaleDateString()}</span>
                                    <span><i class="fas fa-clock"></i> ${req.preferred_date || 'TBD'}</span>
                                    ${req.price ? `<span><i class="fas fa-dollar-sign"></i> ${parseFloat(req.price).toFixed(2)}</span>` : ''}
                                </div>
                            </div>
                        `).join('');
                    } else {
                        requestsContainer.innerHTML = '<p class="no-data">No service requests found</p>';
                    }
                    
                    // Populate activity history
                    const activityContainer = document.getElementById('detail_activity_history');
                    if (activities.length > 0) {
                        activityContainer.innerHTML = activities.map(activity => `
                            <div class="detail-activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-${getActivityIcon(activity.action)}"></i>
                                </div>
                                <div class="activity-content">
                                    <h5>${activity.action.replace(/_/g, ' ')}</h5>
                                    <p>${activity.details}</p>
                                    <small>${new Date(activity.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</small>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        activityContainer.innerHTML = '<p class="no-data">No activity history found</p>';
                    }
                    
                    // Open the modal
                    document.getElementById('detailModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load user details. Please try again.');
                });
        }
        
        function getActivityIcon(action) {
            const icons = {
                'EDIT_USER': 'edit',
                'ARCHIVE_USER': 'archive',
                'UNARCHIVE_USER': 'undo',
                'UPDATE_USER_STATUS': 'toggle-on',
                'LOGIN': 'sign-in-alt',
                'LOGOUT': 'sign-out-alt'
            };
            return icons[action] || 'history';
        }
        
        function closeDetailModal() {
            document.getElementById('detailModal').classList.remove('active');
        }
        
        function editUser(id) {
            // Fetch user data via AJAX
            fetch('get_user.php?id=' + id)
                .then(response => response.json())
                .then(user => {
                    if (user.error) {
                        alert('Error: ' + user.error);
                        return;
                    }
                    
                    // Populate the form with user data
                    document.getElementById('edit_user_id').value = user.id;
                    document.getElementById('edit_first_name').value = user.first_name;
                    document.getElementById('edit_last_name').value = user.last_name;
                    document.getElementById('edit_email').value = user.email;
                    document.getElementById('edit_phone').value = user.phone || '';
                    document.getElementById('edit_user_type').value = user.user_type;
                    
                    // Clear password fields
                    document.getElementById('edit_new_password').value = '';
                    document.getElementById('edit_confirm_password').value = '';
                    
                    // Open the modal
                    document.getElementById('editModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load user data. Please try again.');
                });
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const btn = input.nextElementSibling.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                btn.classList.remove('fa-eye');
                btn.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                btn.classList.remove('fa-eye-slash');
                btn.classList.add('fa-eye');
            }
        }
        
        // Validate password match on form submit
        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('edit_new_password').value;
            const confirmPassword = document.getElementById('edit_confirm_password').value;
            
            if (newPassword || confirmPassword) {
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match. Please check and try again.');
                    return false;
                }
                
                if (newPassword.length < 8) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters long.');
                    return false;
                }
            }
        });
        
        function changeStatus(id, currentStatus, name) {
            document.getElementById('status_user_id').value = id;
            document.getElementById('new_status').value = currentStatus;
            document.getElementById('statusModal').classList.add('active');
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }
        
        function archiveUser(id, name) {
            if (confirm('Are you sure you want to archive ' + name + '?\n\nArchived users can be restored later. They will not be able to log in while archived.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="user_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function restoreUser(id, name) {
            if (confirm('Are you sure you want to restore ' + name + '?\n\nThe user will be able to log in again.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="unarchive">
                    <input type="hidden" name="user_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('statusModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeStatusModal();
            }
        });
        
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailModal();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.animation = 'slideUp 0.3s ease';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>
