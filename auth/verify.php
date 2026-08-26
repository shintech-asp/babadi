<?php
chdir(dirname(__DIR__));
// verify.php - Email verification with OTP
session_start();

require_once 'config/config.php';
require_once 'config/database.php';

// Check if user has started registration
if (!isset($_SESSION['verification_email'])) {
    header("Location: " . appUrl('register.php'));
    exit();
}

$error = '';
$success = '';
$verification_email = $_SESSION['verification_email'];

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action == 'verify_otp') {
            $otp_input = trim($_POST['otp']);
            $stored_otp = $_SESSION['verification_otp'] ?? '';
            
            // Verify OTP
            if (empty($otp_input)) {
                $error = "Please enter the OTP code.";
            } elseif ($otp_input != $stored_otp) {
                $error = "Invalid OTP code. Please try again.";
            } else {
                // OTP is correct - mark email as verified
                $query = "UPDATE users SET email_verified = 1, email_verified_at = NOW() WHERE email = :email";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $verification_email);
                
                if ($stmt->execute()) {
                    $success = "Email verified successfully! Redirecting to login...";
                    
                    // Clear verification session data
                    unset($_SESSION['verification_email']);
                    unset($_SESSION['verification_otp']);
                    unset($_SESSION['verification_token']);
                    
                    // Redirect to login after 2 seconds
                    header("refresh:2;url=" . appUrl('login.php'));
                } else {
                    $error = "Verification failed. Please try again.";
                }
            }
        } elseif ($action == 'resend_otp') {
            // Generate new OTP
            $new_otp = rand(100000, 999999);
            $token_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Update database with new OTP
            $query = "UPDATE users SET last_verification_sent = NOW() WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $verification_email);
            $stmt->execute();
            
            // Send new OTP email
            require_once 'config/send_email.php';
            $emailSender = new EmailSender();
            
            // Get user name
            $userQuery = "SELECT first_name, last_name FROM users WHERE email = :email";
            $userStmt = $db->prepare($userQuery);
            $userStmt->bindParam(':email', $verification_email);
            $userStmt->execute();
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            $full_name = $user['first_name'] . ' ' . $user['last_name'];
            
            if ($emailSender->sendOTPEmail($verification_email, $full_name, $new_otp)) {
                // Update session with new OTP
                $_SESSION['verification_otp'] = $new_otp;
                $success = "A new OTP has been sent to your email. Please check your inbox.";
            } else {
                $error = "Failed to send OTP. Please try again later.";
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
    <title>Verify Email - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/style.css'); ?>">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --primary-light: #C6F6D5;
            --primary-soft: #F0FFF4;
            --neutral-dark: #2D3748;
            --neutral-gray: #718096;
            --neutral-light: #E2E8F0;
            --neutral-soft: #F7FAFC;
            --success: #38A169;
            --danger: #E53E3E;
            --radius: 10px;
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
            background: linear-gradient(135deg, var(--primary-soft) 0%, #F7FAFC 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .verify-container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .verify-icon {
            width: 80px;
            height: 80px;
            background: var(--primary-soft);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            font-size: 2.5rem;
            color: var(--primary);
        }

        .verify-container h1 {
            font-size: 1.75rem;
            color: var(--neutral-dark);
            margin-bottom: 10px;
        }

        .verify-container p {
            color: var(--neutral-gray);
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .email-display {
            background: var(--neutral-soft);
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 30px;
            font-weight: 600;
            color: var(--primary);
            word-break: break-all;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--neutral-dark);
            font-size: 0.9375rem;
        }

        .otp-input-group {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 20px;
        }

        .otp-input {
            width: 50px;
            height: 50px;
            font-size: 1.5rem;
            text-align: center;
            border: 2px solid var(--neutral-light);
            border-radius: var(--radius);
            font-weight: bold;
            transition: all var(--transition);
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--neutral-light);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: all var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1);
        }

        .btn-verify {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            margin-bottom: 15px;
        }

        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 139, 87, 0.3);
        }

        .btn-resend {
            width: 100%;
            padding: 12px;
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }

        .btn-resend:hover {
            background: var(--primary-soft);
        }

        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #FEE2E2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
        }

        .alert-error i {
            color: #DC2626;
        }

        .alert-success {
            background: #D1FAE5;
            border: 1px solid #6EE7B7;
            color: #065F46;
        }

        .alert-success i {
            color: #10B981;
        }

        .timer {
            color: var(--neutral-gray);
            font-size: 0.9375rem;
            margin-top: 15px;
        }

        .timer.expired {
            color: var(--danger);
        }

        .resend-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .resend-link:hover {
            text-decoration: underline;
        }

        .back-link {
            margin-top: 20px;
            text-align: center;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 576px) {
            .verify-container {
                padding: 30px 20px;
            }

            .verify-container h1 {
                font-size: 1.5rem;
            }

            .otp-input {
                width: 45px;
                height: 45px;
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-icon">
            <i class="fas fa-envelope"></i>
        </div>

        <h1>Verify Your Email</h1>
        <p>We've sent a verification code to your email address. Please enter it below to complete your registration.</p>

        <div class="email-display">
            <?php echo htmlspecialchars($verification_email); ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success; ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="action" value="verify_otp">

            <div class="form-group">
                <label for="otp">Enter Verification Code:</label>
                <input type="text" id="otp" name="otp" class="form-control" 
                       placeholder="Enter 6-digit code" maxlength="6" 
                       pattern="[0-9]{6}" required autofocus>
            </div>

            <button type="submit" class="btn-verify">
                <i class="fas fa-check"></i> Verify Email
            </button>
        </form>

        <form method="POST" action="" style="margin-top: 10px;">
            <input type="hidden" name="action" value="resend_otp">
            <button type="submit" class="btn-resend">
                <i class="fas fa-redo"></i> Resend Code
            </button>
        </form>

        <div class="timer">
            <p><i class="fas fa-info-circle"></i> Code expires in 15 minutes</p>
        </div>

        <div class="back-link">
                    <p>Already verified? <a href="<?php echo appUrl('login.php'); ?>">Go to Login</a></p>
        </div>
    </div>
</body>
</html>
