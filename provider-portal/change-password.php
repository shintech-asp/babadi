<?php
// provider-portal/change-password.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['portal_staff_id'])) { header('Location: login.php'); exit(); }

require_once '../config/config.php';
require_once '../config/database.php';
$database = new Database(); $db = $database->getConnection();

$success = $error = '';
$forced  = (bool)($_SESSION['portal_must_change'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        $error = 'All fields are required.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $account_type = $_SESSION['portal_account_type'] ?? 'staff';

        if ($account_type === 'employee') {
            $emp_id = (int)$_SESSION['portal_employee_id'];
            $stmt = $db->prepare("SELECT password_hash FROM employees WHERE id=:id");
            $stmt->execute([':id' => $emp_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current, $row['password_hash'])) {
                $error = 'Current password is incorrect.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $db->prepare("UPDATE employees SET password_hash=:h, must_change_pwd=0, temp_password=NULL WHERE id=:id")
                   ->execute([':h' => $hash, ':id' => $emp_id]);
                $_SESSION['portal_must_change'] = 0;
                $success = 'Password changed successfully!';
            }
        } else {
            $stmt = $db->prepare("SELECT password_hash FROM provider_staff WHERE id=:id");
            $stmt->execute([':id' => $_SESSION['portal_staff_id']]);
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current, $row['password_hash'])) {
                $error = 'Current password is incorrect.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $db->prepare("UPDATE provider_staff SET password_hash=:h,must_change_password=0,temp_password=NULL WHERE id=:id")
                   ->execute([':h'=>$hash,':id'=>$_SESSION['portal_staff_id']]);
                $_SESSION['portal_must_change'] = 0;
                $success = 'Password changed successfully!';
            }
        }
    }
}

$active_menu = 'password';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Change Password - Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}.main-content{flex:1;padding:30px;display:flex;align-items:flex-start;justify-content:center}
.change-box{background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.1);border:1px solid var(--border);width:100%;max-width:440px;overflow:hidden;margin-top:40px}
.change-top{background:linear-gradient(135deg,#2E8B57,#27ae60);padding:24px;text-align:center;color:#fff}
.change-top i{font-size:36px;margin-bottom:10px;display:block}
.change-top h2{font-size:20px;font-weight:700}
.change-top p{font-size:13px;opacity:.85;margin-top:4px}
.change-body{padding:28px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#c6f6d5;color:#276749;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.alert-warning{background:#fefcbf;color:#744210;border-left:4px solid #f59e0b}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:700;color:#2d3748;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.form-control{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit;transition:border .2s}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,139,87,.1)}
.btn{width:100%;padding:12px;background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px}
.btn:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(39,174,96,.3)}
.skip-link{display:block;text-align:center;margin-top:16px;font-size:13px;color:var(--muted);text-decoration:none}
.skip-link:hover{color:var(--primary)}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">
<div class="change-box">
    <div class="change-top">
        <i class="fas fa-key"></i>
        <h2>Change Password</h2>
        <p><?= $forced ? 'You must change your password before continuing.' : 'Update your account password.' ?></p>
    </div>
    <div class="change-body">
        <?php if ($forced && !$success): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> This is your first login. Please set a new password.</div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?> <a href="dashboard.php" style="color:var(--primary);font-weight:700;margin-left:4px">Go to Dashboard →</a></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
            <div class="form-group"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required minlength="8" placeholder="Minimum 8 characters"></div>
            <div class="form-group"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
            <button type="submit" class="btn"><i class="fas fa-save"></i> Save New Password</button>
        </form>
        <?php if (!$forced): ?>
        <a href="dashboard.php" class="skip-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <?php endif; ?>
    </div>
</div>
</div></div></div>
</body></html>