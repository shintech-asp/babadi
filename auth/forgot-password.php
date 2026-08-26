<?php
chdir(dirname(__DIR__));
// forgot-password.php - Forgot Password with OTP
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$error = '';
$success = '';
$step = 1; // Step 1: Enter email, Step 2: Verify OTP, Step 3: Reset password

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . appUrl('index.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    if (isset($_POST['step1_submit'])) {
        // Step 1: User enters email
        $email = sanitize($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = "Email address is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Check if user exists
            $query = "SELECT id, first_name, last_name FROM users WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Generate 6-digit OTP
                $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                
                // Save OTP to database
                $updateQuery = "UPDATE users SET password_reset_otp = :otp, password_reset_otp_expires = :expires WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':otp', $otp);
                $updateStmt->bindParam(':expires', $otp_expires);
                $updateStmt->bindParam(':id', $user['id']);
                $updateStmt->execute();
                
                // Send OTP email
                require_once 'config/send_email.php';
                $emailSender = new EmailSender();
                $emailSent = $emailSender->sendOTPEmail($email, $user['first_name'], $otp);
                
                if ($emailSent) {
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_user_id'] = $user['id'];
                    $step = 2;
                    $success = "OTP has been sent to your email. Please enter it below to proceed.";
                } else {
                    $error = "Failed to send OTP. Please try again later.";
                }
            } else {
                // For security, don't reveal whether email exists
                $step = 2;
                $_SESSION['reset_email'] = $email;
                $success = "If an account exists with this email, you will receive an OTP. Please check your inbox.";
            }
        }
    } elseif (isset($_POST['step2_submit'])) {
        // Step 2: User enters OTP
        $otp_input = sanitize($_POST['otp'] ?? '');
        $reset_email = $_SESSION['reset_email'] ?? '';
        $reset_user_id = $_SESSION['reset_user_id'] ?? null;
        
        if (empty($otp_input)) {
            $error = "OTP is required.";
            $step = 2;
        } elseif (empty($reset_email) || !$reset_user_id) {
            $error = "Session expired. Please start over.";
            $step = 1;
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_user_id']);
        } else {
            // Verify OTP
            $query = "SELECT password_reset_otp, password_reset_otp_expires FROM users WHERE id = :id AND email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $reset_user_id);
            $stmt->bindParam(':email', $reset_email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $stored_otp = $user['password_reset_otp'];
                $otp_expires = strtotime($user['password_reset_otp_expires']);
                
                if ($otp_input === $stored_otp && time() < $otp_expires) {
                    // OTP is valid
                    $_SESSION['otp_verified'] = true;
                    $step = 3;
                    $success = "OTP verified successfully. You can now reset your password.";
                } else {
                    $error = "Invalid or expired OTP. Please try again.";
                    $step = 2;
                }
            } else {
                $error = "User not found. Please try again.";
                $step = 1;
            }
        }
    } elseif (isset($_POST['step3_submit'])) {
        // Step 3: Reset password
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $reset_email = $_SESSION['reset_email'] ?? '';
        $reset_user_id = $_SESSION['reset_user_id'] ?? null;
        
        if (!isset($_SESSION['otp_verified']) || !$_SESSION['otp_verified']) {
            $error = "Please verify OTP first.";
            $step = 2;
        } elseif (empty($new_password)) {
            $error = "New password is required.";
            $step = 3;
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters long.";
            $step = 3;
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
            $step = 3;
        } else {
            // Hash password and update
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            
            $updateQuery = "UPDATE users SET password = :password, password_reset_otp = NULL, password_reset_otp_expires = NULL WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':password', $hashed_password);
            $updateStmt->bindParam(':id', $reset_user_id);
            
            if ($updateStmt->execute()) {
                // Clear session
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['otp_verified']);
                
                $step = 4; // Success page
                $success = "Password reset successfully! You can now login with your new password.";
            } else {
                $error = "Failed to reset password. Please try again.";
                $step = 3;
            }
        }
    }
}

$display_email = $_SESSION['reset_email'] ?? '';
$otp_verified = $_SESSION['otp_verified'] ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f7fa; }
        .forgot-container {
            max-width: 500px;
            margin: 60px auto;
            padding: 0 20px;
        }
        
        .forgot-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            padding: 40px;
        }
        
        .forgot-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .forgot-header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .forgot-header p {
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
        }
        
        .btn-primary {
            background: #2c5aa0;
            color: white;
            transition: background 0.3s;
        }
        
        .btn-primary:hover {
            background: #1e4070;
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
        
        .step-indicator {
            text-align: center;
            margin-bottom: 30px;
            color: #7f8c8d;
            font-size: 14px;
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
        
        .info-box {
            background: #f0f8ff;
            border-left: 4px solid #2c5aa0;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
            color: #2c5aa0;
            font-size: 14px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <?php include appPath('includes/header.php'); ?>
    
    <div class="forgot-container">
        <div class="forgot-card">
            <div class="forgot-header">
                <div class="icon-circle">
                    <i class="fas fa-lock"></i>
                </div>
                <h1>Reset Password</h1>
                <p>
                    <?php 
                        if ($step == 1) echo 'Enter your email to receive an OTP';
                        elseif ($step == 2) echo 'Verify the OTP sent to your email';
                        elseif ($step == 3) echo 'Create your new password';
                        else echo 'Password reset successful';
                    ?>
                </p>
            </div>
            
            <?php if ($step == 1): ?>
                <!-- Step 1: Enter Email -->
                <div class="step-indicator">Step 1 of 3</div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" required class="form-control" 
                               placeholder="Enter your registered email address"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               autocomplete="email">
                    </div>
                    
                    <button type="submit" name="step1_submit" class="btn btn-primary">
                        <i class="fas fa-envelope"></i> Send OTP
                    </button>
                </form>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    A 6-digit OTP will be sent to your email. You'll have 15 minutes to use it.
                </div>
            
            <?php elseif ($step == 2): ?>
                <!-- Step 2: Verify OTP -->
                <div class="step-indicator">Step 2 of 3</div>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success; ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Enter OTP *</label>
                        <input type="text" name="otp" required class="form-control" 
                               placeholder="Enter 6-digit OTP"
                               maxlength="6"
                               inputmode="numeric"
                               pattern="[0-9]{6}">
                        <small style="color: #666; margin-top: 5px; display: block;">
                            Check your email at <strong><?php echo htmlspecialchars($display_email); ?></strong>
                        </small>
                    </div>
                    
                    <button type="submit" name="step2_submit" class="btn btn-primary">
                        <i class="fas fa-check"></i> Verify OTP
                    </button>
                </form>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    OTP expires in 15 minutes. If you don't see it, check your spam folder.
                </div>
            
            <?php elseif ($step == 3): ?>
                <!-- Step 3: Reset Password -->
                <div class="step-indicator">Step 3 of 3</div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="new_password" required class="form-control" 
                               placeholder="Enter new password (min. 8 characters)">
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" required class="form-control" 
                               placeholder="Confirm your password">
                    </div>
                    
                    <button type="submit" name="step3_submit" class="btn btn-primary">
                        <i class="fas fa-lock"></i> Reset Password
                    </button>
                </form>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    Password must be at least 8 characters long.
                </div>
            
            <?php else: ?>
                <!-- Step 4: Success -->
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success; ?></span>
                </div>
                
                <p style="text-align: center; margin-top: 30px; color: #666;">
                    Redirecting to login page in 3 seconds...
                </p>
                
                <script>
                    setTimeout(function() {
                        window.location.href = '<?php echo appUrl('login.php'); ?>';
                    }, 3000);
                </script>
            
            <?php endif; ?>
            
            <div class="back-link">
                            <a href="<?php echo appUrl('login.php'); ?>"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
</body>
</html>
