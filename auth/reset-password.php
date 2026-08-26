<?php
chdir(dirname(__DIR__));
// reset-password.php - Reset Password with Token
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$user_id = null;
$user_email = '';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . appUrl('index.php'));
    exit();
}

// Validate token
if (empty($token)) {
    $error = "Invalid or missing reset token.";
} else {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if token exists and is not expired
    $query = "SELECT id, email FROM users WHERE reset_token = :token AND reset_token_expires > NOW()";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_id = $user['id'];
        $user_email = $user['email'];
        
        // Handle password reset form submission
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_submit'])) {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate password
            if (empty($password) || empty($confirm_password)) {
                $error = "Both password fields are required.";
            } elseif ($password !== $confirm_password) {
                $error = "Passwords do not match.";
            } elseif (strlen($password) < 8) {
                $error = "Password must be at least 8 characters long.";
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $error = "Password must contain at least one uppercase letter.";
            } elseif (!preg_match('/[0-9]/', $password)) {
                $error = "Password must contain at least one number.";
            } elseif (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
                $error = "Password must contain at least one special character.";
            } else {
                // Hash and update password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                try {
                    $updateQuery = "UPDATE users SET password = :password, reset_token = NULL, reset_token_expires = NULL WHERE id = :id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':password', $hashed_password);
                    $updateStmt->bindParam(':id', $user_id);
                    
                    if ($updateStmt->execute()) {
                        $success = "Password has been reset successfully. You can now login with your new password.";
                    } else {
                        $error = "Failed to reset password. Please try again.";
                    }
                } catch (PDOException $e) {
                    error_log("Password reset error: " . $e->getMessage());
                    $error = "Database error. Please try again later.";
                }
            }
        }
    } else {
        $error = "This reset link is invalid or has expired. Please request a new one.";
    }
}

// Function to validate password strength
function validatePassword($password) {
    if (strlen($password) < 8) {
        return "At least 8 characters";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return "At least one uppercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        return "At least one number";
    }
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        return "At least one special character";
    }
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f7fa; }
        .reset-container {
            max-width: 500px;
            margin: 60px auto;
            padding: 0 20px;
        }
        
        .reset-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            padding: 40px;
        }
        
        .reset-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .reset-header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .reset-header p {
            color: #666;
            font-size: 16px;
        }
        
        .icon-circle {
            width: 80px;
            height: 80px;
            background: #e8f4f8;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: #2c5aa0;
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
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            width: 100%;
            justify-content: center;
            transition: background 0.3s;
        }
        
        .btn-primary {
            background: #2c5aa0;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1e4070;
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: #ecf0f1;
            color: #2c3e50;
            margin-top: 12px;
        }
        
        .btn-secondary:hover {
            background: #bdc3c7;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
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
        
        .alert i {
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .password-validation {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            border-left: 4px solid #28a745;
        }
        
        .validation-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .validation-list li {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }
        
        .validation-icon {
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 10px;
            flex-shrink: 0;
        }
        
        .validation-valid {
            background: #28a745;
            color: white;
        }
        
        .validation-invalid {
            background: #dc3545;
            color: white;
        }
        
        .validation-pending {
            background: #6c757d;
            color: white;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: #2c5aa0;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php include appPath('includes/header.php'); ?>
    
    <div class="reset-container">
        <div class="reset-card">
            <div class="reset-header">
                <div class="icon-circle">
                    <i class="fas fa-key"></i>
                </div>
                <h1>Create New Password</h1>
                <p>Enter a strong password to secure your account</p>
            </div>
            
            <?php if (!empty($error) && !$success): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
                
                <?php if (strpos($error, 'invalid') !== false || strpos($error, 'expired') !== false): ?>
                    <div class="back-link">
                    <a href="<?php echo appUrl('forgot-password.php'); ?>"><i class="fas fa-redo"></i> Request New Reset Link</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success; ?></span>
                </div>
                
                <div class="back-link" style="margin-top: 30px;">
                    <a href="<?php echo appUrl('login.php'); ?>" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Go to Login</a>
                </div>
            <?php elseif ($user_id): ?>
                <!-- Reset Form -->
                <form method="POST" action="" id="resetForm">
                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="password" id="password" required class="form-control" 
                               placeholder="Enter a strong password">
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" id="confirm_password" required class="form-control" 
                               placeholder="Re-enter your password">
                    </div>
                    
                    <!-- Password Requirements -->
                    <div class="password-validation">
                        <small style="font-weight: 600; color: #333;">Password Requirements:</small>
                        <ul class="validation-list">
                            <li>
                                <span class="validation-icon validation-pending" id="req-length">?</span>
                                <span>At least 8 characters</span>
                            </li>
                            <li>
                                <span class="validation-icon validation-pending" id="req-uppercase">?</span>
                                <span>At least one uppercase letter</span>
                            </li>
                            <li>
                                <span class="validation-icon validation-pending" id="req-number">?</span>
                                <span>At least one number</span>
                            </li>
                            <li>
                                <span class="validation-icon validation-pending" id="req-special">?</span>
                                <span>At least one special character</span>
                            </li>
                        </ul>
                    </div>
                    
                    <button type="submit" name="reset_submit" class="btn btn-primary" style="margin-top: 20px;" id="resetBtn">
                        <i class="fas fa-lock"></i> Reset Password
                    </button>
                </form>
                
                <div class="back-link">
                <a href="<?php echo appUrl('login.php'); ?>"><i class="fas fa-arrow-left"></i> Back to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
    
    <?php if ($user_id): ?>
    <script>
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const resetBtn = document.getElementById('resetBtn');
        
        function validatePasswordStrength() {
            const password = passwordInput.value;
            const requirements = {
                length: { met: password.length >= 8, element: document.getElementById('req-length') },
                uppercase: { met: /[A-Z]/.test(password), element: document.getElementById('req-uppercase') },
                number: { met: /[0-9]/.test(password), element: document.getElementById('req-number') },
                special: { met: /[!@#$%^&*()\-_=+{};:,<.>]/.test(password), element: document.getElementById('req-special') }
            };
            
            let allValid = true;
            for (let key in requirements) {
                const req = requirements[key];
                const icon = req.element;
                
                if (password.length === 0) {
                    icon.className = 'validation-icon validation-pending';
                    icon.textContent = '?';
                    allValid = false;
                } else if (req.met) {
                    icon.className = 'validation-icon validation-valid';
                    icon.textContent = '?';
                } else {
                    icon.className = 'validation-icon validation-invalid';
                    icon.textContent = '?';
                    allValid = false;
                }
            }
            
            resetBtn.disabled = !allValid || confirmPasswordInput.value !== password || password.length === 0;
        }
        
        function validateConfirmPassword() {
            validatePasswordStrength();
        }
        
        if (passwordInput) passwordInput.addEventListener('input', validatePasswordStrength);
        if (confirmPasswordInput) confirmPasswordInput.addEventListener('input', validateConfirmPassword);
    </script>
    <?php endif; ?>
</body>
</html>
