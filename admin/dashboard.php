<?php
// admin/dashboard.php
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';

// Try to load config
$config_paths = [
    __DIR__ . '/../config/config.php',
    __DIR__ . '/config/config.php',
    'config/config.php',
    '../config/config.php'
];

foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// Try to load database
$database = null;
$db = null;
$db_error = null;

try {
    $db_paths = [
        __DIR__ . '/../config/database.php',
        __DIR__ . '/config/database.php',
        'config/database.php',
        '../config/database.php'
    ];
    
    foreach ($db_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            if (class_exists('Database')) {
                $database = new Database();
                $db = $database->getConnection();
                break;
            }
        }
    }
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// Get dashboard statistics
$stats = [
    'total_users' => 0,
    'total_providers' => 0,
    'total_services' => 0,
    'total_requests' => 0,
    'pending_requests' => 0,
    'total_revenue' => 0
];

$recent_users = [];
$recent_requests = [];
$recent_providers = [];
$recent_logs = [];

// Fetch data if database is available
if ($db) {
    try {
        // Total Users
        $query = "SELECT COUNT(*) as total FROM users";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Total Providers
        $query = "SELECT COUNT(*) as total FROM providers";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $stats['total_providers'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Total Services
        $query = "SELECT COUNT(*) as total FROM service_listings";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $stats['total_services'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Total Service Requests
        $query = "SELECT COUNT(*) as total FROM service_requests";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $stats['total_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Pending Requests
        $query = "SELECT COUNT(*) as total FROM service_requests WHERE status = 'pending'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $stats['pending_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Total Revenue (estimated)
        $query = "SELECT SUM(price) as total FROM service_listings WHERE status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $stats['total_revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Recent Users
        $query = "SELECT * FROM users ORDER BY created_at DESC LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent Service Requests
        $query = "SELECT sr.*, u.first_name, u.last_name, p.company_name 
                  FROM service_requests sr
                  JOIN users u ON sr.seeker_id = u.id
                  LEFT JOIN providers p ON sr.provider_id = p.user_id
                  ORDER BY sr.created_at DESC 
                  LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent Providers
        $query = "SELECT p.*, u.email, u.created_at 
                  FROM providers p
                  JOIN users u ON p.user_id = u.id
                  ORDER BY p.created_at DESC 
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $recent_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // System Logs
        $query = "SELECT * FROM admin_logs ORDER BY created_at DESC LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $db_error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Pestify</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* All dashboard styles from previous version - included inline */
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --primary-light: #C6F6D5;
            --primary-soft: #F0FFF4;
            --secondary: #4C51BF;
            --secondary-dark: #434190;
            --neutral-dark: #1a1f3a;
            --neutral-gray: #718096;
            --neutral-light: #E2E8F0;
            --neutral-soft: #F7FAFC;
            --success: #38A169;
            --warning: #D69E2E;
            --danger: #E53E3E;
            --info: #3182CE;
            --radius-sm: 6px;
            --radius: 10px;
            --radius-md: 14px;
            --radius-lg: 18px;
            --radius-xl: 24px;
            --radius-full: 9999px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.09);
            --shadow-xl: 0 15px 30px rgba(0, 0, 0, 0.1);
            --transition: 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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
        
        /* Sidebar Styles */
        .admin-sidebar {
            width: 260px;
            background: var(--neutral-dark);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform var(--transition);
        }
        
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .admin-logo-small {
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
        
        .admin-info h3 {
            margin: 0;
            font-size: 1rem;
            color: white;
        }
        
        .admin-info p {
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
        
        .menu-item:hover, .menu-item.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary);
        }
        
        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }
        
        .menu-item span {
            font-size: 0.9375rem;
        }
        
        .menu-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }
        
        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }
        
        /* Main Content */
        .admin-main {
            flex: 1;
            margin-left: 260px;
            padding: 2rem;
            transition: margin-left var(--transition);
        }
        
        /* Top Bar */
        .admin-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
        }
        
        .page-title h1 {
            margin: 0;
            color: var(--neutral-dark);
            font-size: 1.75rem;
        }
        
        .page-title p {
            margin: 0.5rem 0 0;
            color: var(--neutral-gray);
            font-size: 0.9375rem;
        }
        
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-input {
            padding: 0.75rem 1rem 0.75rem 2.5rem;
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
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--neutral-gray);
        }
        
        .notification-btn {
            position: relative;
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--neutral-dark);
            cursor: pointer;
            transition: all var(--transition);
        }
        
        .notification-btn:hover {
            background: var(--neutral-soft);
            color: var(--primary);
        }
        
        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            font-size: 0.75rem;
            padding: 0.125rem 0.375rem;
            border-radius: var(--radius-full);
            border: 2px solid white;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            border-radius: var(--radius);
            cursor: pointer;
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
        
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--neutral-dark);
            font-size: 1.25rem;
        }
        
        /* Topbar Action Buttons */
        .topbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: all var(--transition);
            border: none;
            cursor: pointer;
        }
        
        .topbar-btn i {
            font-size: 1rem;
        }
        
        .topbar-btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(46, 139, 87, 0.25);
        }
        
        .topbar-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 139, 87, 0.35);
        }
        
        .topbar-btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .topbar-btn-secondary:hover {
            background: var(--primary-soft);
            transform: translateY(-2px);
        }
        
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--neutral-light);
            transition: all var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary-light);
        }
        
        .stat-icon {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 50px;
            height: 50px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            opacity: 0.1;
            background: var(--primary);
            color: white;
        }
        
        .stat-content h3 {
            margin: 0 0 0.5rem;
            color: var(--neutral-gray);
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--neutral-dark);
            margin-bottom: 0.5rem;
        }
        
        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .stat-change.positive {
            color: var(--success);
        }
        
        .stat-change.negative {
            color: var(--danger);
        }
        
        /* Charts Section */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .chart-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--neutral-light);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .chart-header h3 {
            margin: 0;
            color: var(--neutral-dark);
            font-size: 1.125rem;
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        /* Tables */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .table-card {
            background: white;
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-light);
            overflow: hidden;
        }
        
        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            margin: 0;
            color: var(--neutral-dark);
            font-size: 1.125rem;
        }
        
        .table-content {
            overflow-x: auto;
        }
        
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .admin-table th {
            padding: 1rem 1.5rem;
            text-align: left;
            background: var(--neutral-soft);
            color: var(--neutral-gray);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--neutral-light);
        }
        
        .admin-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
            color: var(--neutral-dark);
            font-size: 0.9375rem;
        }
        
        .admin-table tr:last-child td {
            border-bottom: none;
        }
        
        .admin-table tr:hover {
            background: var(--neutral-soft);
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-active {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .status-pending {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .status-inactive {
            background: #FEE2E2;
            color: #991B1B;
        }
        
        .status-completed {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        /* Action Buttons */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: var(--radius);
            background: none;
            border: none;
            cursor: pointer;
            transition: all var(--transition);
        }
        
        .action-view {
            color: var(--primary);
        }
        
        .action-view:hover {
            background: var(--primary-light);
        }
        
        .action-edit {
            color: var(--warning);
        }
        
        .action-edit:hover {
            background: var(--accent-light);
        }
        
        .action-delete {
            color: var(--danger);
        }
        
        .action-delete:hover {
            background: #FEE2E2;
        }
        
        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        
        .alert-warning {
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            color: #92400E;
        }
        
        .alert-warning i {
            color: #F59E0B;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 1024px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            .admin-sidebar.mobile-show {
                transform: translateX(0);
            }
            
            .admin-main {
                margin-left: 0;
            }
            
            .mobile-menu-toggle {
                display: flex;
            }
            
            .topbar-btn span {
                display: none;
            }
            
            .topbar-btn {
                padding: 0.75rem;
            }
        }
        
        @media (max-width: 768px) {
            .admin-main {
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .tables-grid {
                grid-template-columns: 1fr;
            }
            
            .topbar-btn {
                display: none;
            }
            
            .page-title h1 {
                font-size: 1.25rem;
            }
            
            .page-title p {
                font-size: 0.875rem;
            }
            
            .quick-actions {
                flex-direction: column;
            }
            
            .quick-action-btn {
                min-width: auto;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .topbar-actions {
                gap: 0.5rem;
            }
        }
        
        /* Database Error Styling */
        .db-error {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #DC2626;
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        .db-error i {
            color: #DC2626;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/login_success_alert.php'; ?>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="admin-logo-small">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="admin-info">
                    <h3>Pestify Admin</h3>
                    <p><?php echo $_SESSION['admin_username'] ?? 'Admin'; ?></p>
                </div>
            </div>
            
            <nav class="sidebar-menu">
                <div class="menu-section">
                    <h4>Dashboard</h4>
                    <a href="dashboard.php" class="menu-item active">
                        <i class="fas fa-chart-line"></i>
                        <span>Overview</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <h4>Content Management</h4>
                    <a href="users.php" class="menu-item">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                        <span class="menu-badge"><?php echo $stats['total_users']; ?></span>
                    </a>
                    <a href="providers.php" class="menu-item">
                        <i class="fas fa-building"></i>
                        <span>Providers</span>
                        <span class="menu-badge"><?php echo $stats['total_providers']; ?></span>
                    </a>
                    <a href="verify-providers.php" class="menu-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Verify Providers</span>
                        <?php 
                        // Get pending verification count
                        if ($db) {
                            try {
                                $pending_query = "SELECT COUNT(*) as total
                                                  FROM providers
                                                  WHERE COALESCE(NULLIF(verification_status, ''), CASE
                                                      WHEN status = 'active' THEN 'approved'
                                                      WHEN status = 'rejected' THEN 'rejected'
                                                      ELSE 'pending'
                                                  END) = 'pending'";
                                $pending_stmt = $db->prepare($pending_query);
                                $pending_stmt->execute();
                                $pending_verification = $pending_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                                if ($pending_verification > 0) {
                                    echo '<span class="menu-badge" style="background: #E53E3E;">' . $pending_verification . '</span>';
                                }
                            } catch (Exception $e) {}
                        }
                        ?>
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
            <!-- Top Bar -->
            <div class="admin-topbar">
                <button class="mobile-menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div class="page-title">
                    <h1>Dashboard Overview</h1>
                    <p>Welcome back, <?php echo $_SESSION['admin_username'] ?? 'Admin'; ?>!</p>
                </div>
                
                <div class="topbar-actions">
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
            
            <?php if($db_error): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Database Connection Issue:</strong> Some data may not load correctly.
                        <small style="display: block; margin-top: 0.5rem;">Error: <?php echo htmlspecialchars($db_error); ?></small>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Users</h3>
                        <div class="stat-number"><?php echo number_format($stats['total_users']); ?></div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span>Managing user accounts</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Service Providers</h3>
                        <div class="stat-number"><?php echo number_format($stats['total_providers']); ?></div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span>Verified companies</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-bug"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Active Services</h3>
                        <div class="stat-number"><?php echo number_format($stats['total_services']); ?></div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span>Available services</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Revenue</h3>
                        <div class="stat-number">₱<?php echo number_format($stats['total_revenue'], 2); ?></div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span>Estimated earnings</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Activity Overview</h3>
                        <select class="sort-select" style="width: auto; padding: 0.5rem; border: 1px solid var(--neutral-light); border-radius: var(--radius);">
                            <option>Last 7 Days</option>
                            <option>Last 30 Days</option>
                        </select>
                    </div>
                    <div class="chart-container">
                        <canvas id="userChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Request Status</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="requestChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Tables -->
            <div class="tables-grid">
                <!-- Recent Users -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>Recent Users</h3>
                        <a href="users.php" class="btn-outline" style="padding: 0.5rem 1rem; border: 1px solid var(--neutral-light); border-radius: var(--radius); text-decoration: none; color: var(--primary); font-size: 0.875rem;">View All</a>
                    </div>
                    <div class="table-content">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($recent_users) > 0): ?>
                                    <?php foreach($recent_users as $user): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $user['user_type'] == 'provider' ? 'status-active' : 'status-completed'; ?>">
                                                <?php echo ucfirst($user['user_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-active">
                                                Active
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--neutral-gray); padding: 2rem;">
                                            No users found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Recent Service Requests -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>Recent Service Requests</h3>
                        <a href="service-requests.php" class="btn-outline" style="padding: 0.5rem 1rem; border: 1px solid var(--neutral-light); border-radius: var(--radius); text-decoration: none; color: var(--primary); font-size: 0.875rem;">View All</a>
                    </div>
                    <div class="table-content">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Provider</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($recent_requests) > 0): ?>
                                    <?php foreach($recent_requests as $request): ?>
                                    <tr>
                                        <td>#<?php echo $request['id']; ?></td>
                                        <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['company_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge <?php 
                                                if($request['status'] == 'pending') echo 'status-pending';
                                                elseif($request['status'] == 'completed') echo 'status-completed';
                                                elseif($request['status'] == 'cancelled') echo 'status-inactive';
                                                else echo 'status-active';
                                            ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--neutral-gray); padding: 2rem;">
                                            No service requests found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <footer style="margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--neutral-light); text-align: center; color: var(--neutral-gray); font-size: 0.875rem;">
                <p>© <?php echo date('Y'); ?> Pestify Admin Dashboard.</p>
                <p style="margin-top: 0.5rem;">Logged in as: <?php echo $_SESSION['admin_username'] ?? 'Admin'; ?> | Session: Active</p>
            </footer>
        </main>
    </div>
    
    <script>
        // Mobile Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-show');
        });
        
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // User Registration Chart
            const userCtx = document.getElementById('userChart');
            if (userCtx) {
                new Chart(userCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        datasets: [{
                            label: 'Activity',
                            data: [65, 78, 66, 84, 105, 120, 98],
                            borderColor: '#2E8B57',
                            backgroundColor: 'rgba(46, 139, 87, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
            
            // Request Status Chart
            const requestCtx = document.getElementById('requestChart');
            if (requestCtx) {
                new Chart(requestCtx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Completed', 'Pending', 'Cancelled', 'In Progress'],
                        datasets: [{
                            data: [45, 23, 12, 20],
                            backgroundColor: ['#38A169', '#D69E2E', '#E53E3E', '#3182CE'],
                            borderWidth: 2,
                            borderColor: 'white'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
            
            // Search functionality
            const searchInput = document.querySelector('.search-input');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        alert('Search functionality would be implemented here.');
                    }
                });
            }
        });
        
        // Auto-refresh dashboard every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 5 * 60 * 1000); // 5 minutes
    </script>
</body>
</html>
