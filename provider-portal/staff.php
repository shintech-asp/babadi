<?php
// provider-portal/staff.php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
$database = new Database(); $db = $database->getConnection();
$pid = $portal_provider_id;

// Only owner can access
if ($portal_role !== 'owner') {
    header('Location: dashboard.php?error=access_denied'); exit();
}

$success = $error = '';

// ── Add Staff ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $role      = $_POST['role']           ?? 'hr';
    $dept      = $_POST['department']     ?? 'hr';

    if (!$full_name || !$username || !$email) {
        $error = 'Full name, username and email are required.';
    } else {
        // Check duplicate
        $chk = $db->prepare("SELECT id FROM provider_staff WHERE username=:u OR email=:e");
        $chk->execute([':u'=>$username,':e'=>$email]);
        if ($chk->rowCount()) {
            $error = 'Username or email already exists.';
        } else {
            $temp_pass = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#'), 0, 10);
            $hash      = password_hash($temp_pass, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO provider_staff (provider_id,full_name,username,email,password_hash,temp_password,role,department,must_change_password) VALUES(:p,:n,:u,:e,:h,:tp,:r,:d,1)")
               ->execute([':p'=>$pid,':n'=>$full_name,':u'=>$username,':e'=>$email,':h'=>$hash,':tp'=>$temp_pass,':r'=>$role,':d'=>$dept]);

            // Auto-create a matching employee record (so staff appear in HR employee list)
            $nameParts = explode(' ', $full_name, 2);
            $emp_first = $nameParts[0];
            $emp_last  = $nameParts[1] ?? $nameParts[0];
            $emp_code  = 'EMP-' . strtoupper(substr($emp_last, 0, 3)) . '-' . rand(1000, 9999);
            $roleLabel = ['hr'=>'HR Staff','finance'=>'Finance Staff','crm'=>'CRM Staff','owner'=>'Owner'];
            $emp_pos   = $roleLabel[$role] ?? ucfirst($role) . ' Staff';
            $already   = $db->prepare("SELECT id FROM employees WHERE email=:e AND provider_id=:p");
            $already->execute([':e'=>$email,':p'=>$pid]);
            if (!$already->rowCount()) {
                try {
                    $db->prepare(
                        "INSERT INTO employees (provider_id,employee_id,first_name,last_name,email,position,department,basic_salary,salary,hire_date,employment_type,status,temp_password,password_hash,must_change_pwd,created_at)
                         VALUES (:pid,:code,:fn,:ln,:em,:pos,:dept,0,0,CURDATE(),'regular','active',:tmp,:hash,1,NOW())"
                    )->execute([':pid'=>$pid,':code'=>$emp_code,':fn'=>$emp_first,':ln'=>$emp_last,':em'=>$email,':pos'=>$emp_pos,':dept'=>$dept,':tmp'=>$temp_pass,':hash'=>$hash]);
                } catch (Exception $e) { /* non-fatal — staff created, employee auto-create skipped (e.g. dup email) */ }
            }

            // Send email
            $subject = "Your Portal Account - " . $portal_company;
            $body    = "Hi $full_name,\n\nYour portal account for {$portal_company} has been created.\n\nLogin URL: " . SITE_URL . "/provider-portal/login.php\nUsername: $username\nTemporary Password: $temp_pass\n\nPlease change your password on first login.\n\nRegards,\n{$portal_company}";
            @mail($email, $subject, $body, "From: " . NOREPLY_EMAIL);

            $success = "Staff <strong>$full_name</strong> added. Temp password: <code>$temp_pass</code> (emailed to $email)";
        }
    }
}

// ── Toggle status ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle') {
    $sid    = (int)$_POST['staff_id'];
    $status = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
    $db->prepare("UPDATE provider_staff SET status=:s WHERE id=:id AND provider_id=:p AND role!='owner'")
       ->execute([':s'=>$status,':id'=>$sid,':p'=>$pid]);
    $success = 'Status updated.';
}

// ── Delete ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    $sid = (int)$_POST['staff_id'];
    $db->prepare("DELETE FROM provider_staff WHERE id=:id AND provider_id=:p AND role!='owner'")
       ->execute([':id'=>$sid,':p'=>$pid]);
    $success = 'Staff removed.';
}

$staff_list = $db->prepare("SELECT * FROM provider_staff WHERE provider_id=:p ORDER BY role, created_at DESC");
$staff_list->execute([':p'=>$pid]);
$staff_list = $staff_list->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'staff';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff Management - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}.main-content{flex:1;padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}.btn-sm{padding:5px 11px;font-size:12px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#c6f6d5;color:#276749;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.info-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;font-size:13px;color:#1d4ed8;margin-bottom:20px}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:13px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-active{background:#c6f6d5;color:#276749}.b-inactive{background:#e2e8f0;color:#4a5568}
.role-owner{background:#feebc8;color:#c05621}.role-hr{background:#e9d8fd;color:#6b46c1}.role-finance{background:#c6f6d5;color:#276749}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:22px}.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
.form-group{margin-bottom:16px}.form-label{display:block;font-size:12px;font-weight:600;color:#2d3748;margin-bottom:5px}
.form-control{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,139,87,.1)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-user-shield"></i> Staff Management</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')"><i class="fas fa-plus"></i> Add Staff</button>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div><?php endif; ?>

<div class="info-box"><i class="fas fa-info-circle" style="margin-right:6px"></i> You can add <strong>HR</strong> and <strong>Finance</strong> staff for your company. HR staff can only access HR features; Finance staff can only access Finance features. Staff will receive login credentials via email.</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-users-cog"></i> Portal Staff — <?= htmlspecialchars($portal_company) ?></h2>
        <span style="font-size:12px;color:var(--muted)"><?= count($staff_list) ?> member(s)</span>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($staff_list as $s): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($s['full_name']) ?></td>
            <td><code style="font-size:11px;background:#f8fafc;padding:2px 7px;border-radius:5px"><?= htmlspecialchars($s['username']) ?></code></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($s['email']) ?></td>
            <td><span class="badge role-<?= $s['role'] ?>"><?= ucfirst($s['role']) ?></span></td>
            <td><span style="background:#f0f4ff;color:#3b5bdb;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700"><?= ucfirst($s['department']) ?></span></td>
            <td><span class="badge b-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= $s['last_login'] ? date('M d, Y H:i', strtotime($s['last_login'])) : 'Never' ?></td>
            <td>
                <?php if ($s['role'] !== 'owner'): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="current_status" value="<?= $s['status'] ?>">
                    <button class="btn btn-sm" style="background:#e2e8f0;color:#4a5568" type="submit"><?= $s['status']==='active'?'Deactivate':'Activate' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Remove this staff member?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm" style="background:#fed7d7;color:#c53030" type="submit"><i class="fas fa-trash"></i></button>
                </form>
                <?php else: ?>
                <span style="font-size:12px;color:var(--muted)">Owner</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></div></div>

<!-- Add Staff Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal-box">
    <div class="modal-head">
        <h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px"></i>Add Staff Member</h3>
        <button style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted)" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button>
    </div>
    <form method="POST"><input type="hidden" name="action" value="add">
    <div class="modal-body">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;font-size:12px;color:#276749;margin-bottom:16px">
            <i class="fas fa-key" style="margin-right:6px"></i>A temporary password will be auto-generated and emailed. Staff must change it on first login.
        </div>
        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
        </div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Role</label>
                <select name="role" class="form-control" onchange="syncDept(this)">
                    <option value="hr">HR Staff</option>
                    <option value="finance">Finance Staff</option>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Department</label>
                <select name="department" class="form-control" id="deptSel">
                    <option value="hr">HR Only</option>
                    <option value="finance">Finance Only</option>
                    <option value="all">All Departments</option>
                </select>
            </div>
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Staff</button>
    </div>
    </form>
</div>
</div>
<script>
function syncDept(sel) {
    document.getElementById('deptSel').value = sel.value;
}
document.getElementById('addModal').addEventListener('click', e => { if(e.target===document.getElementById('addModal')) e.target.classList.remove('open'); });
</script>
</body></html>