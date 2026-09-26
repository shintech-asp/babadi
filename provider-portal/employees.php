<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/employee_catalog.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;
// Sidebar defaults to the free tier when $tier_is_paid is unset — this page
// never loaded portal-tier.php, so a paid provider's own sidebar always
// showed Pro nav items as locked here.
require_once 'includes/portal-tier.php';
$positionOptions = pestifyEmployeePositionOptions();
$employmentTypeOptions = pestifyEmploymentTypeOptions();

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
require_once '../vendor/autoload.php';

function sendEmployeeWelcomeEmail($to_email, $to_name, $employee_id, $temp_password, $company) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(NOREPLY_EMAIL, $company . ' Portal');
        $mail->addAddress($to_email, $to_name);
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to ' . $company . ' — Your Portal Account';

        $login_url = SITE_URL . '/provider-portal/login.php';
        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f0f4f1;font-family:\'DM Sans\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f1;padding:40px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">

      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#1c6b3f,#2E8B57,#38a169);border-radius:16px 16px 0 0;padding:36px 40px;text-align:center;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:60px;height:60px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.25);border-radius:14px;margin-bottom:16px;">
          <span style="font-size:26px;">🌿</span>
        </div>
        <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;letter-spacing:-.3px;">Welcome to ' . htmlspecialchars($company) . '</h1>
        <p style="margin:6px 0 0;color:rgba(255,255,255,.8);font-size:14px;">Your employee portal account is ready</p>
      </td></tr>

      <!-- Body -->
      <tr><td style="background:#ffffff;padding:36px 40px;">
        <p style="margin:0 0 20px;color:#1e2d27;font-size:15px;line-height:1.6;">
          Hi <strong>' . htmlspecialchars($to_name) . '</strong>,<br><br>
          Your account has been created on the <strong>' . htmlspecialchars($company) . '</strong> employee portal. Use the credentials below to log in for the first time.
        </p>

        <!-- Credentials box -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6faf8;border:1.5px solid #dde5e0;border-radius:12px;margin-bottom:24px;">
          <tr><td style="padding:24px 28px;">
            <p style="margin:0 0 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#6b8077;">Your Login Credentials</p>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:8px 0;border-bottom:1px solid #dde5e0;">
                  <span style="font-size:12px;color:#6b8077;font-weight:600;">Employee ID</span>
                </td>
                <td style="padding:8px 0;border-bottom:1px solid #dde5e0;text-align:right;">
                  <code style="font-size:14px;font-weight:700;color:#2E8B57;background:#e8f5ee;padding:3px 10px;border-radius:6px;">' . htmlspecialchars($employee_id) . '</code>
                </td>
              </tr>
              <tr>
                <td style="padding:8px 0;">
                  <span style="font-size:12px;color:#6b8077;font-weight:600;">Temporary Password</span>
                </td>
                <td style="padding:8px 0;text-align:right;">
                  <code style="font-size:14px;font-weight:700;color:#2E8B57;background:#e8f5ee;padding:3px 10px;border-radius:6px;">' . htmlspecialchars($temp_password) . '</code>
                </td>
              </tr>
            </table>
          </td></tr>
        </table>

        <!-- Warning -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#fefcbf;border:1px solid rgba(217,119,6,.25);border-radius:10px;margin-bottom:28px;">
          <tr><td style="padding:14px 18px;font-size:13px;color:#78350f;">
            ⚠️ <strong>Important:</strong> You will be required to change this password upon your first login. Do not share your credentials with anyone.
          </td></tr>
        </table>

        <!-- CTA Button -->
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr><td align="center">
            <a href="' . $login_url . '" style="display:inline-block;background:linear-gradient(135deg,#2E8B57,#38a169);color:#fff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:10px;letter-spacing:.1px;">
              Log In to Portal →
            </a>
          </td></tr>
        </table>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f6faf8;border:1px solid #dde5e0;border-top:none;border-radius:0 0 16px 16px;padding:20px 40px;text-align:center;">
        <p style="margin:0;font-size:12px;color:#9ab3aa;line-height:1.6;">
          This is an automated message from <strong>' . htmlspecialchars($company) . '</strong>.<br>
          If you did not expect this email, please contact your HR administrator.
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>';

        $mail->AltBody = "Welcome to " . $company . "!\n\nYour portal account credentials:\nEmployee ID: $employee_id\nTemporary Password: $temp_password\n\nLog in at: $login_url\n\nYou will be required to change your password on first login.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if (!($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'all')) {
    header('Location: dashboard.php'); exit;
}

// Handle Add Employee
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $fn = trim($_POST['first_name']??'');
        $ln = trim($_POST['last_name']??'');
        $em = trim($_POST['email']??'');
        $positionSelection = trim($_POST['position'] ?? '');
        $customPosition = trim($_POST['custom_position'] ?? '');
        $pos = $positionSelection === '__custom__' ? $customPosition : $positionSelection;
        $dept = trim($_POST['department']??'');
        $sal = (float)($_POST['basic_salary']??0);
        $hire = $_POST['hire_date']??date('Y-m-d');
        $emp_type = $_POST['employment_type']??'regular';
        $staff_type = ($_POST['staff_type'] ?? 'office') === 'field' ? 'field' : 'office';
        $sss_no = trim($_POST['sss_no']??'');
        $philhealth_no = trim($_POST['philhealth_no']??'');
        $pagibig_no = trim($_POST['pagibig_no']??'');
        $tin_no = trim($_POST['tin_no']??'');
        $code = 'EMP-'.strtoupper(substr($ln,0,3)).'-'.rand(1000,9999);
        $tmp_pwd = 'Pass@'.rand(10000,99999);
        $hash = password_hash($tmp_pwd, PASSWORD_BCRYPT);
        $emailCheckStmt = $db->prepare("SELECT id FROM employees WHERE email = :em");
        $emailCheckStmt->execute([':em' => $em]);
        if ($positionSelection === '__custom__' && $customPosition === '') {
            $error = 'Please enter the custom position title.';
        } elseif ($emailCheckStmt->fetch(PDO::FETCH_ASSOC)) {
            $error = 'An employee with this email already exists. Employee login requires a unique email.';
        } else {
            try {
                $s = $db->prepare("INSERT INTO employees (provider_id,employee_id,first_name,last_name,email,position,department,staff_type,basic_salary,salary,hire_date,employment_type,sss_no,philhealth_no,pagibig_no,tin_no,status,temp_password,password_hash,must_change_pwd,created_at) VALUES (:pid,:code,:fn,:ln,:em,:pos,:dept,:stype,:sal,:sal,:hire,:etype,:sss,:phil,:pag,:tin,'active',:tmp,:hash,1,NOW())");
                $s->execute([':pid'=>$pid,':code'=>$code,':fn'=>$fn,':ln'=>$ln,':em'=>$em,':pos'=>$pos,':dept'=>$dept,':stype'=>$staff_type,':sal'=>$sal,':hire'=>$hire,':etype'=>$emp_type,':sss'=>$sss_no,':phil'=>$philhealth_no,':pag'=>$pagibig_no,':tin'=>$tin_no,':tmp'=>$tmp_pwd,':hash'=>$hash]);
                $email_sent = sendEmployeeWelcomeEmail($em, "$fn $ln", $code, $tmp_pwd, $portal_company);
                $email_note = $email_sent
                    ? "<span style='color:#16a34a'><i class='fas fa-envelope-circle-check'></i> Welcome email sent to <strong>$em</strong>.</span>"
                    : "<span style='color:#d97706'><i class='fas fa-triangle-exclamation'></i> Employee added but email could not be sent. Share credentials manually.</span>";
                $success = "Employee <strong>$fn $ln</strong> added. Code: <code>$code</code> | Temp Password: <code>$tmp_pwd</code><br><small style='margin-top:4px;display:block'>$email_note</small>";
            } catch(Exception $e) {
                $error = "Error: ".$e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'update_status') {
        $eid = (int)($_POST['emp_id']??0);
        $st = $_POST['status']??'active';
        $db->prepare("UPDATE employees SET status=:s WHERE id=:id AND provider_id=:p")->execute([':s'=>$st,':id'=>$eid,':p'=>$pid]);
        $success = "Employee status updated.";

    } elseif ($_POST['action'] === 'promote' && $portal_role === 'owner') {
        $eid    = (int)($_POST['emp_id']??0);
        $dept   = $_POST['promote_dept'] ?? 'hr';
        $emp    = safeRow($db, "SELECT * FROM employees WHERE id=:id AND provider_id=:p", [':id'=>$eid,':p'=>$pid]);
        if ($emp) {
            // Check if already a staff member
            $existing = safeRow($db, "SELECT id FROM provider_staff WHERE provider_id=:p AND email=:e", [':p'=>$pid,':e'=>$emp['email']]);
            if ($existing) {
                // Update existing staff record
                $role_map = ['hr'=>'hr','finance'=>'finance','crm'=>'crm'];
                $role = $role_map[$dept] ?? 'hr';
                $db->prepare("UPDATE provider_staff SET role=:r, department=:d, status='active' WHERE id=:id")
                   ->execute([':r'=>$role,':d'=>$dept,':id'=>$existing['id']]);
                $success = "<strong>{$emp['first_name']} {$emp['last_name']}</strong> has been updated to <strong>" . ucfirst($dept) . " Manager</strong>.";
            } else {
                // Create new staff record
                $role_map = ['hr'=>'hr','finance'=>'finance','crm'=>'crm'];
                $role = $role_map[$dept] ?? 'hr';
                $full_name = trim($emp['first_name'].' '.$emp['last_name']);
                $username  = strtolower(str_replace(' ','_',$full_name)).rand(10,99);
                $tmp_pwd   = 'Mgr@'.rand(10000,99999);
                $hash      = password_hash($tmp_pwd, PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO provider_staff (provider_id,full_name,username,email,password_hash,temp_password,role,department,must_change_password,status)
                              VALUES (:pid,:fn,:un,:em,:hash,:tmp,:role,:dept,1,'active')")
                   ->execute([':pid'=>$pid,':fn'=>$full_name,':un'=>$username,':em'=>$emp['email'],':hash'=>$hash,':tmp'=>$tmp_pwd,':role'=>$role,':dept'=>$dept]);
                $success = "<strong>{$emp['first_name']} {$emp['last_name']}</strong> promoted to <strong>" . ucfirst($dept) . " Manager</strong>. Portal login: <code>{$username}</code> | Temp Password: <code>{$tmp_pwd}</code>";
            }
        }

    } elseif ($_POST['action'] === 'demote' && $portal_role === 'owner') {
        $eid = (int)($_POST['emp_id']??0);
        $emp = safeRow($db, "SELECT * FROM employees WHERE id=:id AND provider_id=:p", [':id'=>$eid,':p'=>$pid]);
        if ($emp) {
            $db->prepare("UPDATE provider_staff SET status='inactive' WHERE provider_id=:p AND email=:e AND role != 'owner'")
               ->execute([':p'=>$pid,':e'=>$emp['email']]);
            $success = "<strong>{$emp['first_name']} {$emp['last_name']}</strong> has been demoted back to employee.";
        }
    }
}

// Filters
$search = trim($_GET['search']??'');
$dept_filter = $_GET['dept']??'';
$status_filter = $_GET['status']??'active';

$where = "e.provider_id=:p";
$params = [':p'=>$pid];
if ($search) { $where .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s OR e.employee_id LIKE :s OR e.email LIKE :s)"; $params[':s']="%$search%"; }
if ($dept_filter) { $where .= " AND e.department=:d"; $params[':d']=$dept_filter; }
if ($status_filter) { $where .= " AND e.status=:st"; $params[':st']=$status_filter; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeRow($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){return null;}}
$employees = safeAll($db,"SELECT e.*, IF(ps.id IS NOT NULL AND ps.status='active', 1, 0) AS is_manager, ps.role AS staff_role, ps.department AS staff_dept
                FROM employees e
                LEFT JOIN provider_staff ps ON ps.email=e.email AND ps.provider_id=e.provider_id AND ps.role != 'owner'
                WHERE $where ORDER BY e.first_name,e.last_name",$params);
$departments = safeAll($db,"SELECT DISTINCT department FROM employees WHERE provider_id=:p AND department IS NOT NULL ORDER BY department",[':p'=>$pid]);

$active_menu='employees';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Employees - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filters input,.filters select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#2d3748}
.filters input{flex:1;min-width:180px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-green{background:#c6f6d5;color:#276749}
.badge-gray{background:#e2e8f0;color:#4a5568}
.badge-orange{background:#feebc8;color:#c05621}
.badge-purple{background:#f0e6ff;color:#6b46c1}
.emp-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#9b59b6,#8e44ad);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;flex-shrink:0}
.emp-info{display:flex;align-items:center;gap:10px}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,89,182,.15)}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
.empty-state i{font-size:40px;margin-bottom:12px;opacity:.3}
.btn-success{background:#27ae60;color:#fff}
.btn-warning{background:#e67e22;color:#fff}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <div>
        <h1><i class="fas fa-id-badge"></i> Employees</h1>
        <p><?= count($employees) ?> employee(s) found</p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-plus"></i> Add Employee</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<form method="GET" class="filters">
    <input type="text" name="search" placeholder="Search name, code, email…" value="<?= htmlspecialchars($search) ?>">
    <select name="dept">
        <option value="">All Departments</option>
        <?php foreach($departments as $d): ?>
        <option value="<?= htmlspecialchars($d['department']) ?>" <?= $dept_filter===$d['department']?'selected':'' ?>><?= ucfirst(htmlspecialchars($d['department'])) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">All Status</option>
        <option value="active" <?= $status_filter==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status_filter==='inactive'?'selected':'' ?>>Inactive</option>
        <option value="on_leave" <?= $status_filter==='on_leave'?'selected':'' ?>>On Leave</option>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    <a href="employees.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
</form>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead>
        <tr><th>Employee</th><th>Code</th><th>Department</th><th>Position</th><th>Type</th><th>Salary</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if(empty($employees)): ?>
    <tr><td colspan="8"><div class="empty-state"><i class="fas fa-users"></i><p>No employees found.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach($employees as $e): $initials = strtoupper(substr($e['first_name'],0,1).substr($e['last_name'],0,1)); ?>
    <tr>
        <td>
            <div class="emp-info">
                <div class="emp-avatar"><?= $initials ?></div>
                <div>
                    <strong><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></strong>
                    <?php if (($e['staff_type'] ?? 'office') === 'field'): ?>
                    <span class="badge badge-green" style="font-size:9px;padding:1px 6px;margin-left:4px"><i class="fas fa-toolbox"></i> Field</span>
                    <?php endif; ?>
                    <br><small style="color:var(--muted)"><?= htmlspecialchars($e['email']) ?></small>
                </div>
            </div>
        </td>
        <td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 6px;border-radius:5px"><?= htmlspecialchars($e['employee_id']??'—') ?></code></td>
        <td><span class="badge badge-purple"><?= ucfirst(htmlspecialchars($e['department']??'—')) ?></span></td>
        <td style="color:var(--muted);font-size:12px"><?= htmlspecialchars($e['position']??'—') ?></td>
        <td><span class="badge badge-gray"><?= ucfirst(str_replace('_',' ',$e['employment_type']??'—')) ?></span></td>
        <td style="font-weight:600">₱<?= number_format($e['basic_salary']??0,0) ?></td>
        <td>
            <span class="badge <?= $e['status']==='active'?'badge-green':($e['status']==='on_leave'?'badge-orange':'badge-gray') ?>"><?= ucfirst(str_replace('_',' ',$e['status'])) ?></span>
            <?php if (!empty($e['is_manager'])): ?>
            <span class="badge" style="background:#fff3e0;color:#e67e22;margin-top:3px;display:inline-block">
                <i class="fas fa-user-tie" style="font-size:9px"></i> <?= ucfirst($e['staff_dept'] ?? '') ?> Mgr
            </span>
            <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
            <button onclick="openEdit(<?= htmlspecialchars(json_encode($e)) ?>)" class="btn btn-primary btn-sm" title="Edit Status"><i class="fas fa-edit"></i></button>
            <?php if ($portal_role === 'owner'): ?>
            <button onclick="openPromote(<?= htmlspecialchars(json_encode($e)) ?>)" class="btn btn-sm <?= $e['is_manager'] ? 'btn-warning' : 'btn-success' ?>" title="<?= $e['is_manager'] ? 'Demote to Employee' : 'Promote to Manager' ?>">
                <i class="fas <?= $e['is_manager'] ? 'fa-user-minus' : 'fa-user-tie' ?>"></i>
            </button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Add Employee Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-user-plus" style="color:var(--primary)"></i> Add New Employee</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group"><label>First Name *</label><input type="text" name="first_name" required></div>
        <div class="form-group"><label>Last Name *</label><input type="text" name="last_name" required></div>
        <div class="form-group full"><label>Email *</label><input type="email" name="email" required></div>
        <div class="form-group"><label>Position</label>
            <select name="position" id="portalPositionSelect">
                <option value="">Select a position</option>
                <?php foreach ($positionOptions as $positionOption): ?>
                <option value="<?= htmlspecialchars($positionOption) ?>"><?= htmlspecialchars($positionOption) ?></option>
                <?php endforeach; ?>
                <option value="__custom__">Other / Custom</option>
            </select>
        </div>
        <div class="form-group" id="portalCustomPositionWrap" style="display:none;">
            <label>Custom Position</label>
            <input type="text" name="custom_position" id="portalCustomPositionInput">
        </div>
        <div class="form-group"><label>Department</label>
            <select name="department" id="portalDepartmentSelect">
                <option value="">— Select Department —</option>
                <option value="Finance">Finance</option>
                <option value="Human Resources">Human Resources</option>
                <option value="Customer Relationship">Customer Relationship</option>
                <option value="Field Operations">Field Operations</option>
            </select>
        </div>
        <div class="form-group"><label>Basic Salary (₱)</label><input type="number" name="basic_salary" min="0" step="0.01"></div>
        <div class="form-group"><label>Hire Date</label><input type="date" name="hire_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label>Employment Type</label>
            <select name="employment_type">
                <?php foreach ($employmentTypeOptions as $typeValue => $typeLabel): ?>
                <option value="<?= htmlspecialchars($typeValue) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Staff Type *</label>
            <select name="staff_type" id="portalStaffTypeSelect" required>
                <option value="office">Office Staff</option>
                <option value="field">Field Technician</option>
            </select>
            <small id="portalStaffTypeHint" style="color:var(--muted);font-size:11px;display:block;margin-top:4px">Field Technicians can be assigned to services and handle bookings on-site. Office Staff cannot.</small>
        </div>
        <div class="form-group"><label>SSS No.</label><input type="text" name="sss_no" placeholder="Optional"></div>
        <div class="form-group"><label>PhilHealth No.</label><input type="text" name="philhealth_no" placeholder="Optional"></div>
        <div class="form-group"><label>Pag-IBIG No.</label><input type="text" name="pagibig_no" placeholder="Optional"></div>
        <div class="form-group"><label>TIN</label><input type="text" name="tin_no" placeholder="Optional"></div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Employee</button>
    </div>
    </form>
</div>
</div>

<!-- Edit Employee Modal -->
<div class="modal-overlay" id="editModal">
<div class="modal">
    <h3><i class="fas fa-edit" style="color:var(--primary)"></i> Edit Employee</h3>
    <form method="POST">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="emp_id" id="edit_id">
    <div class="form-grid">
        <div class="form-group full"><label>Status</label>
            <select name="status" id="edit_status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="on_leave">On Leave</option>
            </select>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('editModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
    </div>
    </form>
</div>
</div>

<!-- Promote/Demote Modal -->
<div class="modal-overlay" id="promoteModal">
<div class="modal">
    <h3 id="promoteTitle"><i class="fas fa-user-tie" style="color:var(--primary)"></i> Promote to Manager</h3>
    <form method="POST" id="promoteForm">
    <input type="hidden" name="action" id="promote_action" value="promote">
    <input type="hidden" name="emp_id" id="promote_emp_id">
    <div id="promoteBody">
        <p id="promoteDesc" style="font-size:13px;color:var(--muted);margin-bottom:16px"></p>
        <div class="form-group" id="deptGroup">
            <label>Assign Department *</label>
            <select name="promote_dept" id="promote_dept" class="form-control" style="padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit">
                <option value="hr">Human Resources (HR Manager)</option>
                <option value="finance">Finance (Finance Manager)</option>
                <option value="crm">CRM (CRM Manager)</option>
            </select>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('promoteModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn" id="promoteBtn" style="background:linear-gradient(135deg,#27ae60,#16a085);color:#fff"><i class="fas fa-check"></i> <span id="promoteBtnText">Promote</span></button>
    </div>
    </form>
</div>
</div>

<script>
function openEdit(emp) {
    document.getElementById('edit_id').value = emp.id;
    document.getElementById('edit_status').value = emp.status;
    document.getElementById('editModal').classList.add('active');
}

function openPromote(emp) {
    const modal  = document.getElementById('promoteModal');
    const name   = emp.first_name + ' ' + emp.last_name;
    const isMgr  = parseInt(emp.is_manager) === 1;

    document.getElementById('promote_emp_id').value = emp.id;

    if (isMgr) {
        document.getElementById('promoteTitle').innerHTML = '<i class="fas fa-user-minus" style="color:#e74c3c"></i> Demote to Employee';
        document.getElementById('promoteDesc').textContent = 'Remove manager access for ' + name + '? They will no longer be able to log in to the portal as a manager.';
        document.getElementById('deptGroup').style.display = 'none';
        document.getElementById('promote_action').value = 'demote';
        document.getElementById('promoteBtn').style.background = 'linear-gradient(135deg,#e74c3c,#c0392b)';
        document.getElementById('promoteBtnText').textContent = 'Demote';
    } else {
        document.getElementById('promoteTitle').innerHTML = '<i class="fas fa-user-tie" style="color:var(--primary)"></i> Promote to Manager';
        document.getElementById('promoteDesc').textContent = 'Grant portal manager access to ' + name + '. They will receive a new login credential.';
        document.getElementById('deptGroup').style.display = 'flex';
        document.getElementById('promote_action').value = 'promote';
        document.getElementById('promoteBtn').style.background = 'linear-gradient(135deg,#27ae60,#16a085)';
        document.getElementById('promoteBtnText').textContent = 'Promote';
    }

    modal.classList.add('active');
}

document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('active'); }));
const portalPositionSelect = document.getElementById('portalPositionSelect');
const portalCustomPositionWrap = document.getElementById('portalCustomPositionWrap');
const portalCustomPositionInput = document.getElementById('portalCustomPositionInput');
if (portalPositionSelect && portalCustomPositionWrap && portalCustomPositionInput) {
    const syncPortalPositionMode = () => {
        const customSelected = portalPositionSelect.value === '__custom__';
        portalCustomPositionWrap.style.display = customSelected ? 'flex' : 'none';
        portalCustomPositionInput.required = customSelected;
        if (!customSelected) portalCustomPositionInput.value = '';
    };
    syncPortalPositionMode();
    portalPositionSelect.addEventListener('change', syncPortalPositionMode);
}

// Adaptive Department -> Position / Staff Type filtering for Add Employee.
const PORTAL_DEPT_CATALOG = <?= json_encode(pestifyEmployeeDepartmentCatalog()) ?>;
const PORTAL_POSITION_DEFAULT_STAFF_TYPE = <?= json_encode(pestifyPositionDefaultStaffType()) ?>;
const PORTAL_ALL_POSITIONS = <?= json_encode($positionOptions) ?>;
(function () {
    const deptSelect      = document.getElementById('portalDepartmentSelect');
    const positionSelect  = document.getElementById('portalPositionSelect');
    const staffTypeSelect = document.getElementById('portalStaffTypeSelect');
    const staffTypeHint   = document.getElementById('portalStaffTypeHint');
    if (!deptSelect || !positionSelect || !staffTypeSelect) return;

    function rebuildPositionOptions() {
        const dept = deptSelect.value;
        const positions = (PORTAL_DEPT_CATALOG[dept] && PORTAL_DEPT_CATALOG[dept].positions) || PORTAL_ALL_POSITIONS;
        const previousValue = positionSelect.value;

        positionSelect.innerHTML = '';
        const blankOpt = document.createElement('option');
        blankOpt.value = ''; blankOpt.textContent = 'Select a position';
        positionSelect.appendChild(blankOpt);
        positions.forEach(function (p) {
            const opt = document.createElement('option');
            opt.value = p; opt.textContent = p;
            positionSelect.appendChild(opt);
        });
        const customOpt = document.createElement('option');
        customOpt.value = '__custom__'; customOpt.textContent = 'Other / Custom';
        positionSelect.appendChild(customOpt);

        // Keep the previous selection if it's still valid for this department.
        if (previousValue && (positions.includes(previousValue) || previousValue === '__custom__')) {
            positionSelect.value = previousValue;
        }
        positionSelect.dispatchEvent(new Event('change'));
    }

    function syncStaffTypeForDepartment() {
        const dept = deptSelect.value;
        const allowed = (PORTAL_DEPT_CATALOG[dept] && PORTAL_DEPT_CATALOG[dept].allowed_staff_types) || ['office', 'field'];
        Array.from(staffTypeSelect.options).forEach(function (opt) {
            opt.disabled = !allowed.includes(opt.value);
        });
        if (!allowed.includes(staffTypeSelect.value)) {
            staffTypeSelect.value = allowed[0];
        }
        if (staffTypeHint) {
            staffTypeHint.textContent = allowed.length === 1
                ? (dept + ' is office-only — Field Technician isn’t applicable here.')
                : 'Field Technicians can be assigned to services and handle bookings on-site. Office Staff cannot.';
        }
    }

    function suggestStaffTypeForPosition() {
        const dept = deptSelect.value;
        const allowed = (PORTAL_DEPT_CATALOG[dept] && PORTAL_DEPT_CATALOG[dept].allowed_staff_types) || ['office', 'field'];
        const suggestion = PORTAL_POSITION_DEFAULT_STAFF_TYPE[positionSelect.value];
        if (suggestion && allowed.includes(suggestion)) {
            staffTypeSelect.value = suggestion;
        }
    }

    deptSelect.addEventListener('change', function () {
        syncStaffTypeForDepartment();
        rebuildPositionOptions();
    });
    positionSelect.addEventListener('change', suggestStaffTypeForPosition);
})();
</script>
</body></html>
