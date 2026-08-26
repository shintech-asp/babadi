<?php
// admin/user-management.php
$allowed_roles = ['super_admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = $error = '';

// ── Add Admin User ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_admin') {
    $full_name  = trim($_POST['full_name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? 'admin';
    $dept       = $_POST['department'] ?? 'all';
    $temp_pass  = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#'), 0, 10);

    // Only super_admin can create super_admin
    if ($role === 'super_admin' && ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
        $error = 'Only Super Admin can create another Super Admin.';
    } elseif (empty($full_name) || empty($username) || empty($email)) {
        $error = 'Full name, username, and email are required.';
    } else {
        // Check duplicate
        $chk = $db->prepare("SELECT id FROM admin_users WHERE username=:u OR email=:e");
        $chk->execute([':u' => $username, ':e' => $email]);
        if ($chk->rowCount()) {
            $error = 'Username or email already exists.';
        } else {
            $hash = password_hash($temp_pass, PASSWORD_DEFAULT);
            $ins  = $db->prepare("INSERT INTO admin_users (username,email,full_name,role,department,password_hash,temp_password,must_change_password,status) VALUES(:u,:e,:n,:r,:d,:h,:tp,1,'active')");
            $ins->execute([':u'=>$username,':e'=>$email,':n'=>$full_name,':r'=>$role,':d'=>$dept,':h'=>$hash,':tp'=>$temp_pass]);

            // Send email with temp password
            $subject = "Your Pestify Admin Account";
            $body    = "Hello $full_name,\n\nYour admin account has been created.\n\nUsername: $username\nTemporary Password: $temp_pass\n\nPlease log in and change your password immediately.\n\nURL: " . SITE_URL . "/admin/admin-login.php\n\nRegards,\nPestify System";
            @mail($email, $subject, $body, "From: " . NOREPLY_EMAIL);

            $success = "Admin user <strong>$username</strong> created. Temporary password: <code>$temp_pass</code> (also emailed).";
        }
    }
}

// ── Toggle Status ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    $uid    = (int)$_POST['user_id'];
    $status = $_POST['status'] === 'active' ? 'inactive' : 'active';
    if ($uid !== (int)$_SESSION['admin_id']) {
        $db->prepare("UPDATE admin_users SET status=:s WHERE id=:id")->execute([':s'=>$status,':id'=>$uid]);
        $success = 'Status updated.';
    } else { $error = 'Cannot change your own status.'; }
}

// ── Delete ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $uid = (int)$_POST['user_id'];
    if ($uid !== (int)$_SESSION['admin_id']) {
        $db->prepare("DELETE FROM admin_users WHERE id=:id")->execute([':id'=>$uid]);
        $success = 'Admin user deleted.';
    } else { $error = 'Cannot delete yourself.'; }
}

// ── Fetch all admin users ─────────────────────────────────────
$admins = $db->query("SELECT * FROM admin_users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'user_mgmt';
$super_admin_total = count(array_filter($admins, static fn($row) => ($row['role'] ?? '') === 'super_admin'));
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>User Management - Pestify Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}
.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px}
.page-header h1{font-size:26px;font-weight:700;color:#1a2744;display:flex;align-items:center;gap:10px}
.page-header h1 i{color:var(--primary)}
.hero-banner{background:linear-gradient(135deg,rgba(46,139,87,.12),rgba(59,91,219,.08));border:1px solid rgba(46,139,87,.16);border-radius:18px;padding:18px 20px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.hero-meta{display:flex;gap:10px;flex-wrap:wrap}
.hero-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:#fff;border:1px solid var(--border);font-size:12px;font-weight:700;color:#1a2744}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{background:#276749;transform:translateY(-1px)}
.btn-danger{background:#fed7d7;color:#c53030;border:1px solid #feb2b2}.btn-danger:hover{background:#feb2b2}
.btn-sm{padding:6px 14px;font-size:12px}
.alert{padding:14px 18px;border-radius:10px;margin-bottom:22px;font-size:14px;display:flex;align-items:center;gap:10px}
.alert-success{background:#c6f6d5;color:#276749;border-left:4px solid #38a169}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:28px}
.card-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:16px;font-weight:700;color:#1a2744;display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:14px 16px;border-bottom:1px solid var(--border);font-size:14px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700}
.badge-active{background:#c6f6d5;color:#276749}.badge-inactive{background:#e2e8f0;color:#4a5568}
.badge-suspended{background:#fed7d7;color:#c53030}
.role-badge{padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.role-super_admin{background:#feebc8;color:#c05621}
.role-admin{background:#bee3f8;color:#2b6cb0}
.role-hr{background:#e9d8fd;color:#6b46c1}
.role-finance{background:#c6f6d5;color:#276749}
.role-moderator{background:#e2e8f0;color:#4a5568}
.dept-badge{padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700;background:#f0f4ff;color:#3b5bdb}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .3s}
.modal-head{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-head h3{font-size:17px;font-weight:700;color:#1a2744}
.modal-close{background:none;border:none;font-size:20px;color:var(--muted);cursor:pointer}
.modal-body{padding:24px}
.modal-foot{padding:16px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:13px;font-weight:600;color:#2d3748;margin-bottom:6px}
.form-control{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit;transition:border .2s}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,139,87,.12)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.info-box{background:#f0fff4;border:1px solid #9ae6b4;border-radius:10px;padding:14px 16px;font-size:13px;color:#276749;margin-bottom:20px}
@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-user-shield"></i> User Management</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')">
        <i class="fas fa-plus"></i> Add Admin User
    </button>
</div>

<div class="hero-banner">
    <div>
        <div style="font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--primary);margin-bottom:6px">Super Admin Workspace</div>
        <div style="font-size:14px;color:var(--muted)">Manage admin access, secure privileged roles, and keep back-office accounts aligned with department access.</div>
    </div>
    <div class="hero-meta">
        <span class="hero-chip"><i class="fas fa-users-cog"></i> <?= count($admins) ?> admin account(s)</span>
        <span class="hero-chip"><i class="fas fa-shield-check"></i> <?= $super_admin_total ?> super admin(s)</span>
        <span class="hero-chip"><i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Super Admin') ?></span>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<div class="info-box"><i class="fas fa-info-circle" style="margin-right:6px;"></i> This page manages <strong>admin portal users only</strong>. Each user is assigned a role and department. HR users can only access HR features; Finance users can only access Finance features.</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-users-cog"></i> Admin Users</h2>
        <span style="font-size:13px;color:var(--muted)"><?= count($admins) ?> admin(s)</span>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>#</th><th>Name / Email</th><th>Username</th><th>Role</th><th>Department</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
        <tr>
            <td style="color:var(--muted);font-size:12px"><?= $a['id'] ?></td>
            <td>
                <div style="font-weight:600"><?= htmlspecialchars($a['full_name']) ?></div>
                <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($a['email']) ?></div>
            </td>
            <td><code style="font-size:12px;background:#f8fafc;padding:2px 7px;border-radius:5px"><?= htmlspecialchars($a['username']) ?></code></td>
            <td><span class="role-badge role-<?= $a['role'] ?>"><?= str_replace('_',' ',ucfirst($a['role'])) ?></span></td>
            <td><span class="dept-badge"><?= ucfirst($a['department'] ?? 'all') ?></span></td>
            <td><span class="badge badge-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= $a['last_login'] ? date('M d, Y H:i', strtotime($a['last_login'])) : 'Never' ?></td>
            <td>
                <?php if ($a['id'] != $_SESSION['admin_id']): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                    <input type="hidden" name="status" value="<?= $a['status'] ?>">
                    <button class="btn btn-sm" style="background:#e2e8f0;color:#4a5568" type="submit"><?= $a['status']==='active'?'Deactivate':'Activate' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this admin user?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-trash"></i></button>
                </form>
                <?php else: ?>
                <span style="font-size:12px;color:var(--muted)">You</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div><!-- /main-content -->
</div><!-- /dashboard-container -->

<!-- Add Admin Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal-box">
    <div class="modal-head">
        <h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px"></i>Add Admin User</h3>
        <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button>
    </div>
    <form method="POST">
    <input type="hidden" name="action" value="add_admin">
    <div class="modal-body">
        <div class="alert alert-success" style="margin-bottom:16px"><i class="fas fa-key"></i> A temporary password will be auto-generated and emailed to the new user. They must change it on first login.</div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="full_name" class="form-control" required placeholder="Juan Dela Cruz">
            </div>
            <div class="form-group">
                <label class="form-label">Username *</label>
                <input type="text" name="username" class="form-control" required placeholder="jdelacruz">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Email Address *</label>
            <input type="email" name="email" class="form-control" required placeholder="juan@pestify.com">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Role *</label>
                <select name="role" class="form-control" onchange="syncDept(this)">
                    <option value="admin">Admin</option>
                    <option value="hr">HR Staff</option>
                    <option value="finance">Finance Staff</option>
                    <option value="moderator">Moderator</option>
                    <?php if (($_SESSION['admin_role']??'')=='super_admin'): ?>
                    <option value="super_admin">Super Admin</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Department Access</label>
                <select name="department" class="form-control" id="deptSelect">
                    <option value="all">All Departments</option>
                    <option value="hr">HR Only</option>
                    <option value="finance">Finance Only</option>
                    <option value="management">Management Only</option>
                </select>
            </div>
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create User</button>
    </div>
    </form>
</div>
</div>

<script>
function syncDept(sel) {
    const dept = document.getElementById('deptSelect');
    if (sel.value === 'hr')      { dept.value = 'hr'; }
    else if (sel.value === 'finance') { dept.value = 'finance'; }
    else if (sel.value === 'super_admin' || sel.value === 'admin') { dept.value = 'all'; }
}
document.getElementById('addModal').addEventListener('click', e => { if(e.target===document.getElementById('addModal')) e.target.classList.remove('open'); });
</script>
</body></html>
