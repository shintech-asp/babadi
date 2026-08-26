<?php
// admin/settings.php
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_site':
                // Update site settings
                $success_message = "Site settings updated successfully!";
                break;
                
            case 'update_email':
                // Update email settings
                $success_message = "Email settings updated successfully!";
                break;
                
            case 'change_password':
                // Change admin password
                $current_password = $_POST['current_password'];
                $new_password = $_POST['new_password'];
                $confirm_password = $_POST['confirm_password'];
                
                if ($new_password === $confirm_password) {
                    // Here you would verify current password and update
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "New passwords do not match!";
                }
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Pestify Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --primary-light: #C6F6D5;
            --primary-soft: #F0FFF4;
            --secondary: #4C51BF;
            --neutral-dark: #2D3748;
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
            --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.09);
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
        }
        
        /* Main Content */
        .admin-main {
            flex: 1;
            margin-left: 260px;
            padding: 2rem;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--neutral-light);
        }
        
        .page-title h1 {
            margin: 0;
            font-size: 1.75rem;
            color: var(--neutral-dark);
        }
        
        .page-title p {
            margin: 0.5rem 0 0;
            color: var(--neutral-gray);
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: all var(--transition);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        /* Settings Grid */
        .settings-grid {
            display: grid;
            gap: 1.5rem;
        }
        
        .settings-card {
            background: white;
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-light);
            overflow: hidden;
        }
        
        .settings-card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--neutral-light);
            background: var(--neutral-soft);
        }
        
        .settings-card-header h3 {
            margin: 0;
            font-size: 1.125rem;
            color: var(--neutral-dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .settings-card-header p {
            margin: 0.5rem 0 0;
            font-size: 0.875rem;
            color: var(--neutral-gray);
        }
        
        .settings-card-body {
            padding: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group:last-child {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--neutral-dark);
            font-size: 0.9375rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--neutral-light);
            border-radius: var(--radius);
            font-size: 0.9375rem;
            transition: all var(--transition);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1);
        }
        
        .form-text {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.8125rem;
            color: var(--neutral-gray);
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--neutral-light);
        }
        
        .btn-secondary {
            padding: 0.75rem 1.5rem;
            background: var(--neutral-soft);
            color: var(--neutral-dark);
            border: 2px solid var(--neutral-light);
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: all var(--transition);
        }
        
        .btn-secondary:hover {
            background: var(--neutral-light);
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
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
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 28px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--neutral-light);
            transition: 0.4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: var(--primary);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(22px);
        }
        
        .toggle-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid var(--neutral-light);
        }
        
        .toggle-group:last-child {
            border-bottom: none;
        }
        
        .toggle-info h4 {
            margin: 0;
            font-size: 0.9375rem;
            color: var(--neutral-dark);
        }
        
        .toggle-info p {
            margin: 0.25rem 0 0;
            font-size: 0.8125rem;
            color: var(--neutral-gray);
        }
        
        @media (max-width: 1024px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            .admin-main {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
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
                    <a href="dashboard.php" class="menu-item">
                        <i class="fas fa-chart-line"></i>
                        <span>Overview</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <h4>Content Management</h4>
                    <a href="users.php" class="menu-item">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                    <a href="providers.php" class="menu-item">
                        <i class="fas fa-building"></i>
                        <span>Providers</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <h4>System</h4>
                    <a href="settings.php" class="menu-item active">
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
                    <h1>Settings</h1>
                    <p>Manage your system configuration and preferences</p>
                </div>
                
                <a href="dashboard.php" class="btn-primary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
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
            
            <!-- Settings Grid -->
            <div class="settings-grid">
                <!-- Site Settings -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3>
                            <i class="fas fa-globe"></i>
                            Site Settings
                        </h3>
                        <p>Configure basic site information</p>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_site">
                            
                            <div class="form-group">
                                <label for="site_name">Site Name</label>
                                <input type="text" id="site_name" name="site_name" class="form-control" value="Pestify" required>
                                <small class="form-text">The name of your website</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="site_description">Site Description</label>
                                <textarea id="site_description" name="site_description" class="form-control" rows="3">Professional Pest Control Services Platform</textarea>
                                <small class="form-text">Brief description of your site</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="contact_email">Contact Email</label>
                                <input type="email" id="contact_email" name="contact_email" class="form-control" value="admin@pestify.com" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="contact_phone">Contact Phone</label>
                                <input type="tel" id="contact_phone" name="contact_phone" class="form-control" value="+63 123 456 7890">
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-save"></i>
                                    Save Changes
                                </button>
                                <button type="reset" class="btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Email Settings -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3>
                            <i class="fas fa-envelope"></i>
                            Email Configuration
                        </h3>
                        <p>Configure SMTP settings for sending emails</p>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_email">
                            
                            <div class="form-group">
                                <label for="smtp_host">SMTP Host</label>
                                <input type="text" id="smtp_host" name="smtp_host" class="form-control" value="smtp.gmail.com">
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_port">SMTP Port</label>
                                <input type="number" id="smtp_port" name="smtp_port" class="form-control" value="587">
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_username">SMTP Username</label>
                                <input type="text" id="smtp_username" name="smtp_username" class="form-control" value="admin@pestify.com">
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_password">SMTP Password</label>
                                <input type="password" id="smtp_password" name="smtp_password" class="form-control" placeholder="••••••••">
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-save"></i>
                                    Save Changes
                                </button>
                                <button type="button" class="btn-secondary" onclick="testEmail()">
                                    <i class="fas fa-paper-plane"></i>
                                    Test Email
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Security Settings -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3>
                            <i class="fas fa-lock"></i>
                            Security Settings
                        </h3>
                        <p>Change your admin password</p>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-control" required>
                                <small class="form-text">Minimum 8 characters</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-key"></i>
                                    Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- System Preferences -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3>
                            <i class="fas fa-sliders-h"></i>
                            System Preferences
                        </h3>
                        <p>Toggle system features on or off</p>
                    </div>
                    <div class="settings-card-body">
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>User Registration</h4>
                                <p>Allow new users to register on the platform</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Email Notifications</h4>
                                <p>Send email notifications for important events</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Maintenance Mode</h4>
                                <p>Put the site in maintenance mode</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-info">
                                <h4>Provider Verification</h4>
                                <p>Require admin approval for new providers</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        function testEmail() {
            alert('Test email functionality would be implemented here.\n\nThis would send a test email to verify SMTP settings.');
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const passwordForm = document.querySelector('form[action=""] input[name="action"][value="change_password"]')?.closest('form');
            
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
                    const newPassword = document.getElementById('new_password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    
                    if (newPassword.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long!');
                        return false;
                    }
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New passwords do not match!');
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html>
