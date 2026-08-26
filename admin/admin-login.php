<?php
// admin/admin-login.php — Redirects to the central Pestify login page.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
header('Location: ' . appUrl('auth/login.php'));
exit();

// (Legacy code below — kept for reference but never reached)
session_start();

// Try different possible paths for config file
$config_paths = [
    __DIR__ . '/../config/config.php',  // For admin directory
    __DIR__ . '/config/config.php',     // If config is in admin folder
    'config/config.php',                // Relative path
    '../config/config.php'              // Relative from admin
];

$config_loaded = false;
foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $config_loaded = true;
        break;
    }
}

if (!$config_loaded) {
    die("Configuration file not found. Please check the config.php file exists.");
}

// Also load database if needed
$database_paths = [
    __DIR__ . '/../config/database.php',
    __DIR__ . '/config/database.php',
    'config/database.php',
    '../config/database.php'
];

$database_loaded = false;
foreach ($database_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $database_loaded = true;
        break;
    }
}

// Check if already logged in
if (isset($_SESSION['admin_id'])) {
    // Check if password needs to be changed
    if (isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] == 1) {
        header("Location: change-password.php");
        exit();
    }
    $__role = $_SESSION['admin_role'] ?? 'admin';
    $__dest = ($__role === 'super_admin') ? 'super-admin-dashboard.php'
            : (($__role === 'hr')      ? 'hr/dashboard.php'
            : (($__role === 'finance') ? 'finance/dashboard.php'
            : 'dashboard.php'));
    header('Location: ' . $__dest);
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = htmlspecialchars(trim($_POST['username']));
    $password = $_POST['password'];
    
    // Try database authentication first if database is available
    if ($database_loaded && class_exists('Database')) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Query to get admin user with role and department
            $query = "SELECT id, username, email, password_hash, role, department,
                            full_name, must_change_password, temp_password, status
                     FROM admin_users
                     WHERE (username = :username OR email = :username)
                       AND status = 'active'
                     LIMIT 1";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check if using temporary password
                $password_valid = false;
                
                // Verify password (check both regular password and temp password)
                if (!empty($admin['password_hash']) && password_verify($password, $admin['password_hash'])) {
                    $password_valid = true;
                } elseif (!empty($admin['temp_password']) && $password === $admin['temp_password']) {
                    $password_valid = true;
                    // Force password change if using temp password
                    $admin['must_change_password'] = 1;
                }
                
                if ($password_valid) {
                    // Set session variables
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role'];
                    $_SESSION['admin_dept'] = $admin['department'] ?? 'all';
                    $_SESSION['admin_full_name'] = $admin['full_name'] ?? $admin['username'];
                    $_SESSION['must_change_password'] = $admin['must_change_password'] ?? 0;
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    // Log the login
                    try {
                        // Check if admin_logs table exists
                        $checkTable = $db->query("SHOW TABLES LIKE 'admin_logs'");
                        if ($checkTable->rowCount() > 0) {
                            $query = "INSERT INTO admin_logs (admin_id, action, details, ip_address, user_agent, created_at) 
                                      VALUES (:admin_id, 'LOGIN', 'Admin logged in', :ip_address, :user_agent, NOW())";
                            $logStmt = $db->prepare($query);
                            $logStmt->execute([
                                ':admin_id' => $admin['id'],
                                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                            ]);
                        }
                    } catch (Exception $e) {
                        // Continue even if logging fails
                        error_log("Login log failed: " . $e->getMessage());
                    }
                    
                    // Clear temp password if used
                    if (!empty($admin['temp_password']) && $password === $admin['temp_password']) {
                        try {
                            $updateQuery = "UPDATE admin_users SET temp_password = NULL WHERE id = :id";
                            $updateStmt = $db->prepare($updateQuery);
                            $updateStmt->execute([':id' => $admin['id']]);
                        } catch (Exception $e) {
                            // Continue even if update fails
                        }
                    }
                    
                    // Redirect based on password change requirement
                    if ($_SESSION['must_change_password'] == 1) {
                        header("Location: change-password.php");
                    } else {
                        $__r = $admin['role'] ?? 'admin';
                        $__d = ($__r === 'super_admin') ? 'super-admin-dashboard.php'
                             : (($__r === 'hr')      ? 'hr/dashboard.php'
                             : (($__r === 'finance') ? 'finance/dashboard.php'
                             : 'dashboard.php'));
                        header('Location: ' . $__d);
                    }
                    exit();
                } else {
                    $error = "Invalid username or password!";
                }
            } else {
                $error = "Invalid username or password!";
            }
        } catch (Exception $e) {
            error_log("Database login failed: " . $e->getMessage());
            $error = "Database connection error. Admin login is unavailable right now.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Pestify</title>
    <style>
        /* Inline styles to avoid CSS path issues */
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --neutral-dark: #2D3748;
            --neutral-gray: #718096;
            --neutral-light: #E2E8F0;
            --neutral-soft: #F7FAFC;
            --danger: #E53E3E;
            --success: #38A169;
            --warning: #D69E2E;
            --radius: 10px;
            --radius-xl: 20px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.1);
            --transition: 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, var(--neutral-dark) 0%, var(--primary-dark) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .admin-login-box {
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            width: 100%;
            max-width: 400px;
            padding: 3rem;
            position: relative;
            overflow: hidden;
        }
        
        .admin-login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }
        
        .admin-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .admin-logo-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.75rem;
            margin: 0 auto 1rem;
            box-shadow: var(--shadow);
        }
        
        .admin-logo h1 {
            color: var(--neutral-dark);
            margin-bottom: 0.5rem;
            font-size: 1.75rem;
        }
        
        .admin-logo p {
            color: var(--neutral-gray);
            font-size: 0.875rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .alert-error {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #DC2626;
        }
        
        .alert-error i {
            color: #DC2626;
        }
        
        .alert-success {
            background: #F0FDF4;
            border: 1px solid #86EFAC;
            color: #166534;
        }
        
        .alert-info {
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            color: #1E40AF;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--neutral-dark);
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
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
        
        .btn-login {
            width: 100%;
            padding: 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .admin-login-footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--neutral-light);
            color: var(--neutral-gray);
            font-size: 0.875rem;
        }
        
        .admin-login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .admin-login-footer a:hover {
            text-decoration: underline;
        }
        
        .security-note {
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.8125rem;
            color: #92400E;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        
        .security-note i {
            color: #F59E0B;
            margin-top: 0.125rem;
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 480px) {
            .admin-login-box {
                padding: 2rem;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="admin-login-box">
        <div class="admin-logo">
            <div class="admin-logo-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1>Pestify Admin</h1>
            <p>Administrator Access Only</p>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> 
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="loginForm">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" name="username" id="username" required 
                       class="form-control" placeholder="Enter username or email"
                       autocomplete="username"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required 
                       class="form-control" placeholder="Enter password"
                       autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn-login" id="loginBtn">
                <i class="fas fa-sign-in-alt"></i> Login to Dashboard
            </button>
        </form>
        
        <div class="security-note">
            <i class="fas fa-lock"></i>
            <div>
                <strong>Security Notice:</strong> This area is restricted to authorized personnel only.
            </div>
        </div>
        
        <div class="admin-login-footer">
            <p>© <?php echo date('Y'); ?> Pestify. All rights reserved.</p>
            <p style="margin-top: 0.5rem;">
                <a href="../index.php"><i class="fas fa-arrow-left"></i> Back to Main Site</a>
            </p>
        </div>
    </div>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const icon = btn.querySelector('i');
            
            btn.innerHTML = '<i class="fas fa-spinner spinner"></i> Authenticating...';
            btn.disabled = true;
        });
        
        // Auto-focus on username field
        document.getElementById('username').focus();
        
        // Show/hide password functionality (optional)
        const passwordInput = document.getElementById('password');
        const togglePassword = document.createElement('i');
        togglePassword.className = 'fas fa-eye';
        togglePassword.style.cssText = 'position: absolute; right: 1rem; top: 2.5rem; cursor: pointer; color: var(--neutral-gray);';
        
        const passwordGroup = passwordInput.parentElement;
        passwordGroup.style.position = 'relative';
        passwordGroup.appendChild(togglePassword);
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
        });
    </script>
</body>
</html>
