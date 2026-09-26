<?php
// provider-portal/change-password.php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
$database = new Database(); $db = $database->getConnection();

// portal-auth.php sets $portal_provider_id — needed by portal-tier.php below so
// the sidebar (which defaults to the free tier when $tier_is_paid is unset)
// renders this Pro-eligible provider's real tier instead of always falling
// back to "Free"/locked, which is what happened when this page did its own
// bare $_SESSION['portal_staff_id'] check and never loaded either include.
require_once 'includes/portal-tier.php';

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
:root{
    --primary:#2E8B57;--primary-dim:rgba(46,139,87,.12);
    --dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096;
    --green:#16a34a;--green-dim:rgba(22,163,74,.1);
    --red:#dc2626;--red-dim:rgba(220,38,38,.1);
    --amber:#d97706;--amber-dim:rgba(217,119,6,.1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px;display:flex;justify-content:center}
.cp-wrap{width:100%;max-width:920px}

.page-header{margin-bottom:24px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}

.alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:13.5px;display:flex;align-items:center;gap:9px;animation:slideIn .25s ease}
@keyframes slideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.alert-success{background:var(--green-dim);border:1px solid rgba(22,163,74,.22);color:#14532d}
.alert-error  {background:var(--red-dim);  border:1px solid rgba(220,38,38,.22);  color:#7f1d1d}
.alert-warning{background:var(--amber-dim);border:1px solid rgba(217,119,6,.22); color:#78350f}
.alert a{color:inherit;font-weight:700;text-decoration:underline;margin-left:auto;white-space:nowrap}

.cp-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;align-items:start}
@media(max-width:760px){.cp-grid{grid-template-columns:1fr}}

.settings-card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,.06);overflow:hidden}
.settings-card-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.settings-card-header .header-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;color:#fff;flex-shrink:0;background:linear-gradient(135deg,#2E8B57,#27ae60)}
.settings-card-header h2{font-size:15px;font-weight:700;color:var(--dark)}
.settings-card-header p{font-size:12px;color:var(--muted);margin-top:2px}
.settings-card-body{padding:24px}

.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.form-label{font-size:12px;font-weight:700;color:#2d3748;text-transform:uppercase;letter-spacing:.4px}
.pw-input-wrap{position:relative}
.form-control{width:100%;padding:11px 42px 11px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit;color:#2d3748;background:#fafcfb;transition:border .18s,box-shadow .18s}
.form-control:focus{outline:none;border-color:var(--primary);background:#fff;box-shadow:0 0 0 3px var(--primary-dim)}
.pw-toggle{position:absolute;right:1px;top:1px;bottom:1px;width:40px;display:flex;align-items:center;justify-content:center;background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px}
.pw-toggle:hover{color:var(--dark)}

.pw-strength{margin-top:8px}
.pw-strength-bars{display:flex;gap:4px}
.pw-strength-bars span{flex:1;height:4px;border-radius:999px;background:var(--border);transition:background .2s}
.pw-strength-label{font-size:11px;color:var(--muted);margin-top:5px;font-weight:600}
.pw-match-hint{font-size:11px;margin-top:5px;font-weight:600;display:none;align-items:center;gap:5px}
.pw-match-hint.show{display:flex}
.pw-match-hint.ok{color:var(--green)}
.pw-match-hint.bad{color:var(--red)}

.btn-save{width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 24px;background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .2s;margin-top:6px}
.btn-save:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(46,139,87,.3)}
.btn-save:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
.skip-link{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;font-size:13px;color:var(--muted);text-decoration:none}
.skip-link:hover{color:var(--primary)}

.tips-card ul{list-style:none;display:flex;flex-direction:column;gap:14px}
.tips-card li{display:flex;gap:10px;font-size:12.5px;color:#475569;line-height:1.5}
.tips-card li i{color:var(--primary);font-size:13px;margin-top:2px;flex-shrink:0}
.tips-card li b{color:var(--dark)}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">
<div class="cp-wrap">

    <div class="page-header">
        <h1><i class="fas fa-shield-halved"></i> Change Password</h1>
        <p><?= $forced ? 'You must set a new password before continuing.' : 'Update the password used to sign in to the provider portal.' ?></p>
    </div>

    <?php if ($forced && !$success): ?>
    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> This is your first login. Please set a new password to continue.</div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?> <a href="dashboard.php">Go to Dashboard →</a></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
    <?php endif; ?>

    <div class="cp-grid">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="header-icon"><i class="fas fa-key"></i></div>
                <div><h2>Update Password</h2><p>Choose a new password only you know.</p></div>
            </div>
            <div class="settings-card-body">
                <form method="POST" id="cpForm">
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <div class="pw-input-wrap">
                            <input type="password" name="current_password" id="curPw" class="form-control" required>
                            <button type="button" class="pw-toggle" data-target="curPw"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <div class="pw-input-wrap">
                            <input type="password" name="new_password" id="newPw" class="form-control" required minlength="8" placeholder="Minimum 8 characters">
                            <button type="button" class="pw-toggle" data-target="newPw"><i class="fas fa-eye"></i></button>
                        </div>
                        <div class="pw-strength">
                            <div class="pw-strength-bars"><span></span><span></span><span></span><span></span></div>
                            <div class="pw-strength-label" id="pwStrengthLabel">Enter a new password</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <div class="pw-input-wrap">
                            <input type="password" name="confirm_password" id="confirmPw" class="form-control" required>
                            <button type="button" class="pw-toggle" data-target="confirmPw"><i class="fas fa-eye"></i></button>
                        </div>
                        <div class="pw-match-hint" id="pwMatchHint"><i class="fas fa-circle-check"></i><span></span></div>
                    </div>
                    <button type="submit" class="btn-save" id="cpSubmit"><i class="fas fa-save"></i> Save New Password</button>
                </form>
                <?php if (!$forced): ?>
                <a href="dashboard.php" class="skip-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="settings-card tips-card">
            <div class="settings-card-header">
                <div class="header-icon" style="background:linear-gradient(135deg,#6366f1,#4f46e5)"><i class="fas fa-lightbulb"></i></div>
                <div><h2>Keep Your Account Safe</h2><p>A few quick tips</p></div>
            </div>
            <div class="settings-card-body">
                <ul>
                    <li><i class="fas fa-circle-check"></i><span>Use <b>8 or more characters</b>, mixing letters, numbers, and symbols.</span></li>
                    <li><i class="fas fa-circle-check"></i><span>Avoid reusing a password from another site or your previous portal password.</span></li>
                    <li><i class="fas fa-circle-check"></i><span>Never share your password, even with coworkers or support staff.</span></li>
                    <li><i class="fas fa-circle-check"></i><span>Change it right away if you suspect anyone else may know it.</span></li>
                </ul>
            </div>
        </div>
    </div>

</div>
</div></div></div>
<script>
(function(){
    document.querySelectorAll('.pw-toggle').forEach(function(btn){
        btn.addEventListener('click', function(){
            var input = document.getElementById(btn.dataset.target);
            var icon  = btn.querySelector('i');
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            icon.classList.toggle('fa-eye', showing);
            icon.classList.toggle('fa-eye-slash', !showing);
        });
    });

    var newPw   = document.getElementById('newPw');
    var confirm = document.getElementById('confirmPw');
    var bars    = document.querySelectorAll('.pw-strength-bars span');
    var label   = document.getElementById('pwStrengthLabel');
    var hint    = document.getElementById('pwMatchHint');
    var hintIcon = hint.querySelector('i');
    var hintText = hint.querySelector('span');

    function scorePassword(pw) {
        if (!pw) return 0;
        var score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/[0-9]/.test(pw) && /[^A-Za-z0-9]/.test(pw)) score++;
        return Math.min(score, 4);
    }

    function updateStrength() {
        var score = scorePassword(newPw.value);
        var colors = ['#dc2626', '#d97706', '#eab308', '#16a34a'];
        var labels = ['Enter a new password', 'Weak', 'Fair', 'Good', 'Strong'];
        bars.forEach(function(bar, i){
            bar.style.background = (i < score) ? colors[Math.max(score - 1, 0)] : '#e2e8f0';
        });
        label.textContent = newPw.value ? labels[score] : labels[0];
        label.style.color = newPw.value ? (colors[Math.max(score - 1, 0)]) : '#718096';
    }

    function updateMatch() {
        if (!confirm.value) { hint.classList.remove('show'); return; }
        hint.classList.add('show');
        var matches = confirm.value === newPw.value;
        hint.classList.toggle('ok', matches);
        hint.classList.toggle('bad', !matches);
        hintIcon.className = matches ? 'fas fa-circle-check' : 'fas fa-circle-xmark';
        hintText.textContent = matches ? 'Passwords match' : 'Passwords do not match';
    }

    newPw.addEventListener('input', function(){ updateStrength(); updateMatch(); });
    confirm.addEventListener('input', updateMatch);
})();
</script>
</body></html>