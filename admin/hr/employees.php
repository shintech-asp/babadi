<?php
// admin/hr/employees.php
$require_dept = 'hr';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/employee_catalog.php';
$database = new Database(); $db = $database->getConnection();

$positionOptions = pestifyEmployeePositionOptions();
$employmentTypeOptions = pestifyEmploymentTypeOptions();

$success = $error = '';

// ── Add Employee ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add') {
    $fn    = trim($_POST['first_name']??'');
    $ln    = trim($_POST['last_name']??'');
    $email = trim($_POST['email']??'');
    $positionSelection = trim($_POST['position'] ?? '');
    $customPosition = trim($_POST['custom_position'] ?? '');
    $pos   = $positionSelection === '__custom__' ? $customPosition : $positionSelection;
    $dept  = $_POST['department'] ?? 'operations';
    $sal   = (float)($_POST['basic_salary']??0);
    $hired = $_POST['date_hired'] ?? null;
    $type  = $_POST['employment_type'] ?? 'regular';
    $sss   = trim($_POST['sss_no']??'');
    $phil  = trim($_POST['philhealth_no']??'');
    $pag   = trim($_POST['pagibig_no']??'');
    $tin   = trim($_POST['tin_no']??'');
    $phone = trim($_POST['phone']??'');

    if ($positionSelection === '__custom__' && $customPosition === '') {
        $error = 'Please enter the custom position title.';
    } elseif (!$fn || !$ln || !$email) { $error = 'First name, last name and email are required.'; }
    else {
        // Generate employee code. employees has no separate "code" column —
        // employee_id (varchar UNIQUE) IS the human-readable code column
        // (existing rows look like "EMP-102-7719"); the old employee_code
        // reference here pointed at a column that doesn't exist at all.
        $last = $db->query("SELECT employee_id FROM employees ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num  = $last ? ((int)substr($last,3) + 1) : 1;
        $code = 'EMP' . str_pad($num, 4, '0', STR_PAD_LEFT);
        // Generate QR token
        $qr_token = bin2hex(random_bytes(16));
        // Temp password
        $temp_pass = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
        $hash      = password_hash($temp_pass, PASSWORD_DEFAULT);

        try {
            // date_hired -> hire_date: employees has no date_hired column.
            $ins = $db->prepare("INSERT INTO employees (employee_id,first_name,last_name,email,phone,position,department,employment_type,hire_date,basic_salary,sss_no,philhealth_no,pagibig_no,tin_no,qr_token) VALUES(:ec,:fn,:ln,:em,:ph,:pos,:dept,:et,:dh,:sal,:sss,:phil,:pag,:tin,:qr)");
            $ins->execute([':ec'=>$code,':fn'=>$fn,':ln'=>$ln,':em'=>$email,':ph'=>$phone,':pos'=>$pos,':dept'=>$dept,':et'=>$type,':dh'=>$hired,':sal'=>$sal,':sss'=>$sss,':phil'=>$phil,':pag'=>$pag,':tin'=>$tin,':qr'=>$qr_token]);
            $emp_id = $db->lastInsertId();

            // Create admin_users entry with department = hr
            $admin_ins = $db->prepare("INSERT INTO admin_users (username,email,full_name,role,department,password_hash,temp_password,must_change_password,status) VALUES(:u,:e,:n,'hr','hr',:h,:tp,1,'active')");
            $admin_ins->execute([':u'=>strtolower($fn[0].$ln),'e'=>$email,':n'=>"$fn $ln",':h'=>$hash,':tp'=>$temp_pass]);
            // Note: employees has no admin_user_id column to link back to the
            // admin_users row just created (a UPDATE against it here always
            // threw and was silently swallowed below, making Add Employee
            // report "Error" on every submission even though both rows were
            // actually inserted). No code reads that link, so it's dropped
            // rather than invented — the employees row and its admin_users
            // login are still both created correctly.

            // Email
            $subject = "Welcome to Pestify — Your Employee Account";
            $body    = "Hi $fn $ln,\n\nWelcome! Your employee account has been created.\n\nEmployee Code: $code\nLogin URL: " . SITE_URL . "/admin/admin-login.php\nUsername: " . strtolower($fn[0].$ln) . "\nTemporary Password: $temp_pass\n\nPlease change your password on first login.\n\nRegards,\nPestify HR";
            @mail($email, $subject, $body, "From: " . NOREPLY_EMAIL);

            $success = "Employee <strong>$fn $ln</strong> ($code) added. Login details sent to $email. Temp password: <code>$temp_pass</code>";
        } catch(Exception $ex) { $error = 'Error: ' . $ex->getMessage(); }
    }
}

// ── Update Status ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'update_status') {
    $db->prepare("UPDATE employees SET status=:s WHERE id=:id")->execute([':s'=>$_POST['status'],':id'=>(int)$_POST['emp_id']]);
    $success = 'Employee status updated.';
}

// ── Fetch ─────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$dfilter = $_GET['dept'] ?? '';
$sql = "SELECT * FROM employees WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (first_name LIKE :q OR last_name LIKE :q OR employee_id LIKE :q OR email LIKE :q)"; $params[':q'] = "%$search%"; }
if ($dfilter) { $sql .= " AND department=:dept"; $params[':dept'] = $dfilter; }
$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'employees';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Employees - Pestify HR</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{background:#8e44ad}
.btn-sm{padding:6px 12px;font-size:12px}
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filters input,.filters select{padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.filters input:focus,.filters select:focus{outline:none;border-color:var(--primary)}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#e9d8fd;color:#6b46c1;border-left:4px solid #9b59b6}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:13px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-active{background:#c6f6d5;color:#276749}.b-inactive{background:#e2e8f0;color:#4a5568}
.b-resigned{background:#feebc8;color:#c05621}.b-terminated{background:#fed7d7;color:#c53030}
.emp-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#9b59b6,#8e44ad);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:640px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .3s}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-head h3{font-size:16px;font-weight:700;color:var(--dark)}
.modal-close{background:none;border:none;font-size:20px;color:var(--muted);cursor:pointer}
.modal-body{padding:22px;overflow-y:auto;flex:1}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;flex-shrink:0}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:600;color:#2d3748;margin-bottom:5px}
.form-control{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,89,182,.12)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--primary);margin-bottom:12px;margin-top:4px;border-bottom:1px solid #f0e6ff;padding-bottom:6px}
@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-id-badge"></i> Employees</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')"><i class="fas fa-plus"></i> Add Employee</button>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div><?php endif; ?>

<form method="GET" class="filters">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, code, email...">
    <select name="dept"><option value="">All Departments</option>
        <?php foreach(['management','hr','finance','operations','field'] as $d): ?>
        <option value="<?=$d?>" <?=$dfilter===$d?'selected':''?>><?=ucfirst($d)?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    <a href="employees.php" class="btn" style="background:#f0f0f0;color:#555">Reset</a>
</form>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-users"></i> Employee List</h2>
        <span style="font-size:12px;color:var(--muted)"><?= count($employees) ?> employee(s)</span>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Employee</th><th>Position</th><th>Department</th><th>Type</th><th>Salary</th><th>Date Hired</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (empty($employees)): ?><tr><td colspan="9" style="text-align:center;padding:50px;color:var(--muted)"><i class="fas fa-users" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3"></i>No employees found</td></tr><?php endif; ?>
        <?php foreach ($employees as $e): ?>
        <tr>
            <td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 7px;border-radius:5px"><?= $e['employee_id'] ?></code></td>
            <td>
                <div style="display:flex;align-items:center;gap:10px">
                    <div class="emp-avatar"><?= strtoupper(substr($e['first_name'],0,1)) ?></div>
                    <div>
                        <div style="font-weight:600"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></div>
                        <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($e['email']) ?></div>
                    </div>
                </div>
            </td>
            <td><?= htmlspecialchars($e['position']??'—') ?></td>
            <td><span style="background:#f0e6ff;color:#6b46c1;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700"><?= ucfirst($e['department']??'—') ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= ucfirst(str_replace('_',' ',$e['employment_type']??'')) ?></td>
            <td style="font-weight:600;color:#27ae60">₱<?= number_format($e['basic_salary'],2) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= $e['hire_date'] ? date('M d, Y',strtotime($e['hire_date'])) : '—' ?></td>
            <td><span class="badge b-<?= $e['status'] ?>"><?= ucfirst($e['status']) ?></span></td>
            <td>
                <a href="attendance.php?emp_id=<?= $e['id'] ?>" class="btn btn-sm" style="background:#f0e6ff;color:#6b46c1"><i class="fas fa-user-clock"></i></a>
                <a href="payroll.php?emp_id=<?= $e['id'] ?>" class="btn btn-sm" style="background:#c6f6d5;color:#276749"><i class="fas fa-money-check"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></div>

<!-- Add Employee Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal-box">
    <div class="modal-head">
        <h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px"></i>Add New Employee</h3>
        <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button>
    </div>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="modal-body">
        <div class="section-title">Personal Information</div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" placeholder="09XXXXXXXXX"></div>
        </div>
        <div class="section-title" style="margin-top:8px">Employment Details</div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Position</label>
                <select name="position" id="adminPositionSelect" class="form-control">
                    <option value="">Select a position</option>
                    <?php foreach ($positionOptions as $positionOption): ?>
                    <option value="<?= htmlspecialchars($positionOption) ?>"><?= htmlspecialchars($positionOption) ?></option>
                    <?php endforeach; ?>
                    <option value="__custom__">Other / Custom</option>
                </select>
            </div>
            <div class="form-group" id="adminCustomPositionWrap" style="display:none;">
                <label class="form-label">Custom Position</label>
                <input type="text" name="custom_position" id="adminCustomPositionInput" class="form-control" placeholder="Enter custom position title">
            </div>
            <div class="form-group"><label class="form-label">Department</label>
                <select name="department" class="form-control">
                    <?php foreach(['management','hr','finance','operations','field'] as $d): ?>
                    <option value="<?=$d?>"><?=ucfirst($d)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Employment Type</label>
                <select name="employment_type" class="form-control">
                    <?php foreach ($employmentTypeOptions as $typeValue => $typeLabel): ?>
                    <option value="<?= htmlspecialchars($typeValue) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Date Hired</label><input type="date" name="date_hired" class="form-control"></div>
            <div class="form-group"><label class="form-label">Basic Salary (₱)</label><input type="number" name="basic_salary" class="form-control" step="0.01" min="0" placeholder="0.00"></div>
        </div>
        <div class="section-title" style="margin-top:8px">Government Numbers</div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">SSS No.</label><input type="text" name="sss_no" class="form-control"></div>
            <div class="form-group"><label class="form-label">PhilHealth No.</label><input type="text" name="philhealth_no" class="form-control"></div>
            <div class="form-group"><label class="form-label">Pag-IBIG No.</label><input type="text" name="pagibig_no" class="form-control"></div>
            <div class="form-group"><label class="form-label">TIN No.</label><input type="text" name="tin_no" class="form-control"></div>
        </div>
        <div style="background:#f0e6ff;border:1px solid #d6bcfa;border-radius:10px;padding:12px 14px;font-size:12px;color:#6b46c1;margin-top:4px">
            <i class="fas fa-envelope" style="margin-right:6px"></i>Login credentials will be auto-generated and emailed to the employee. They must change their password on first login.
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Employee</button>
    </div>
    </form>
</div>
</div>
<script>
document.getElementById('addModal').addEventListener('click',e=>{if(e.target===document.getElementById('addModal'))e.target.classList.remove('open')});
const adminPositionSelect = document.getElementById('adminPositionSelect');
const adminCustomPositionWrap = document.getElementById('adminCustomPositionWrap');
const adminCustomPositionInput = document.getElementById('adminCustomPositionInput');
if (adminPositionSelect && adminCustomPositionWrap && adminCustomPositionInput) {
    const syncAdminPositionMode = () => {
        const customSelected = adminPositionSelect.value === '__custom__';
        adminCustomPositionWrap.style.display = customSelected ? 'block' : 'none';
        adminCustomPositionInput.required = customSelected;
        if (!customSelected) adminCustomPositionInput.value = '';
    };
    syncAdminPositionMode();
    adminPositionSelect.addEventListener('change', syncAdminPositionMode);
}
</script>
</body></html>
