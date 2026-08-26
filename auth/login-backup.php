<?php
chdir(dirname(__DIR__));
// login.php - Complete Version
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$error = '';
$success = '';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect based on user type
    if ($_SESSION['user_type'] == 'provider') {
        header("Location: " . appUrl('providers-dashboard.php'));
        exit();
    } elseif ($_SESSION['user_type'] == 'admin') {
        header("Location: " . appUrl('admin/dashboard.php'));
        exit();
    } else {
        header("Location: " . appUrl('index.php'));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if database connection was successful
    if (!$db) {
        $error = "Database connection failed. Please try again later. " . $database->getError();
    } else {
        $email = sanitize($_POST['email']);
        $password = $_POST['password'];
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                $query = "SELECT id, email, password, user_type, first_name, last_name, address_completed FROM users WHERE email = :email";
                $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($password, $user['password'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    
                    // Update last login time
                    $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':id', $user['id']);
                    $updateStmt->execute();
                    
                    // Check if seeker needs to complete address
                    if ($user['user_type'] == 'seeker' && $user['address_completed'] == 0) {
                        header("Location: " . appUrl('setup-address.php'));
                        exit();
                    }
                    
                    // Route based on user type
                    if ($user['user_type'] == 'provider') {
                        // Try to get provider details, but don't block login on failure
                        try {
                            $q = "SELECT id, company_name, status FROM providers WHERE user_id = :user_id";
                            $ps = $db->prepare($q);
                            $ps->bindParam(':user_id', $user['id']);
                            $ps->execute();
                            
                            if ($ps->rowCount() > 0) {
                                $provider = $ps->fetch(PDO::FETCH_ASSOC);
                                $_SESSION['provider_id'] = $provider['id'];
                                $_SESSION['company_name'] = $provider['company_name'];
                                $_SESSION['provider_status'] = $provider['status'];
                            }
                        } catch (PDOException $ex) {
                            error_log("Provider details fetch error: " . $ex->getMessage());
                        }
                        
                        header("Location: " . appUrl('providers-dashboard.php'));
                        exit();
                        
                    } elseif ($user['user_type'] == 'admin') {
                        header("Location: " . appUrl('admin/dashboard.php'));
                        exit();
                        
                    } else {
                        // Regular user/seeker
                        header("Location: " . appUrl('index.php'));
                        exit();
                    }
                } else {
                    $error = "Invalid email or password!";
                }
            } else {
                $error = "Invalid email or password!";
            }
        } catch (PDOException $e) {
            $error = "Database error. Please try again later.";
            error_log("Login error: " . $e->getMessage());
        }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
<link rel="stylesheet" href="<?php echo appUrl('assets/css/modern-theme.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="split-container">
        <!-- Left Side - Brand Hero -->
        <div class="split-left">
            <div class="hero-content">
                <div>
                    <div class="logo-icon">
                        <i class="fas fa-bug"></i>
                    </div>
                    <h1>Welcome to <span class="highlight">Pestify</span></h1>
                    <p>Connect with certified pest control professionals and solve your pest problems efficiently.</p>
                </div>
                <div class="hero-tagline">
                    ? 500+ Providers • 24/7 Support • Trusted Services
                </div>
            </div>
            <div class="footer-text">
                © 2026 Pestify. All rights reserved.
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="split-right">
            <div class="form-container">
                <div class="form-header">
                    <h2>Welcome Back</h2>
                    <p>Sign in to your account</p>
                </div>

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
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="name@company.com"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               autocomplete="email" required>
                    </div>

                    <div class="form-group password-field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" 
                               placeholder="••••••••"
                               autocomplete="current-password" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                    <div class="form-footer">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" value="1">
                            <span>Remember me</span>
                        </label>
                            <a href="<?php echo appUrl('forgot-password.php'); ?>" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary" id="loginBtn">
                        Login Now
                    </button>
                </form>

                <button type="button" class="btn btn-secondary">
                    <i class="fab fa-google"></i> Login with Google
                </button>

                <div class="divider">or</div>

                <div class="signup-link">
                    Don't have an account? <a href="<?php echo appUrl('register.php'); ?>">Create one now</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleBtn = document.querySelector('.toggle-password');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                passwordField.type = 'password';
                toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
            }
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnContent = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner" style="animation: spin 1s linear infinite;"></i> Logging in...';
            btn.disabled = true;
            
            setTimeout(function() {
                if (btn.disabled) {
                    btn.innerHTML = btnContent;
                    btn.disabled = false;
                }
            }, 5000);
        });

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
        
        .login-wrapper {
            display: flex;
            gap: 60px;
            align-items: center;
            min-height: calc(100vh - 200px);
        }
        
        .login-left {
            flex: 1;
            padding: 40px 0;
        }
        
        .login-right {
            flex: 1;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            padding: 40px;
        }
        
        .login-hero h1 {
            font-size: 42px;
            color: #333;
            margin-bottom: 20px;
            line-height: 1.2;
        }
        
        .login-hero p {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .features-list {
            list-style: none;
            padding: 0;
            margin-top: 40px;
        }
        
        .features-list li {
            padding: 15px 0;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 16px;
            color: #555;
        }
        
        .features-list i {
            color: #2c5aa0;
            font-size: 20px;
            width: 30px;
        }
        
        .login-form h2 {
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #2c5aa0;
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
        }
        
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #666;
        }
        
        .remember-me input[type="checkbox"] {
            cursor: pointer;
        }
        
        .forgot-password {
            color: #2c5aa0;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        .forgot-password:hover {
            text-decoration: underline;
        }
        
        .btn-login {
            background: #2c5aa0;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s;
        }
        
        .btn-login:hover {
            background: #1e4070;
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .divider {
            text-align: center;
            margin: 25px 0;
            color: #999;
            font-size: 14px;
            position: relative;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: #ddd;
        }
        
        .divider::before {
            left: 0;
        }
        
        .divider::after {
            right: 0;
        }
        
        .register-link {
            text-align: center;
            color: #666;
            margin-top: 25px;
        }
        
        .register-link a {
            color: #2c5aa0;
            text-decoration: none;
            font-weight: 500;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
        }
        
        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c00;
        }
        
        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #090;
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 992px) {
            .login-wrapper {
                flex-direction: column;
                gap: 40px;
            }
            
            .login-left {
                text-align: center;
            }
            
            .features-list li {
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .login-container {
                padding: 20px 15px;
            }
            
            .login-right {
                padding: 30px 20px;
            }
            
            .login-hero h1 {
                font-size: 32px;
            }
            
            .login-hero p {
                font-size: 16px;
            }
        }
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
                    
                    <ul class="features-list">
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>500+ Trusted Providers</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>24/7 Emergency Service</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Certified Professionals</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Affordable Solutions</span>
                        </li>
                    </ul>
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
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="email" required class="form-control" 
                                   placeholder="Enter your email"
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                   autocomplete="email">
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
                        <p style="margin-top: 10px;"><a href="<?php echo appUrl('index.php'); ?>"><i class="fas fa-home"></i> Home</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnContent = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner spinner"></i> Logging in...';
            btn.disabled = true;
            
            // Re-enable button after 5 seconds as fallback
            setTimeout(function() {
                if (btn.disabled) {
                    btn.innerHTML = btnContent;
                    btn.disabled = false;
                }
            }, 5000);
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
