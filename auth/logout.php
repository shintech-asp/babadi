<?php
chdir(dirname(__DIR__));
// logout.php
session_start();
require_once 'config/config.php';

// If confirmed, destroy session and redirect
if (isset($_POST['confirm_logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . appUrl('login.php?logout=1'));
    exit();
}

// Get user info before showing confirmation
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'there';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - Pestify</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Blurred background overlay feel */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background: linear-gradient(135deg, #1e2d40 0%, #243348 50%, #1a2535 100%);
            opacity: 0.96;
            z-index: 0;
        }

        .logout-card {
            position: relative; z-index: 1;
            background: white;
            border-radius: 24px;
            padding: 48px 40px 40px;
            max-width: 420px; width: 100%;
            text-align: center;
            box-shadow: 0 24px 80px rgba(0,0,0,0.3);
            animation: cardIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes cardIn {
            from { transform: translateY(30px) scale(0.94); opacity: 0; }
            to   { transform: translateY(0) scale(1); opacity: 1; }
        }

        /* Icon */
        .logout-icon-wrap {
            width: 80px; height: 80px; border-radius: 50%;
            background: #fef2f2;
            border: 3px solid #fecaca;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px;
            animation: iconPulse 2s ease-in-out infinite;
        }
        @keyframes iconPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.2); }
            50%       { box-shadow: 0 0 0 10px rgba(239,68,68,0); }
        }
        .logout-icon-wrap i {
            font-size: 32px; color: #ef4444;
        }

        /* Text */
        .logout-title {
            font-size: 22px; font-weight: 800; color: #1e2d40;
            margin-bottom: 10px;
        }
        .logout-subtitle {
            font-size: 14px; color: #64748b; line-height: 1.7;
            margin-bottom: 32px;
        }
        .logout-subtitle strong { color: #1e2d40; }

        /* Divider */
        .logout-divider {
            border: none; border-top: 1px solid #f1f5f9;
            margin-bottom: 28px;
        }

        /* Buttons */
        .logout-actions {
            display: flex; gap: 12px;
        }
        .btn-cancel {
            flex: 1; padding: 14px 20px;
            background: #f1f5f9; color: #475569;
            border: 2px solid #e2e8f0; border-radius: 12px;
            font-size: 15px; font-weight: 700; font-family: inherit;
            cursor: pointer; text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s;
        }
        .btn-cancel:hover {
            background: #e2e8f0; color: #1e293b;
            border-color: #cbd5e1; transform: translateY(-1px);
        }
        .btn-confirm {
            flex: 1; padding: 14px 20px;
            background: #ef4444; color: white;
            border: 2px solid #ef4444; border-radius: 12px;
            font-size: 15px; font-weight: 700; font-family: inherit;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s;
        }
        .btn-confirm:hover {
            background: #dc2626; border-color: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(239,68,68,0.35);
        }
        .btn-confirm:active { transform: translateY(0); }

        /* Branding */
        .logout-brand {
            margin-top: 28px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            color: #94a3b8; font-size: 13px;
        }
        .logout-brand i { color: #1e2d40; font-size: 16px; }
        .logout-brand span { font-weight: 700; color: #1e2d40; }
    </style>
</head>
<body>

    <div class="logout-card">

        <div class="logout-icon-wrap">
            <i class="fas fa-sign-out-alt"></i>
        </div>

        <div class="logout-title">Logging Out?</div>
        <div class="logout-subtitle">
            Hey <strong><?php echo htmlspecialchars($user_name); ?></strong>, are you sure you want to sign out of your Pestify account?
        </div>

        <hr class="logout-divider">

        <div class="logout-actions">
            <a href="javascript:history.back()" class="btn-cancel">
                <i class="fas fa-arrow-left"></i> Cancel
            </a>
            <form method="POST" action="<?php echo appUrl('logout.php'); ?>" style="flex:1; display:flex;">
                <button type="submit" name="confirm_logout" class="btn-confirm" style="flex:1;">
                    <i class="fas fa-sign-out-alt"></i> Yes, Logout
                </button>
            </form>
        </div>

        <div class="logout-brand">
            <i class="fas fa-bug"></i>
            <span>Pestify</span> &nbsp;·&nbsp; Pest Control Experts
        </div>

    </div>

</body>
</html>
