<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

date_default_timezone_set('Asia/Manila');

$can_hr      = ($portal_role === 'owner' || $portal_dept === 'hr'      || $portal_dept === 'all');
$can_finance = ($portal_role === 'owner' || $portal_dept === 'finance'  || $portal_dept === 'all');
$can_crm     = ($portal_role === 'owner' || $portal_dept === 'crm'      || $portal_dept === 'all');
$can_manage  = ($portal_role === 'owner');

if (!$can_hr && !$can_finance && !$can_crm && !$can_manage) {
    header('Location: dashboard.php'); exit;
}

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Archive'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

$tab    = $_GET['tab']    ?? ($can_hr ? 'employees' : ($can_finance ? 'income' : 'bookings'));
$view   = $_GET['view']   ?? 'active';
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['from'] ?? date('Y-m-01', strtotime('-3 months'));
$date_to   = $_GET['to']   ?? date('Y-m-d');

// ── RBAC: which tabs each dept can archive ──
$tab_access = [
    'employees'   => $can_hr,
    'attendance'  => $can_hr,
    'timekeeping' => $can_hr,
    'payroll'     => $can_hr || $can_finance,
    'leaves'      => $can_hr,
    'recruitment' => $can_hr,
    'income'      => $can_finance,
    'expenses'    => $can_finance,
    'budget'      => $can_finance,
    'bookings'    => $can_crm,
    'inv_requests'=> $can_crm,
];

// ── Table map ──
$tab_tables = [
    'employees'   => 'employees',
    'attendance'  => 'attendance',
    'timekeeping' => 'timekeeping',
    'payroll'     => 'payroll',
    'leaves'      => 'leave_requests',
    'recruitment' => 'recruitment',
    'income'      => 'income_records',
    'expenses'    => 'expense_records',
    'budget'      => 'budget_requests',
    'bookings'    => 'availed_services',
    'inv_requests'=> 'inventory_requests',
];

$tab_labels = [
    'employees'=>'Employees','attendance'=>'Attendance','timekeeping'=>'Timekeeping',
    'payroll'=>'Payroll','leaves'=>'Leave Requests','recruitment'=>'Recruitment',
    'income'=>'Income','expenses'=>'Expenses','budget'=>'Budget Requests',
    'bookings'=>'Bookings','inv_requests'=>'Inv. Requests',
];

$success = $error = '';
$archiver = $portal_full_name . ' (' . ucfirst($portal_role) . ')';

// ── POST: Archive / Unarchive ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']   ?? '';
    $post_tab = $_POST['tab']      ?? $tab;
    $ids      = array_map('intval', (array)($_POST['ids'] ?? []));

    if (!($tab_access[$post_tab] ?? false)) {
        $error = "You do not have permission to perform this action.";
    } elseif (empty($ids)) {
        $error = "No records selected.";
    } elseif (!isset($tab_tables[$post_tab])) {
        $error = "Unknown module.";
    } else {
        $tbl = $tab_tables[$post_tab];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($action === 'archive') {
            $params = [date('Y-m-d H:i:s'), $archiver, $pid];
            foreach ($ids as $id) $params[] = $id;
            $db->prepare("UPDATE `$tbl` SET is_archived=1, archived_at=?, archived_by=?
                          WHERE provider_id=? AND id IN ($placeholders) AND is_archived=0")
               ->execute($params);
            $success = count($ids) . " record(s) archived successfully.";
        } elseif ($action === 'unarchive') {
            $params = [$pid];
            foreach ($ids as $id) $params[] = $id;
            $db->prepare("UPDATE `$tbl` SET is_archived=0, archived_at=NULL, archived_by=NULL
                          WHERE provider_id=? AND id IN ($placeholders)")
               ->execute($params);
            $success = count($ids) . " record(s) restored from archive.";
        }
    }
}

// ── Fetch data ──
$data = [];
$af = (int)($view === 'archived');
$df = $date_from;
$dt = $date_to;
$q  = "%$search%";

if (!($tab_access[$tab] ?? false)) {
    foreach ($tab_access as $t => $ok) { if ($ok) { header("Location: ?tab=$t"); exit; } }
}

if ($tab === 'employees' && $can_hr) {
    $w = "e.provider_id=:p AND e.is_archived=:af"; $p=[':p'=>$pid,':af'=>$af];
    if ($view==='active') $w .= " AND e.status='inactive'";
    else { $w .= " AND DATE(e.archived_at) BETWEEN :df AND :dt"; $p[':df']=$df; $p[':dt']=$dt; }
    if ($search) { $w .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q OR e.employee_id LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT e.* FROM employees e WHERE $w ORDER BY e.updated_at DESC",$p);
}
elseif ($tab === 'attendance' && $can_hr) {
    $w = "a.provider_id=:p AND a.is_archived=:af AND a.date BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df,':dt'=>$dt];
    if ($search) { $w .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT a.*,CONCAT(e.first_name,' ',e.last_name) AS emp_name,e.department
                   FROM attendance a JOIN employees e ON a.employee_id=e.id WHERE $w ORDER BY a.date DESC",$p);
}
elseif ($tab === 'timekeeping' && $can_hr) {
    $w = "t.provider_id=:p AND t.is_archived=:af AND t.work_date BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df,':dt'=>$dt];
    if ($search) { $w .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT t.*,CONCAT(e.first_name,' ',e.last_name) AS emp_name,e.department,e.employee_id AS emp_code
                   FROM timekeeping t JOIN employees e ON t.employee_id=e.id WHERE $w ORDER BY t.work_date DESC",$p);
}
elseif ($tab === 'payroll' && ($can_hr||$can_finance)) {
    $w = "p.provider_id=:p AND p.is_archived=:af AND p.status='paid' AND p.pay_period_start BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df,':dt'=>$dt];
    if ($search) { $w .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT p.*,CONCAT(e.first_name,' ',e.last_name) AS emp_name,e.department
                   FROM payroll p JOIN employees e ON p.employee_id=e.id WHERE $w ORDER BY p.pay_period_start DESC",$p);
}
elseif ($tab === 'leaves' && $can_hr) {
    $w = "lr.provider_id=:p AND lr.is_archived=:af AND lr.status IN('approved','rejected') AND lr.updated_at BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df.' 00:00:00',':dt'=>$dt.' 23:59:59'];
    if ($search) { $w .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT lr.*,CONCAT(e.first_name,' ',e.last_name) AS emp_name,e.department
                   FROM leave_requests lr JOIN employees e ON lr.employee_id=e.id WHERE $w ORDER BY lr.updated_at DESC",$p);
}
elseif ($tab === 'recruitment' && $can_hr) {
    $w = "r.provider_id=:p AND r.is_archived=:af AND r.status IN('closed','on_hold')";
    $p=[':p'=>$pid,':af'=>$af];
    if ($view==='archived') { $w .= " AND DATE(r.archived_at) BETWEEN :df AND :dt"; $p[':df']=$df; $p[':dt']=$dt; }
    if ($search) { $w .= " AND r.job_title LIKE :q"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT r.*,
                   (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id=r.id) AS total_applicants,
                   (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id=r.id AND a.status='hired') AS hired
                   FROM recruitment r WHERE $w ORDER BY r.updated_at DESC",$p);
}
elseif ($tab === 'income' && $can_finance) {
    $w = "provider_id=:p AND is_archived=:af AND income_date BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df,':dt'=>$dt];
    if ($search) { $w .= " AND (income_type LIKE :q OR received_from LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT * FROM income_records WHERE $w ORDER BY income_date DESC",$p);
}
elseif ($tab === 'expenses' && $can_finance) {
    $w = "provider_id=:p AND is_archived=:af AND expense_date BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df,':dt'=>$dt];
    if ($search) { $w .= " AND (expense_type LIKE :q OR category LIKE :q OR paid_to LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT * FROM expense_records WHERE $w ORDER BY expense_date DESC",$p);
}
elseif ($tab === 'budget' && $can_finance) {
    $w = "provider_id=:p AND is_archived=:af AND status IN('approved','rejected','partially_approved') AND updated_at BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df.' 00:00:00',':dt'=>$dt.' 23:59:59'];
    if ($search) { $w .= " AND (department LIKE :q OR purpose LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT * FROM budget_requests WHERE $w ORDER BY updated_at DESC",$p);
}
elseif ($tab === 'bookings' && $can_crm) {
    $w = "provider_id=:p AND is_archived=:af AND status IN('completed','cancelled','rejected') AND created_at BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df.' 00:00:00',':dt'=>$dt.' 23:59:59'];
    if ($search) { $w .= " AND (full_name LIKE :q OR service_name LIKE :q)"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT * FROM availed_services WHERE $w ORDER BY created_at DESC",$p);
}
elseif ($tab === 'inv_requests' && $can_crm) {
    $w = "ir.provider_id=:p AND ir.is_archived=:af AND ir.status IN('fulfilled','rejected') AND ir.updated_at BETWEEN :df AND :dt";
    $p=[':p'=>$pid,':af'=>$af,':df'=>$df.' 00:00:00',':dt'=>$dt.' 23:59:59'];
    if ($search) { $w .= " AND ir.item_name LIKE :q"; $p[':q']=$q; }
    $data = safeAll($db,"SELECT ir.*,CONCAT(e.first_name,' ',e.last_name) AS emp_name
                   FROM inventory_requests ir LEFT JOIN employees e ON ir.requested_by=e.id WHERE $w ORDER BY ir.updated_at DESC",$p);
}

// CSV export
if (isset($_GET['export']) && !empty($data)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="archive_'.$tab.'_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, array_keys($data[0]));
    foreach ($data as $row) fputcsv($out, $row);
    fclose($out); exit;
}

$active_menu = 'archive';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Archive · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096;--arc:#7c3aed;--arc-dim:rgba(124,58,237,.1)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{margin-bottom:20px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:9px}
.alert-success{background:var(--arc-dim);border:1px solid rgba(124,58,237,.2);color:#4c1d95}
.alert-error{background:#fee2e2;border:1px solid rgba(220,38,38,.2);color:#991b1b}
/* Tabs */
.module-tabs{display:flex;gap:3px;margin-bottom:16px;flex-wrap:wrap;background:#fff;padding:5px;border-radius:12px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.05)}
.mtab{padding:7px 12px;border:none;background:transparent;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:5px;transition:all .2s;text-decoration:none;white-space:nowrap}
.mtab:hover{color:var(--dark);background:#f8fafc}
.mtab.active{background:var(--dark);color:#fff}
.mtab.locked{opacity:.3;cursor:not-allowed;pointer-events:none}
.tab-sep{width:1px;background:var(--border);margin:4px 2px;align-self:stretch}
/* View toggle */
.view-toggle{display:flex;margin-bottom:16px;border:1.5px solid var(--border);border-radius:9px;overflow:hidden;width:fit-content;background:#fff}
.vt{padding:8px 18px;border:none;background:transparent;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;color:var(--muted);display:flex;align-items:center;gap:6px;transition:all .2s;text-decoration:none}
.vt:first-child{border-right:1.5px solid var(--border)}
.vt.active{background:var(--arc);color:#fff}
/* Filter */
.filter-bar{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.fg input{padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff;min-width:130px}
.fg.flex1{flex:1;min-width:180px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-archive{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff}
.btn-restore{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff}
.btn-export{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
/* Stats */
.stats-row{display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.sstat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:9px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.sstat-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.sstat-info h4{font-size:17px;font-weight:700;color:var(--dark);line-height:1}
.sstat-info p{font-size:11px;color:var(--muted);margin-top:2px}
/* Table card */
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.card-head h2{font-size:13px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.bulk-bar{display:flex;gap:8px;align-items:center}
.sel-all{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);font-weight:600;cursor:pointer;user-select:none}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:9px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left;white-space:nowrap}
thead th.cb{width:36px;text-align:center}
tbody td{padding:9px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody td.cb{text-align:center}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
tbody tr.selected{background:#f5f0ff}
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.pill-green{background:#dcfce7;color:#166534}.pill-red{background:#fee2e2;color:#991b1b}
.pill-gray{background:#f1f5f9;color:#475569}.pill-orange{background:#fef3c7;color:#92400e}
.pill-blue{background:#dbeafe;color:#1e40af}.pill-purple{background:#ede9fe;color:#5b21b6}
.pill-teal{background:#d1fae5;color:#065f46}
.arc-meta{font-size:11px;color:var(--arc);font-style:italic}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
.empty-state i{font-size:40px;opacity:.2;display:block;margin-bottom:12px}
input[type=checkbox]{width:15px;height:15px;cursor:pointer;accent-color:var(--arc)}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-box-archive" style="color:var(--arc)"></i> Archive</h1>
    <p>Select records to archive — they disappear from active views but can be restored anytime.</p>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Module Tabs -->
<div class="module-tabs">
<?php
$icons = ['employees'=>'fa-id-badge','attendance'=>'fa-calendar-check','timekeeping'=>'fa-clock',
          'payroll'=>'fa-money-check-alt','leaves'=>'fa-file-alt','recruitment'=>'fa-user-plus',
          'income'=>'fa-arrow-up','expenses'=>'fa-arrow-down','budget'=>'fa-hand-holding-usd',
          'bookings'=>'fa-calendar-check','inv_requests'=>'fa-box-open'];
$groups = ['HR'=>['employees','attendance','timekeeping','payroll','leaves','recruitment'],
           'Finance'=>['income','expenses','budget'],'CRM'=>['bookings','inv_requests']];
$grp_ok = ['HR'=>$can_hr,'Finance'=>$can_finance,'CRM'=>$can_crm];
$sep = false;
foreach ($groups as $grp => $tabs):
    if (!$grp_ok[$grp]) continue;
    if ($sep) echo '<div class="tab-sep"></div>';
    $sep = true;
    foreach ($tabs as $t):
        $ok = $tab_access[$t] ?? false;
        $qs = "?tab=$t&view=$view&from=$date_from&to=$date_to";
?>
    <a href="<?= $ok ? $qs : '#' ?>" class="mtab <?= $tab===$t?'active':'' ?> <?= !$ok?'locked':'' ?>">
        <i class="fas <?= $icons[$t] ?>"></i> <?= $tab_labels[$t] ?>
    </a>
<?php endforeach; endforeach; ?>
</div>

<!-- View Toggle -->
<div class="view-toggle">
    <a href="?tab=<?= $tab ?>&view=active&from=<?= $date_from ?>&to=<?= $date_to ?>" class="vt <?= $view==='active'?'active':'' ?>">
        <i class="fas fa-list-check"></i> Archivable
    </a>
    <a href="?tab=<?= $tab ?>&view=archived&from=<?= $date_from ?>&to=<?= $date_to ?>" class="vt <?= $view==='archived'?'active':'' ?>">
        <i class="fas fa-box-archive"></i> Archived
    </a>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="<?= $tab ?>">
    <input type="hidden" name="view" value="<?= $view ?>">
    <div class="fg"><label>From</label><input type="date" name="from" value="<?= $date_from ?>"></div>
    <div class="fg"><label>To</label><input type="date" name="to" value="<?= $date_to ?>"></div>
    <div class="fg flex1"><label>Search</label><input type="text" name="search" placeholder="Name, type, dept…" value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    <a href="?tab=<?= $tab ?>&view=<?= $view ?>" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
    <?php if (!empty($data)): ?>
    <a href="?tab=<?= $tab ?>&view=<?= $view ?>&from=<?= $date_from ?>&to=<?= $date_to ?>&search=<?= urlencode($search) ?>&export=1" class="btn btn-export"><i class="fas fa-file-csv"></i> CSV</a>
    <?php endif; ?>
</form>

<!-- Stats -->
<div class="stats-row">
    <div class="sstat"><div class="sstat-icon"><i class="fas fa-box-archive"></i></div>
        <div class="sstat-info"><h4><?= count($data) ?></h4><p><?= $view==='archived'?'Archived':'Archivable' ?> Records</p></div></div>
    <?php if ($tab==='timekeeping'&&!empty($data)): ?>
    <div class="sstat"><div class="sstat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb)"><i class="fas fa-hourglass-half"></i></div>
        <div class="sstat-info"><h4><?= number_format(array_sum(array_column($data,'hours_worked')),1) ?>h</h4><p>Total Hours</p></div></div>
    <?php elseif ($tab==='payroll'&&!empty($data)): ?>
    <div class="sstat"><div class="sstat-icon" style="background:linear-gradient(135deg,#27ae60,#1e8449)"><i class="fas fa-peso-sign"></i></div>
        <div class="sstat-info"><h4>₱<?= number_format(array_sum(array_column($data,'net_salary')),0) ?></h4><p>Net Pay Total</p></div></div>
    <?php elseif (in_array($tab,['income','expenses'])&&!empty($data)): ?>
    <div class="sstat"><div class="sstat-icon" style="background:linear-gradient(135deg,#27ae60,#1e8449)"><i class="fas fa-peso-sign"></i></div>
        <div class="sstat-info"><h4>₱<?= number_format(array_sum(array_column($data,'amount')),0) ?></h4><p>Total Amount</p></div></div>
    <?php elseif ($tab==='bookings'&&!empty($data)): ?>
    <div class="sstat"><div class="sstat-icon" style="background:linear-gradient(135deg,#1abc9c,#16a085)"><i class="fas fa-peso-sign"></i></div>
        <div class="sstat-info"><h4>₱<?= number_format(array_sum(array_column($data,'total_amount')),0) ?></h4><p>Total Revenue</p></div></div>
    <?php endif; ?>
</div>

<!-- Table -->
<form method="POST" id="bulkForm">
<input type="hidden" name="tab" value="<?= $tab ?>">
<input type="hidden" name="action" id="bulkAction" value="">

<div class="card">
<div class="card-head">
    <h2>
        <i class="fas fa-<?= $view==='archived'?'box-archive':'list-check' ?>" style="color:<?= $view==='archived'?'var(--arc)':'var(--primary)' ?>"></i>
        <?= $view==='archived'?'Archived':'Archivable' ?> — <?= $tab_labels[$tab] ?>
        <span style="font-size:12px;font-weight:400;color:var(--muted);margin-left:4px"><?= date('M j',strtotime($date_from)) ?>–<?= date('M j, Y',strtotime($date_to)) ?></span>
    </h2>
    <div class="bulk-bar">
        <label class="sel-all"><input type="checkbox" id="selAll"> Select All</label>
        <?php if ($view==='active' && ($tab_access[$tab]??false)): ?>
        <button type="button" onclick="go('archive')" class="btn btn-archive btn-sm"><i class="fas fa-box-archive"></i> Archive Selected</button>
        <?php elseif ($view==='archived'): ?>
        <button type="button" onclick="go('unarchive')" class="btn btn-restore btn-sm"><i class="fas fa-rotate-left"></i> Restore Selected</button>
        <?php endif; ?>
    </div>
</div>
<div style="overflow-x:auto"><table>

<?php if (empty($data)): ?>
<tbody><tr><td colspan="20"><div class="empty-state">
    <i class="fas fa-<?= $view==='archived'?'box-archive':'inbox' ?>"></i>
    <p><?= $view==='archived'
        ? 'No archived records in this period.'
        : 'No archivable records found. Records become archivable once they reach a final state (completed, paid, inactive, closed, etc.).' ?></p>
</div></td></tr></tbody>

<?php elseif ($tab==='employees'): ?>
<thead><tr><th class="cb"></th><th>Employee ID</th><th>Name</th><th>Department</th><th>Position</th><th>Salary</th><th>Hire Date</th><?= $view==='archived'?'<th>Archived By</th><th>Archived At</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): ?>
<tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 6px;border-radius:4px"><?= htmlspecialchars($r['employee_id']) ?></code></td>
<td><strong><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['email']) ?></small></td>
<td><?= htmlspecialchars($r['department']??'—') ?></td>
<td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['position']??'—') ?></td>
<td>₱<?= number_format($r['basic_salary']??0,0) ?></td>
<td style="font-size:12px"><?= $r['hire_date']?date('M j, Y',strtotime($r['hire_date'])):'—' ?></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><td class="arc-meta"><?= $r['archived_at']?date('M j, Y H:i',strtotime($r['archived_at'])):'—' ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='attendance'): ?>
<thead><tr><th class="cb"></th><th>Date</th><th>Employee</th><th>Dept</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r):
[$pc,$pt]=match($r['status']){'present'=>['pill-green','Present'],'late'=>['pill-orange','Late'],'half_day'=>['pill-blue','Half Day'],'absent'=>['pill-red','Absent'],default=>['pill-gray',ucfirst($r['status'])]};
?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="white-space:nowrap;font-size:12px"><?= date('D M j, Y',strtotime($r['date'])) ?></td>
<td><strong><?= htmlspecialchars($r['emp_name']) ?></strong></td>
<td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['department']??'—') ?></td>
<td><?= $r['time_in']?date('h:i A',strtotime($r['time_in'])):'—' ?></td>
<td><?= $r['time_out']?date('h:i A',strtotime($r['time_out'])):'—' ?></td>
<td><?= $r['total_hours']?number_format($r['total_hours'],1).'h':'—' ?></td>
<td><span class="pill <?= $pc ?>"><?= $pt ?></span></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='timekeeping'): ?>
<thead><tr><th class="cb"></th><th>Date</th><th>Employee</th><th>Time In</th><th>Time Out</th><th>Regular</th><th>Overtime</th><th>Total</th><th>Late</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): ?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="white-space:nowrap;font-size:12px"><?= date('D M j',strtotime($r['work_date'])) ?></td>
<td><strong><?= htmlspecialchars($r['emp_name']) ?></strong><br><code style="font-size:10px;color:#6b46c1"><?= htmlspecialchars($r['emp_code']??'') ?></code></td>
<td><?= $r['time_in']?date('h:i A',strtotime($r['time_in'])):'—' ?></td>
<td><?= $r['time_out']?date('h:i A',strtotime($r['time_out'])):'—' ?></td>
<td><?= number_format($r['regular_hours']??0,2) ?>h</td>
<td><?= ($r['overtime_hours']??0)>0?'<span class="pill pill-purple">+'.number_format($r['overtime_hours'],2).'h</span>':'—' ?></td>
<td><strong><?= number_format($r['hours_worked']??0,2) ?>h</strong></td>
<td><?= ($r['late_minutes']??0)>0?'<span class="pill pill-red">'.$r['late_minutes'].'m</span>':'—' ?></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='payroll'): ?>
<thead><tr><th class="cb"></th><th>Period</th><th>Employee</th><th>Dept</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): ?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="font-size:12px"><?= htmlspecialchars($r['pay_period_name']??date('M Y',strtotime($r['pay_period_start']))) ?></td>
<td><strong><?= htmlspecialchars($r['emp_name']) ?></strong></td>
<td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['department']??'—') ?></td>
<td>₱<?= number_format($r['gross_salary']??0,0) ?></td>
<td>₱<?= number_format($r['deductions']??0,0) ?></td>
<td><strong style="color:#27ae60">₱<?= number_format($r['net_salary']??0,0) ?></strong></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='leaves'): ?>
<thead><tr><th class="cb"></th><th>Employee</th><th>Dept</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): $days=(int)((strtotime($r['end_date'])-strtotime($r['start_date']))/86400)+1; ?>
<tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td><strong><?= htmlspecialchars($r['emp_name']) ?></strong></td>
<td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['department']??'—') ?></td>
<td><span class="pill pill-purple"><?= ucfirst($r['leave_type']) ?></span></td>
<td style="font-size:12px"><?= date('M j, Y',strtotime($r['start_date'])) ?></td>
<td style="font-size:12px"><?= date('M j, Y',strtotime($r['end_date'])) ?></td>
<td><strong><?= $days ?></strong></td>
<td><span class="pill <?= $r['status']==='approved'?'pill-green':'pill-red' ?>"><?= ucfirst($r['status']) ?></span></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='recruitment'): ?>
<thead><tr><th class="cb"></th><th>Job Title</th><th>Dept</th><th>Posted</th><th>Applicants</th><th>Hired</th><th>Status</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): ?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td><strong><?= htmlspecialchars($r['job_title']) ?></strong></td>
<td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['department']??'—') ?></td>
<td style="font-size:12px"><?= $r['posted_date']?date('M j, Y',strtotime($r['posted_date'])):'—' ?></td>
<td><strong><?= $r['total_applicants'] ?></strong></td>
<td><span class="pill pill-green"><?= $r['hired'] ?> hired</span></td>
<td><span class="pill <?= $r['status']==='closed'?'pill-gray':'pill-orange' ?>"><?= ucfirst($r['status']) ?></span></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='income'): ?>
<thead><tr><th class="cb"></th><th>Date</th><th>Type</th><th>Amount</th><th>Received From</th><th>Method</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): ?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="font-size:12px;white-space:nowrap"><?= date('M j, Y',strtotime($r['income_date'])) ?></td>
<td><strong><?= htmlspecialchars($r['income_type']) ?></strong></td>
<td style="font-weight:600;color:#27ae60">₱<?= number_format($r['amount'],2) ?></td>
<td style="font-size:12px"><?= htmlspecialchars($r['received_from']??'—') ?></td>
<td><span class="pill pill-gray"><?= ucfirst(str_replace('_',' ',$r['payment_method']??'—')) ?></span></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='expenses'): ?>
<thead><tr><th class="cb"></th><th>Date</th><th>Type</th><th>Category</th><th>Amount</th><th>Paid To</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): ?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="font-size:12px;white-space:nowrap"><?= date('M j, Y',strtotime($r['expense_date'])) ?></td>
<td><strong><?= htmlspecialchars($r['expense_type']) ?></strong></td>
<td><span class="pill pill-gray"><?= htmlspecialchars($r['category']??'—') ?></span></td>
<td style="font-weight:600;color:#e74c3c">₱<?= number_format($r['amount'],2) ?></td>
<td style="font-size:12px"><?= htmlspecialchars($r['paid_to']??'—') ?></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='budget'): ?>
<thead><tr><th class="cb"></th><th>Date</th><th>Dept</th><th>Purpose</th><th>Requested</th><th>Approved</th><th>Status</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): ?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="font-size:12px;white-space:nowrap"><?= date('M j, Y',strtotime($r['request_date'])) ?></td>
<td><span class="pill pill-blue"><?= htmlspecialchars($r['department']) ?></span></td>
<td style="font-size:12px;max-width:160px"><?= htmlspecialchars(substr($r['purpose']??'—',0,60)) ?></td>
<td>₱<?= number_format($r['amount'],0) ?></td>
<td><?= $r['approved_amount']!==null?'<strong>₱'.number_format($r['approved_amount'],0).'</strong>':'—' ?></td>
<td><span class="pill <?= $r['status']==='approved'?'pill-green':($r['status']==='rejected'?'pill-red':'pill-orange') ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='bookings'): ?>
<thead><tr><th class="cb"></th><th>#</th><th>Client</th><th>Service</th><th>Date</th><th>Amount</th><th>Status</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r):
[$pc,$pt]=match($r['status']){'completed'=>['pill-teal','Completed'],'cancelled'=>['pill-red','Cancelled'],'rejected'=>['pill-red','Rejected'],default=>['pill-gray',ucwords(str_replace('_',' ',$r['status']))]};
?><tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="color:var(--muted);font-size:12px">#<?= $r['id'] ?></td>
<td><strong><?= htmlspecialchars($r['full_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['contact_number']) ?></small></td>
<td style="font-size:12px"><?= htmlspecialchars($r['service_name']??'—') ?></td>
<td style="font-size:12px;white-space:nowrap"><?= date('M j, Y',strtotime($r['preferred_date'])) ?></td>
<td style="font-weight:600"><?= $r['total_amount']>0?'₱'.number_format($r['total_amount'],0):'—' ?></td>
<td><span class="pill <?= $pc ?>"><?= $pt ?></span></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>

<?php elseif ($tab==='inv_requests'): ?>
<thead><tr><th class="cb"></th><th>Date</th><th>Requested By</th><th>Item</th><th>Qty</th><th>Urgency</th><th>Status</th><?= $view==='archived'?'<th>Archived By</th>':'' ?></tr></thead>
<tbody><?php foreach ($data as $r): $n=trim(($r['first_name']??'').' '.($r['last_name']??''))?:'Unknown'; ?>
<tr><td class="cb"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="rcb"></td>
<td style="font-size:12px;white-space:nowrap"><?= date('M j, Y',strtotime($r['created_at'])) ?></td>
<td><strong><?= htmlspecialchars($n) ?></strong></td>
<td><?= htmlspecialchars($r['item_name']) ?></td>
<td><?= $r['quantity_requested'] ?> <?= htmlspecialchars($r['unit']??'') ?></td>
<td><span class="pill <?= $r['urgency']==='urgent'?'pill-red':($r['urgency']==='normal'?'pill-blue':'pill-gray') ?>"><?= ucfirst($r['urgency']) ?></span></td>
<td><span class="pill <?= $r['status']==='fulfilled'?'pill-green':'pill-red' ?>"><?= ucfirst($r['status']) ?></span></td>
<?php if ($view==='archived'): ?><td class="arc-meta"><?= htmlspecialchars($r['archived_by']??'—') ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody>
<?php endif; ?>

</table></div></div>
</form>

</div></div></div>

<!-- Confirm Modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center;padding:20px">
<div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
        <div id="confirmIcon" style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0"></div>
        <div>
            <h3 id="confirmTitle" style="font-size:16px;font-weight:700;color:#1a2744;margin:0"></h3>
            <p id="confirmDesc" style="font-size:13px;color:#718096;margin:4px 0 0"></p>
        </div>
    </div>
    <div id="confirmDetail" style="background:#f8fafc;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#2d3748;border:1px solid #e2e8f0"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
        <button onclick="closeConfirm()" style="padding:10px 20px;border:1.5px solid #e2e8f0;background:#fff;border-radius:9px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;color:#718096;transition:all .2s">Cancel</button>
        <button id="confirmBtn" style="padding:10px 24px;border:none;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;color:#fff;display:flex;align-items:center;gap:7px;transition:all .2s"></button>
    </div>
</div>
</div>

<script>
document.getElementById('selAll')?.addEventListener('change', function() {
    document.querySelectorAll('.rcb').forEach(cb => cb.checked = this.checked);
    highlight();
});
document.addEventListener('change', e => { if (e.target.classList.contains('rcb')) highlight(); });
function highlight() {
    document.querySelectorAll('tbody tr').forEach(tr => {
        const cb = tr.querySelector('.rcb');
        if (cb) tr.classList.toggle('selected', cb.checked);
    });
}
function go(action) {
    const checked = document.querySelectorAll('.rcb:checked');
    if (!checked.length) { alert('Please select at least one record.'); return; }

    const isArchive = action === 'archive';
    const count = checked.length;

    // Collect names/ids for detail preview (up to 5)
    const rows = Array.from(checked).map(cb => {
        const row = cb.closest('tr');
        const nameEl = row.querySelector('td:nth-child(2) strong, td:nth-child(3) strong');
        return nameEl ? nameEl.textContent.trim() : 'Record #' + cb.value;
    });
    const preview = rows.slice(0,5).map(n => `<span style="display:block;padding:2px 0;border-bottom:1px solid #e2e8f0">&nbsp;· ${n}</span>`).join('');
    const more = rows.length > 5 ? `<span style="color:#718096;font-size:12px">+ ${rows.length-5} more…</span>` : '';

    document.getElementById('confirmModal').style.display = 'flex';
    document.getElementById('confirmIcon').style.background = isArchive
        ? 'linear-gradient(135deg,#7c3aed,#6d28d9)'
        : 'linear-gradient(135deg,#2563eb,#1d4ed8)';
    document.getElementById('confirmIcon').innerHTML = isArchive
        ? '<i class="fas fa-box-archive"></i>'
        : '<i class="fas fa-rotate-left"></i>';
    document.getElementById('confirmTitle').textContent = isArchive
        ? `Archive ${count} Record${count>1?'s':''}`
        : `Restore ${count} Record${count>1?'s':''}`;
    document.getElementById('confirmDesc').textContent = isArchive
        ? 'These records will be removed from active views.'
        : 'These records will be restored to active views.';
    document.getElementById('confirmDetail').innerHTML = preview + more;

    const btn = document.getElementById('confirmBtn');
    btn.style.background = isArchive
        ? 'linear-gradient(135deg,#7c3aed,#6d28d9)'
        : 'linear-gradient(135deg,#2563eb,#1d4ed8)';
    btn.innerHTML = isArchive
        ? '<i class="fas fa-box-archive"></i> Archive'
        : '<i class="fas fa-rotate-left"></i> Restore';
    btn.onclick = function() {
        document.getElementById('bulkAction').value = action;
        document.getElementById('bulkForm').submit();
    };
}

function closeConfirm() {
    document.getElementById('confirmModal').style.display = 'none';
}
// Close on backdrop click
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});
</script>
</body></html>