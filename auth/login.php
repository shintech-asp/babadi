<?php
chdir(dirname(__DIR__));
// login.php - Complete Version
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$error = '';
$success = '';

function safeRedirectPath(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    // Only allow local relative paths.
    if (preg_match('#^(https?:)?//#i', $path)) return '';
    if (strpos($path, "\n") !== false || strpos($path, "\r") !== false) return '';
    return ltrim($path, '/');
}

$requested_redirect = safeRedirectPath($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));

// Check if already logged in
if (isset($_SESSION['admin_id']) && ($_SESSION['admin_logged_in'] ?? false)) {
    if (($_SESSION['must_change_password'] ?? 0) == 1) {
        header("Location: " . appUrl('admin/change-password.php'));
    } else {
        $__r = $_SESSION['admin_role'] ?? 'admin';
        $adminDashboardPath = ($__r === 'super_admin') ? 'admin/super-admin-dashboard.php'
                            : (($__r === 'hr')        ? 'admin/hr/dashboard.php'
                            : (($__r === 'finance')   ? 'admin/finance/dashboard.php'
                            : 'admin/dashboard.php'));
        header("Location: " . appUrl($adminDashboardPath));
    }
    exit();
}

if (isset($_SESSION['portal_staff_id'])) {
    header("Location: " . appUrl(
        !empty($_SESSION['portal_must_change']) ? 'provider-portal/change-password.php' : 'provider-portal/dashboard.php'
    ));
    exit();
}

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] == 'seeker' && $requested_redirect !== '') {
        header("Location: " . appUrl($requested_redirect));
        exit();
    } elseif ($_SESSION['user_type'] == 'provider') {
        if ($requested_redirect !== '') {
            header("Location: " . appUrl($requested_redirect));
        } else {
            header("Location: " . appUrl('providers-dashboard.php'));
        }
        exit();
    } elseif ($_SESSION['user_type'] == 'admin') {
        $adminDashboardPath = (($_SESSION['admin_role'] ?? '') === 'super_admin')
            ? 'admin/super-admin-dashboard.php'
            : 'admin/dashboard.php';
        header("Location: " . appUrl($adminDashboardPath));
        exit();
    } else {
        header("Location: " . appUrl('index.php'));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        $error = "Database connection failed. Please try again later. " . $database->getError();
    } else {
        $identifier = trim((string)($_POST['email'] ?? ''));
        $password = trim($_POST['password']);

        if ($identifier === '') {
            $error = "Please enter your email address or username.";
        } else {
            try {
                $adminStmt = $db->prepare(
                    "SELECT id, username, email, password_hash, role, department,
                            full_name, must_change_password, temp_password, status
                     FROM admin_users
                     WHERE (username = :identifier OR email = :identifier)
                       AND status = 'active'
                     LIMIT 1"
                );
                $adminStmt->execute([':identifier' => $identifier]);
                $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

                if ($admin) {
                    $adminPasswordValid = false;

                    if (!empty($admin['password_hash']) && password_verify($password, $admin['password_hash'])) {
                        $adminPasswordValid = true;
                    } elseif (!empty($admin['temp_password']) && hash_equals((string)$admin['temp_password'], $password)) {
                        $adminPasswordValid = true;
                        $admin['must_change_password'] = 1;
                    }

                    if ($adminPasswordValid) {
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_email'] = $admin['email'];
                        $_SESSION['admin_role'] = $admin['role'];
                        $_SESSION['admin_dept'] = $admin['department'] ?? 'all';
                        $_SESSION['admin_full_name'] = $admin['full_name'] ?? $admin['username'];
                        $_SESSION['must_change_password'] = $admin['must_change_password'] ?? 0;
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['login_time'] = time();

                        try {
                            $checkTable = $db->query("SHOW TABLES LIKE 'admin_logs'");
                            if ($checkTable->rowCount() > 0) {
                                // admin_logs has no user_agent column — folded into
                                // details instead of being silently dropped.
                                $logStmt = $db->prepare(
                                    "INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at)
                                     VALUES (:admin_id, 'LOGIN', :details, :ip_address, NOW())"
                                );
                                $logStmt->execute([
                                    ':admin_id' => $admin['id'],
                                    ':details' => 'Admin logged in via shared login page — UA: ' . ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                                    ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
                                ]);
                            }
                        } catch (Exception $e) {
                            error_log("Admin login log failed: " . $e->getMessage());
                        }

                        if (!empty($admin['temp_password']) && hash_equals((string)$admin['temp_password'], $password)) {
                            try {
                                $updateStmt = $db->prepare("UPDATE admin_users SET temp_password = NULL WHERE id = :id");
                                $updateStmt->execute([':id' => $admin['id']]);
                            } catch (Exception $e) {}
                        }

                        if (($_SESSION['must_change_password'] ?? 0) == 1) {
                            header("Location: " . appUrl('admin/change-password.php'));
                        } else {
                            $__r = $admin['role'] ?? 'admin';
                            $adminDashboardPath = ($__r === 'super_admin') ? 'admin/super-admin-dashboard.php'
                                                : (($__r === 'hr')        ? 'admin/hr/dashboard.php'
                                                : (($__r === 'finance')   ? 'admin/finance/dashboard.php'
                                                : 'admin/dashboard.php'));
                            header("Location: " . appUrl($adminDashboardPath));
                        }
                        exit();
                    }
                }

                // Portal staff check — accepts username or email
                $staffStmt = $db->prepare(
                    "SELECT ps.*, p.company_name
                     FROM provider_staff ps
                     JOIN providers p ON p.id = ps.provider_id
                     WHERE (ps.username = :identifier OR ps.email = :identifier)
                       AND ps.status = 'active'
                     LIMIT 1"
                );
                $staffStmt->execute([':identifier' => $identifier]);
                $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

                if ($staff) {
                    $staffPasswordValid = false;
                    $staffUsedTemp      = false;

                    if (!empty($staff['password_hash']) && password_verify($password, $staff['password_hash'])) {
                        $staffPasswordValid = true;
                    } elseif (!empty($staff['temp_password']) && hash_equals((string)$staff['temp_password'], $password)) {
                        $staffPasswordValid = true;
                        $staffUsedTemp      = true;
                    }

                    if ($staffPasswordValid) {
                        $must_change = $staffUsedTemp ? 1 : (int)($staff['must_change_password'] ?? 0);

                        // A promoted staff member may also have a matching HR
                        // employee record (e.g. auto-created by staff.php, or
                        // linked by email) — carry that over so they can also
                        // use employee self-service features (timekeeping,
                        // payslips) under their own staff login.
                        $linkedEmp = null;
                        try {
                            $linkStmt = $db->prepare(
                                "SELECT id, staff_type FROM employees WHERE provider_id = :pid AND email = :email LIMIT 1"
                            );
                            $linkStmt->execute([':pid' => (int)$staff['provider_id'], ':email' => $staff['email']]);
                            $linkedEmp = $linkStmt->fetch(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {}

                        $_SESSION['portal_staff_id']     = $staff['id'];
                        $_SESSION['portal_provider_id']  = (int)$staff['provider_id'];
                        $_SESSION['portal_role']         = $staff['role'];
                        $_SESSION['portal_dept']         = $staff['department'];
                        $_SESSION['portal_full_name']    = $staff['full_name'];
                        $_SESSION['portal_company']      = $staff['company_name'];
                        $_SESSION['portal_must_change']  = $must_change;
                        $_SESSION['portal_account_type'] = 'staff';
                        $_SESSION['portal_employee_id']  = $linkedEmp ? (int)$linkedEmp['id'] : 0;
                        $_SESSION['portal_staff_type']   = $linkedEmp['staff_type'] ?? 'office';

                        if ($staffUsedTemp && !$staff['must_change_password']) {
                            $db->prepare("UPDATE provider_staff SET must_change_password=1 WHERE id=:id")
                               ->execute([':id' => $staff['id']]);
                        }

                        $portalDest = $must_change
                            ? 'provider-portal/change-password.php'
                            : 'provider-portal/dashboard.php';
                        header('Location: ' . appUrl($portalDest));
                        exit();
                    }
                }

                // Employee self-service login (not promoted to portal staff) —
                // accepts email or employee_id code (e.g. "EMP-XXX-1234").
                // Reuses the provider-portal session namespace (portal_*):
                // provider-portal/timekeeping.php, change-password.php and
                // crm-requests.php already branch on portal_account_type ===
                // 'employee' / portal_employee_id — this just completes the
                // login path those were built to expect.
                $empStmt = $db->prepare(
                    "SELECT e.*, p.company_name
                     FROM employees e
                     JOIN providers p ON p.id = e.provider_id
                     WHERE (e.email = :identifier OR e.employee_id = :identifier)
                       AND e.status = 'active'
                     LIMIT 1"
                );
                $empStmt->execute([':identifier' => $identifier]);
                $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empPasswordValid = false;
                    $empUsedTemp      = false;

                    if (!empty($emp['password_hash']) && password_verify($password, $emp['password_hash'])) {
                        $empPasswordValid = true;
                    } elseif (!empty($emp['temp_password']) && hash_equals((string)$emp['temp_password'], $password)) {
                        $empPasswordValid = true;
                        $empUsedTemp      = true;
                    }

                    if ($empPasswordValid) {
                        $must_change = $empUsedTemp ? 1 : (int)($emp['must_change_pwd'] ?? 0);

                        // portal_staff_id has no real provider_staff row for a
                        // plain employee — set to 0 as a "logged in, but not
                        // staff" marker (portal-auth.php only checks isset()).
                        $_SESSION['portal_staff_id']     = 0;
                        $_SESSION['portal_provider_id']  = (int)$emp['provider_id'];
                        $_SESSION['portal_role']         = 'employee';
                        $_SESSION['portal_dept']         = $emp['department'];
                        $_SESSION['portal_full_name']    = trim($emp['first_name'] . ' ' . $emp['last_name']);
                        $_SESSION['portal_company']      = $emp['company_name'];
                        $_SESSION['portal_must_change']  = $must_change;
                        $_SESSION['portal_account_type'] = 'employee';
                        $_SESSION['portal_employee_id']  = (int)$emp['id'];
                        $_SESSION['portal_staff_type']   = $emp['staff_type'] ?? 'office';

                        if ($empUsedTemp && !$emp['must_change_pwd']) {
                            $db->prepare("UPDATE employees SET must_change_pwd=1 WHERE id=:id")
                               ->execute([':id' => $emp['id']]);
                        }

                        $portalDest = $must_change
                            ? 'provider-portal/change-password.php'
                            : 'provider-portal/dashboard.php';
                        header('Location: ' . appUrl($portalDest));
                        exit();
                    }
                }

                if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email, username, or password!";
                } else {
                    $query = "SELECT id, email, password, user_type, first_name, last_name, address_completed FROM users WHERE email = :email";
                    $stmt  = $db->prepare($query);
                    $stmt->bindParam(':email', $identifier);
                    $stmt->execute();

                    if ($stmt->rowCount() == 1) {
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (password_verify($password, $user['password'])) {
                            // Set session variables
                            $_SESSION['user_id']    = $user['id'];
                            $_SESSION['email']      = $user['email'];
                            $_SESSION['user_type']  = $user['user_type'];
                            $_SESSION['full_name']  = $user['first_name'] . ' ' . $user['last_name'];
                            $_SESSION['first_name'] = $user['first_name'];
                            $_SESSION['last_name']  = $user['last_name'];

                            // -- Set welcome flash (consumed once on next page) --
                            $_SESSION['welcome_flash'] = $user['first_name'];

                            // Update last login time
                            $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
                            $updateStmt->bindParam(':id', $user['id']);
                            $updateStmt->execute();

                            // Check if seeker needs to complete address
                            if ($user['user_type'] == 'seeker' && $user['address_completed'] == 0) {
                                header("Location: " . appUrl('setup-address.php'));
                                exit();
                            }

                            if ($user['user_type'] == 'provider') {
                                try {
                                    $ps = $db->prepare("SELECT id, company_name, status FROM providers WHERE user_id = :user_id");
                                    $ps->bindParam(':user_id', $user['id']);
                                    $ps->execute();
                                    if ($ps->rowCount() > 0) {
                                        $provider = $ps->fetch(PDO::FETCH_ASSOC);
                                        $_SESSION['provider_id']     = $provider['id'];
                                        $_SESSION['company_name']    = $provider['company_name'];
                                        $_SESSION['provider_status'] = $provider['status'];
                                    }
                                } catch (PDOException $ex) {
                                    error_log("Provider details fetch error: " . $ex->getMessage());
                                }
                                if ($requested_redirect !== '') {
                                    header("Location: " . appUrl($requested_redirect));
                                } else {
                                    header("Location: " . appUrl('providers-dashboard.php'));
                                }
                                exit();

                            } elseif ($user['user_type'] == 'admin') {
                                $adminDashboardPath = (($_SESSION['admin_role'] ?? '') === 'super_admin')
                                    ? 'admin/super-admin-dashboard.php'
                                    : 'admin/dashboard.php';
                                header("Location: " . appUrl($adminDashboardPath));
                                exit();

                            } else {
                                if ($requested_redirect !== '') {
                                    header("Location: " . appUrl($requested_redirect));
                                } else {
                                    header("Location: " . appUrl('index.php'));
                                }
                                exit();
                            }
                        }
                    }

                    $error = "Invalid email, username, or password!";
                }
            } catch (PDOException $e) {
                $error = "Database error. Please try again later.";
                error_log("Login error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .login-container {
            padding: 40px 20px;
            background: #f5f5f5;
            min-height: calc(100vh - 200px);
        }
        .login-wrapper {
            display: flex;
            gap: 60px;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            min-height: calc(100vh - 200px);
        }
        .login-left  { flex: 1; padding: 40px 0; }
        .login-right {
            flex: 1; background: #fff; border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1); padding: 40px;
        }
        .login-hero h1 { font-size: 42px; color: #333; margin-bottom: 20px; line-height: 1.2; }
        .login-hero p  { font-size: 18px; color: #666; margin-bottom: 30px; line-height: 1.6; }
        .features-list { list-style: none; padding: 0; margin-top: 40px; }
        .features-list li { padding: 15px 0; display: flex; align-items: center; gap: 15px; font-size: 16px; color: #555; }
        .features-list i  { color: #2c5aa0; font-size: 20px; width: 30px; }
        .login-form h2 { color: #333; margin-bottom: 30px; font-size: 28px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        .form-control { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: #2c5aa0; box-shadow: 0 0 0 3px rgba(44,90,160,0.1); }
        .remember-forgot { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .remember-me { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #666; }
        .remember-me input[type="checkbox"] { cursor: pointer; }
        .forgot-password { color: #2c5aa0; text-decoration: none; font-size: 14px; font-weight: 500; }
        .forgot-password:hover { text-decoration: underline; }
        .btn-login { background: #2c5aa0; color: white; border: none; padding: 14px; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; transition: background 0.3s; }
        .btn-login:hover { background: #1e4070; }
        .btn-login:disabled { opacity: 0.7; cursor: not-allowed; }
        .divider { text-align: center; margin: 25px 0; color: #999; font-size: 14px; position: relative; }
        .divider::before, .divider::after { content: ''; position: absolute; top: 50%; width: 40%; height: 1px; background: #ddd; }
        .divider::before { left: 0; }
        .divider::after  { right: 0; }
        .register-link { text-align: center; color: #666; margin-top: 25px; }
        .register-link a { color: #2c5aa0; text-decoration: none; font-weight: 500; }
        .register-link a:hover { text-decoration: underline; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 25px; }
        .alert-error   { background: #fee; border: 1px solid #fcc; color: #c00; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #090; }
        @media (max-width: 992px) { .login-wrapper { flex-direction: column; gap: 40px; } .login-left { text-align: center; } .features-list li { justify-content: center; } }
        @media (max-width: 576px) { .login-container { padding: 20px 15px; } .login-right { padding: 30px 20px; } .login-hero h1 { font-size: 32px; } .login-hero p { font-size: 16px; } }
    </style>
</head>
<body>
    <?php include appPath('includes/header.php'); ?>
    
    <div class="login-container">
        <div class="login-wrapper">
            <div class="login-left">
                <div class="login-hero">
                    <h1>Welcome Back to Pestify!</h1>
                    <p>Sign in to access your account and connect with professional pest control services.</p>
                </div>
            </div>
            
            <div class="login-right">
                <div class="login-form">
                    <h2>Login to Your Account</h2>
                    
                    <?php if($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($_GET['registered']) && $_GET['registered'] == '1'): ?>
                        <div class="alert alert-success">Registration successful! Please login.</div>
                    <?php endif; ?>
                    
                    <?php if(isset($_GET['logout']) && $_GET['logout'] == '1'): ?>
                        <div class="alert alert-success">Logged out successfully!</div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="loginForm">
                        <?php if ($requested_redirect !== ''): ?>
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($requested_redirect); ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Email Address or Username *</label>
                            <input type="text" name="email" required class="form-control"
                                   placeholder="Enter your email or admin username"
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                   autocomplete="username">
                        </div>
                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" name="password" required class="form-control"
                                   placeholder="Enter your password"
                                   autocomplete="current-password">
                        </div>
                        <div class="remember-forgot">
                            <div class="remember-me">
                                <input type="checkbox" name="remember" value="1" id="remember">
                                <label for="remember">Remember me</label>
                            </div>
                            <a href="<?php echo appUrl('forgot-password.php'); ?>" class="forgot-password">Forgot password?</a>
                        </div>
                        <button type="submit" class="btn-login" id="loginBtn">
                            Login to Account
                        </button>
                    </form>
                    
                    <div class="divider">OR</div>
                    
                    <div class="register-link">
                        <p>Don't have an account? <a href="<?php echo appUrl('register.php'); ?>">Create Account</a></p>
                        <p style="margin-top:10px;"><a href="<?php echo appUrl('index.php'); ?>"><i class="fas fa-home"></i> Home</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
            btn.disabled = true;
            setTimeout(function() { if (btn.disabled) { btn.innerHTML = orig; btn.disabled = false; } }, 5000);
        });
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
