<?php
// provider-portal/dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

require_once 'includes/portal-tier.php';

// Safe query helpers - won't crash if tables don't exist yet
function safeCount($db, $sql, $params = []) {
    try { $s=$db->prepare($sql);$s->execute($params);return (int)$s->fetchColumn(); } catch(Exception $e){return 0;}
}
function safeSum($db, $sql, $params = []) {
    try { $s=$db->prepare($sql);$s->execute($params);return (float)$s->fetchColumn(); } catch(Exception $e){return 0;}
}
function safeAll($db, $sql, $params = []) {
    try { $s=$db->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){return [];}
}

// Best-effort, lazy-triggered reminder: whoever loads a provider-portal
// dashboard today (owner, staff, or the employee) fires the check for any
// of this provider's bookings scheduled today with an assigned technician.
require_once appPath('includes/service_reminder_helper.php');
sendDueServiceReminders($db, $pid);

// ── Non-promoted employee accounts get their own dashboard: only what's
// theirs (their attendance, their leave, their assigned services calendar)
// — never company-wide finance/HR data. ──
if ((($_SESSION['portal_account_type'] ?? 'staff') === 'employee')) {
    require_once 'includes/employee-dashboard.php';
    exit;
}

$total_emp     = safeCount($db, "SELECT COUNT(*) FROM employees WHERE provider_id=:p AND status='active'", [':p'=>$pid]);
$pending_leave = safeCount($db, "SELECT COUNT(*) FROM leave_requests WHERE provider_id=:p AND status='pending'", [':p'=>$pid]);
$total_income  = safeSum($db,   "SELECT COALESCE(SUM(amount),0) FROM income_records WHERE provider_id=:p AND YEAR(date)=YEAR(NOW())", [':p'=>$pid]);
$total_expense = safeSum($db,   "SELECT COALESCE(SUM(amount),0) FROM expense_records WHERE provider_id=:p AND YEAR(date)=YEAR(NOW())", [':p'=>$pid]);
$service_rev   = safeSum($db,   "SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE provider_id=:p AND status='completed' AND YEAR(created_at)=YEAR(NOW())", [':p'=>$pid]);
$total_income += $service_rev;
$net = $total_income - $total_expense;

$today         = date('Y-m-d');
$present_today = safeCount($db, "SELECT COUNT(*) FROM attendance WHERE provider_id=:p AND date=:d AND status IN('present','late')", [':p'=>$pid,':d'=>$today]);
$recent_emps   = safeAll($db,   "SELECT * FROM employees WHERE provider_id=:p ORDER BY created_at DESC LIMIT 5", [':p'=>$pid]);

// Booking stats (owner only)
$book_pending   = safeCount($db, "SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status='pending'", [':p'=>$pid]);
$book_active    = safeCount($db, "SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status IN('waiting_provider_confirmation','preparing','on_the_way','in_progress')", [':p'=>$pid]);
$book_completed = safeCount($db, "SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status='completed'", [':p'=>$pid]);
$book_cancelled = safeCount($db, "SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status IN('cancelled','rejected')", [':p'=>$pid]);
$recent_bookings = safeAll($db,  "SELECT * FROM availed_services WHERE provider_id=:p ORDER BY created_at DESC LIMIT 8", [':p'=>$pid]);

$chart_labels = []; $chart_income = []; $chart_expense = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chart_labels[] = date('M Y', strtotime("-$i months"));
    $inc  = safeSum($db, "SELECT COALESCE(SUM(amount),0) FROM income_records WHERE provider_id=:p AND DATE_FORMAT(date,'%Y-%m')=:m", [':p'=>$pid,':m'=>$m]);
    $inc += safeSum($db, "SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE provider_id=:p AND status='completed' AND DATE_FORMAT(created_at,'%Y-%m')=:m", [':p'=>$pid,':m'=>$m]);
    $exp  = safeSum($db, "SELECT COALESCE(SUM(amount),0) FROM expense_records WHERE provider_id=:p AND DATE_FORMAT(date,'%Y-%m')=:m", [':p'=>$pid,':m'=>$m]);
    $chart_income[]  = round($inc, 2);
    $chart_expense[] = round($exp, 2);
}

// C1 fix: use the canonical $tier_is_paid from portal-tier.php (already included above)
// Old plan-column-based check (plan='hr'/'finance'/'crm') is from the legacy model;
// the new flow always inserts plan='pro'. Use $tier_is_paid for all owner gating.
if ($portal_role === 'owner') {
    $sub_hr = $sub_finance = $sub_crm = $tier_is_paid;
} else {
    $sub_hr      = ($portal_dept === 'hr'      || $portal_dept === 'all');
    $sub_finance = ($portal_dept === 'finance' || $portal_dept === 'all');
    $sub_crm     = ($portal_dept === 'crm'     || $portal_dept === 'all');
}
$can_hr      = $sub_hr;
$can_finance = $sub_finance;
$can_manage  = ($portal_role === 'owner');
$active_menu = 'dashboard';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Dashboard - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{margin-bottom:26px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:14px;transition:all .25s}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.1)}
.stat-icon{width:50px;height:50px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.stat-info h3{font-size:22px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted);margin-top:2px}
.analytics-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:22px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
.card-body{padding:16px 20px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.dept-links{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px 20px}
.dept-card{border-radius:12px;padding:18px;text-decoration:none;display:flex;flex-direction:column;gap:8px;transition:all .2s;color:#fff}
.dept-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.15)}
.dept-card i{font-size:26px}
.dept-card h3{font-size:15px;font-weight:700}
.dept-card p{font-size:11px;opacity:.8}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:11px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr)}.analytics-grid{grid-template-columns:1fr}}
@media(max-width:768px){.stats-grid{grid-template-columns:1fr}.dept-links{grid-template-columns:1fr}}
.booking-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.bk-card{background:#fff;padding:16px 18px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px;text-decoration:none;transition:all .2s;cursor:default}
.bk-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.1)}
.bk-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff;flex-shrink:0}
.bk-pending{background:linear-gradient(135deg,#f39c12,#e67e22)}
.bk-active{background:linear-gradient(135deg,#3498db,#2980b9)}
.bk-done{background:linear-gradient(135deg,#27ae60,#16a085)}
.bk-cancel{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.bk-info h3{font-size:22px;font-weight:700;color:var(--dark)}
.bk-info p{font-size:11px;color:var(--muted);margin-top:2px}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.sp-pending{background:#fef3c7;color:#92400e}
.sp-active{background:#dbeafe;color:#1e40af}
.sp-preparing{background:#e0f2fe;color:#0369a1}
.sp-completed{background:#dcfce7;color:#166534}
.sp-cancelled{background:#fee2e2;color:#991b1b}
.sp-waiting{background:#f3e8ff;color:#6b21a8}
@media(max-width:900px){.booking-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.stats-grid{grid-template-columns:1fr}.dept-links{grid-template-columns:1fr}.booking-stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-home"></i> Portal Dashboard</h1>
    <p><?= htmlspecialchars($portal_company) ?> &nbsp;·&nbsp; <?= date('l, F j, Y') ?> &nbsp;·&nbsp; Logged in as <strong><?= htmlspecialchars($portal_full_name) ?></strong> (<?= ucfirst($portal_role) ?>)</p>
</div>

<?php if (!$tier_is_paid): ?>
<div style="background:#eef2ff;border:1.5px solid #c7d2fe;border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#3730a3">
    <i class="fas fa-star" style="flex-shrink:0;font-size:16px"></i>
    <div><strong>Free Tier.</strong> Some stats require Pro. <a href="subscriptions.php" style="color:#4f46e5;font-weight:700">Upgrade to unlock all dashboard metrics &rarr;</a></div>
</div>
<?php endif; ?>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-id-badge"></i></div><div class="stat-info"><h3><?= $total_emp ?></h3><p>Active Employees</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-file-alt"></i></div><div class="stat-info"><h3><?= $pending_leave ?></h3><p>Pending Leave</p></div></div>
    <?php if ($tier_is_paid): ?>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-arrow-up"></i></div><div class="stat-info"><h3>&#8369;<?= number_format($total_income, 0) ?></h3><p>Income This Year</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-arrow-down"></i></div><div class="stat-info"><h3>&#8369;<?= number_format($total_expense, 0) ?></h3><p>Expenses This Year</p></div></div>
    <?php else: ?>
    <div class="stat-card" style="opacity:.55;cursor:not-allowed" title="Pro feature"><div class="stat-icon" style="background:linear-gradient(135deg,#818cf8,#6366f1)"><i class="fas fa-lock"></i></div><div class="stat-info"><h3>&mdash;</h3><p>Income (Pro)</p></div></div>
    <div class="stat-card" style="opacity:.55;cursor:not-allowed" title="Pro feature"><div class="stat-icon" style="background:linear-gradient(135deg,#818cf8,#6366f1)"><i class="fas fa-lock"></i></div><div class="stat-info"><h3>&mdash;</h3><p>Expenses (Pro)</p></div></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-th-large"></i> Departments</h2></div>
    <div class="dept-links">
        <?php if ($can_hr): ?>
        <a href="hr-dashboard.php" class="dept-card" style="background:linear-gradient(135deg,#9b59b6,#8e44ad)">
            <i class="fas fa-users"></i><h3>HR Department</h3><p>Employees, Attendance, Payroll, Recruitment</p>
        </a>
        <?php endif; ?>
        <?php if ($can_finance): ?>
        <a href="finance-dashboard.php" class="dept-card" style="background:linear-gradient(135deg,#27ae60,#16a085)">
            <i class="fas fa-chart-line"></i><h3>Finance Department</h3><p>Income, Expenses, Budget Requests</p>
        </a>
        <?php endif; ?>
        <?php if ($can_manage): ?>
        <a href="employees.php" class="dept-card" style="background:linear-gradient(135deg,#3498db,#2980b9)">
            <i class="fas fa-id-badge"></i><h3>Employees</h3><p>Add employees and promote to HR/Finance/CRM manager</p>
        </a>
        <a href="schedules.php" class="dept-card" style="background:linear-gradient(135deg,#e67e22,#d35400)">
            <i class="fas fa-calendar-alt"></i><h3>Schedules</h3><p>Manage work schedules and events</p>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_manage): ?>
<!-- ── Bookings Overview ── -->
<div style="margin-bottom:6px;display:flex;justify-content:space-between;align-items:center">
    <h2 style="font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px"><i class="fas fa-calendar-check" style="color:#3498db"></i> Bookings Overview</h2>
    <a href="booking-management.php" class="btn btn-primary" style="font-size:12px;padding:6px 14px"><i class="fas fa-external-link-alt"></i> Manage All</a>
</div>
<div class="booking-stats">
    <div class="bk-card"><div class="bk-icon bk-pending"><i class="fas fa-clock"></i></div><div class="bk-info"><h3><?= $book_pending ?></h3><p>Pending</p></div></div>
    <div class="bk-card"><div class="bk-icon bk-active"><i class="fas fa-spinner"></i></div><div class="bk-info"><h3><?= $book_active ?></h3><p>Active / In Progress</p></div></div>
    <div class="bk-card"><div class="bk-icon bk-done"><i class="fas fa-circle-check"></i></div><div class="bk-info"><h3><?= $book_completed ?></h3><p>Completed</p></div></div>
    <div class="bk-card"><div class="bk-icon bk-cancel"><i class="fas fa-circle-xmark"></i></div><div class="bk-info"><h3><?= $book_cancelled ?></h3><p>Cancelled / Rejected</p></div></div>
</div>

<div class="card" style="margin-bottom:22px">
    <div class="card-header">
        <h2><i class="fas fa-list-check"></i> Recent Bookings</h2>
        <a href="booking-management.php" class="btn btn-primary" style="font-size:12px;padding:6px 14px"><i class="fas fa-eye"></i> View All</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>#</th><th>Client</th><th>Service</th><th>Date &amp; Time</th><th>Amount</th><th>Payment</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($recent_bookings)): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted)">No bookings yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent_bookings as $b):
            $status = $b['status'];
            $pill = match(true) {
                $status === 'pending'                        => ['sp-pending',  'fa-clock',        'Pending'],
                $status === 'waiting_provider_confirmation'  => ['sp-waiting',  'fa-hourglass-half','Awaiting Confirm'],
                $status === 'preparing'                      => ['sp-preparing','fa-box',          'Preparing'],
                in_array($status,['on_the_way','in_progress'])=> ['sp-active',  'fa-spinner',      ucwords(str_replace('_',' ',$status))],
                $status === 'completed'                      => ['sp-completed','fa-circle-check', 'Completed'],
                default                                      => ['sp-cancelled','fa-circle-xmark', ucwords(str_replace('_',' ',$status))],
            };
        ?>
        <tr>
            <td style="font-size:12px;color:var(--muted)">#<?= $b['id'] ?></td>
            <td>
                <strong style="font-size:13px"><?= htmlspecialchars($b['full_name']) ?></strong>
                <br><small style="color:var(--muted)"><?= htmlspecialchars($b['contact_number']) ?></small>
            </td>
            <td style="font-size:13px;max-width:160px"><?= htmlspecialchars($b['service_name'] ?? '—') ?></td>
            <td style="font-size:12px;white-space:nowrap">
                <?= date('M j, Y', strtotime($b['preferred_date'])) ?>
                <br><span style="color:var(--muted)"><?= date('h:i A', strtotime($b['preferred_time'])) ?></span>
            </td>
            <td style="font-weight:600;font-size:13px">
                <?= $b['total_amount'] > 0 ? '₱'.number_format($b['total_amount'],0) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td>
                <?php
                $ps = $b['payment_status'];
                $pc = $ps==='paid'?'#27ae60':($ps==='partial'?'#e67e22':'#e74c3c');
                ?>
                <span style="font-size:11px;font-weight:700;color:<?= $pc ?>"><?= ucfirst($ps) ?></span>
            </td>
            <td><span class="status-pill <?= $pill[0] ?>"><i class="fas <?= $pill[1] ?>"></i> <?= $pill[2] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="analytics-grid">
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-chart-bar"></i> Income vs Expenses (Last 6 Months)</h2></div>
        <div class="card-body"><canvas id="finChart" height="110"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-info-circle"></i> Quick Summary</h2></div>
        <div class="card-body">
            <div style="margin-bottom:14px;padding:14px;background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0">
                <div style="font-size:11px;color:#718096;margin-bottom:4px">Net Profit (This Year)</div>
                <div style="font-size:24px;font-weight:800;color:<?= $net>=0?'#27ae60':'#e74c3c' ?>"><?= $net<0?'-':'' ?>₱<?= number_format(abs($net),0) ?></div>
                <div style="font-size:11px;color:<?= $net>=0?'#27ae60':'#e74c3c' ?>"><?= $net>=0?'Profitable':'At a loss' ?> this year</div>
            </div>
            <div style="padding:14px;background:#f0e6ff;border-radius:10px;border:1px solid #d6bcfa">
                <div style="font-size:11px;color:#718096;margin-bottom:4px">Present Today</div>
                <div style="font-size:24px;font-weight:800;color:#6b46c1"><?= $present_today ?> / <?= $total_emp ?></div>
                <div style="font-size:11px;color:#6b46c1">Employees on site</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-id-badge"></i> Recent Employees</h2>
        <a href="employees.php" class="btn btn-primary"><i class="fas fa-eye"></i> View All</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Position</th><th>Department</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($recent_emps)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">No employees yet. <a href="employees.php" style="color:var(--primary);font-weight:600">Add your first employee →</a></td></tr>
        <?php endif; ?>
        <?php foreach ($recent_emps as $e): ?>
        <tr>
            <td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 6px;border-radius:5px"><?= htmlspecialchars($e['employee_id']) ?></code></td>
            <td style="font-weight:600"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($e['position'] ?? '—') ?></td>
            <td><span style="background:#f0e6ff;color:#6b46c1;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700"><?= ucfirst($e['department'] ?? '—') ?></span></td>
            <td><span style="background:<?= $e['status']==='active'?'#c6f6d5':'#e2e8f0' ?>;color:<?= $e['status']==='active'?'#276749':'#4a5568' ?>;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700"><?= ucfirst($e['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></div></div>
<script>
new Chart(document.getElementById('finChart'),{type:'bar',data:{labels:<?= json_encode($chart_labels) ?>,datasets:[{label:'Income',data:<?= json_encode($chart_income) ?>,backgroundColor:'rgba(39,174,96,.7)',borderRadius:5},{label:'Expenses',data:<?= json_encode($chart_expense) ?>,backgroundColor:'rgba(231,76,60,.5)',borderRadius:5}]},options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{callback:v=>'₱'+(v>=1000?(v/1000).toFixed(0)+'k':v)}}}}});
</script>
</body></html>