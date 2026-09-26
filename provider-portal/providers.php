<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;
// Sidebar defaults to the free tier when $tier_is_paid is unset — this page
// never loaded portal-tier.php, so a paid provider's own sidebar always
// showed Pro nav items as locked here.
require_once 'includes/portal-tier.php';

if (!($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'all')) {
    header('Location: dashboard.php');
    exit;
}

function safeAll($db, $sql, $params = []) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function weekdaysBetween($start, $end) {
    $days = 0;
    $cur = new DateTime($start);
    $last = new DateTime($end);
    while ($cur <= $last) {
        if ((int)$cur->format('N') <= 5) $days++;
        $cur->modify('+1 day');
    }
    return $days;
}

function autoEnd($start, $type) {
    $d = new DateTime($start);
    if ($type === '15days') {
        $d->modify('+14 day');
    } else {
        $d->modify('+1 month');
        $d->modify('-1 day');
    }
    return $d->format('Y-m-d');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate') {
        $empIds = $_POST['emp_ids'] ?? [];
        $periodType = $_POST['period_type'] ?? 'monthly';
        $periodStart = $_POST['period_start'] ?? '';
        $periodEnd = $_POST['period_end'] ?? '';
        $payPeriodName = trim($_POST['pay_period_name'] ?? '');

        if (empty($empIds) || !$periodStart) {
            $error = 'Select employees and pay period.';
        } else {
            if (!$periodEnd) $periodEnd = autoEnd($periodStart, $periodType);
            if ($periodStart > $periodEnd) {
                $error = 'Invalid start/end date.';
            } else {
                $generated = 0;
                foreach ($empIds as $id) {
                    $empId = (int)$id;
                    if ($empId <= 0) continue;

                    $empStmt = $db->prepare("SELECT id, first_name, last_name, basic_salary FROM employees WHERE provider_id=:p AND id=:id AND status='active' LIMIT 1");
                    $empStmt->execute([':p' => $pid, ':id' => $empId]);
                    $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$emp) continue;

                    $attStmt = $db->prepare("SELECT
                        SUM(CASE WHEN status IN('present','late','half_day') THEN 1 ELSE 0 END) AS present_days,
                        COALESCE(SUM(late_minutes),0) AS late_mins,
                        COALESCE(SUM(overtime_min),0) AS ot_mins
                        FROM attendance
                        WHERE provider_id=:p AND employee_id=:e AND date BETWEEN :s AND :en");
                    $attStmt->execute([':p' => $pid, ':e' => $empId, ':s' => $periodStart, ':en' => $periodEnd]);
                    $att = $attStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                    $presentDays = (float)($att['present_days'] ?? 0);
                    $lateMins = (int)($att['late_mins'] ?? 0);
                    $otMins = (int)($att['ot_mins'] ?? 0);
                    $absentDays = max(0, weekdaysBetween($periodStart, $periodEnd) - $presentDays);

                    $monthlyBasic = (float)$emp['basic_salary'];
                    $dailyRate = $monthlyBasic / 26;
                    $hourRate = $dailyRate / 8;
                    $basicForPeriod = $periodType === '15days' ? $monthlyBasic / 2 : $monthlyBasic;
                    $overtimePay = round(($otMins / 60) * $hourRate * 1.25, 2);
                    $lateDed = round(($lateMins / 60) * $hourRate, 2);
                    $absentDed = round($absentDays * $dailyRate, 2);

                    $gross = round($basicForPeriod + $overtimePay, 2);
                    $sssEmp = round($gross * 0.045, 2);
                    $philEmp = round($gross * 0.02, 2);
                    $pagEmp = round(min($gross, 5000) * 0.02, 2);
                    $sssEr = round($gross * 0.095, 2);
                    $philEr = round($gross * 0.02, 2);
                    $pagEr = round(min($gross, 5000) * 0.02, 2);
                    $tax = 0;
                    if ($gross > 33332) $tax = round(($gross - 33332) * 0.20, 2);
                    elseif ($gross > 8333) $tax = round(($gross - 8333) * 0.15, 2);
                    $ded = round($sssEmp + $philEmp + $pagEmp + $tax + $lateDed + $absentDed, 2);
                    $net = round($gross - $ded, 2);

                    $periodName = $payPeriodName !== ''
                        ? $payPeriodName
                        : (($periodType === '15days' ? '15 Days' : '1 Month') . ' - ' . date('M d', strtotime($periodStart)) . ' to ' . date('M d, Y', strtotime($periodEnd)));

                    $exists = $db->prepare("SELECT id FROM payroll WHERE provider_id=:p AND employee_id=:e AND pay_period_start=:s AND pay_period_end=:en LIMIT 1");
                    $exists->execute([':p' => $pid, ':e' => $empId, ':s' => $periodStart, ':en' => $periodEnd]);
                    $row = $exists->fetch(PDO::FETCH_ASSOC);

                    if ($row) {
                        $db->prepare("UPDATE payroll SET pay_period_name=:pn,basic_salary=:bs,gross_salary=:gs,sss_employee=:sse,philhealth_employee=:phe,pagibig_employee=:pae,withholding_tax=:tax,sss_employer=:sser,philhealth_employer=:pher,pagibig_employer=:paer,deductions=:ded,net_salary=:net,status='pending' WHERE id=:id AND provider_id=:p")
                           ->execute([':pn'=>$periodName,':bs'=>$basicForPeriod,':gs'=>$gross,':sse'=>$sssEmp,':phe'=>$philEmp,':pae'=>$pagEmp,':tax'=>$tax,':sser'=>$sssEr,':pher'=>$philEr,':paer'=>$pagEr,':ded'=>$ded,':net'=>$net,':id'=>(int)$row['id'],':p'=>$pid]);
                    } else {
                        $db->prepare("INSERT INTO payroll (provider_id,employee_id,pay_period_name,pay_period_start,pay_period_end,basic_salary,gross_salary,sss_employee,philhealth_employee,pagibig_employee,withholding_tax,sss_employer,philhealth_employer,pagibig_employer,deductions,net_salary,status) VALUES (:p,:e,:pn,:ps,:pe,:bs,:gs,:sse,:phe,:pae,:tax,:sser,:pher,:paer,:ded,:net,'pending')")
                           ->execute([':p'=>$pid,':e'=>$empId,':pn'=>$periodName,':ps'=>$periodStart,':pe'=>$periodEnd,':bs'=>$basicForPeriod,':gs'=>$gross,':sse'=>$sssEmp,':phe'=>$philEmp,':pae'=>$pagEmp,':tax'=>$tax,':sser'=>$sssEr,':pher'=>$philEr,':paer'=>$pagEr,':ded'=>$ded,':net'=>$net]);
                    }
                    $generated++;
                }
                if ($generated > 0) $success = "Payroll generated for $generated employee(s).";
                else $error = 'No payroll generated.';
            }
        }
    } elseif ($_POST['action'] === 'mark_paid') {
        $id = (int)($_POST['payroll_id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE payroll SET status='paid',payment_date=NOW() WHERE provider_id=:p AND id=:id")
               ->execute([':p' => $pid, ':id' => $id]);
            $success = 'Payroll marked as paid.';
        }
    }
}

$monthFilter = $_GET['month'] ?? date('Y-m');
$records = safeAll($db, "SELECT p.*, e.first_name, e.last_name, e.position, e.department, e.date_hired, COALESCE(e.employee_code,e.employee_id,CONCAT('EMP-',e.id)) AS employee_code,
    (SELECT COUNT(*) FROM attendance a WHERE a.provider_id=p.provider_id AND a.employee_id=p.employee_id AND a.date BETWEEN p.pay_period_start AND p.pay_period_end AND a.status IN ('present','late','half_day')) AS days_worked
    FROM payroll p JOIN employees e ON p.employee_id=e.id
    WHERE p.provider_id=:p AND DATE_FORMAT(p.pay_period_start,'%Y-%m')=:m
    ORDER BY p.created_at DESC LIMIT 100", [':p' => $pid, ':m' => $monthFilter]);
$employees = safeAll($db, "SELECT id, first_name, last_name, basic_salary, COALESCE(employee_code,employee_id,CONCAT('EMP-',id)) AS employee_code FROM employees WHERE provider_id=:p AND status='active' ORDER BY first_name,last_name", [':p' => $pid]);
$totalGross = array_sum(array_map(fn($r) => (float)$r['gross_salary'], $records));
$totalDed = array_sum(array_map(fn($r) => (float)$r['deductions'], $records));
$totalNet = array_sum(array_map(fn($r) => (float)$r['net_salary'], $records));
$active_menu = 'payroll';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--p:#9b59b6;--d:#1a2744;--bg:#f5f7fa;--b:#e2e8f0;--m:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}.portal-main{flex:1}.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;gap:12px;flex-wrap:wrap}
.page-header h1{font-size:22px;color:var(--d);display:flex;gap:8px;align-items:center}.page-header h1 i{color:var(--p)}
.page-header p{font-size:12px;color:var(--m);margin-top:4px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none}
.btn-primary{background:var(--p);color:#fff}.btn-outline{background:#fff;border:1px solid var(--b);color:#334155}.btn-success{background:#16a34a;color:#fff}.btn-sm{padding:5px 9px;font-size:11px}
.alert{padding:12px 15px;border-radius:8px;font-size:13px;margin-bottom:14px}.alert-success{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0}.alert-error{background:#fff5f5;color:#7f1d1d;border:1px solid #fecaca}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}.stat{background:#fff;border:1px solid var(--b);border-radius:10px;padding:14px;display:flex;gap:10px;align-items:center}
.stat i{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff}.si1{background:#8b5cf6}.si2{background:#ef4444}.si3{background:#22c55e}.stat h3{font-size:17px}.stat p{font-size:11px;color:var(--m)}
.filters{display:flex;gap:10px;margin-bottom:16px}.filters input{padding:8px 11px;border:1px solid var(--b);border-radius:8px}
.card{background:#fff;border:1px solid var(--b);border-radius:12px;overflow:hidden}.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}thead th{background:#f8fafc;padding:10px 12px;font-size:10px;text-transform:uppercase;color:var(--m);text-align:left;border-bottom:2px solid var(--b)}
tbody td{padding:10px 12px;font-size:12px;border-bottom:1px solid var(--b);vertical-align:middle}tbody tr:last-child td{border-bottom:none}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}.b-paid{background:#dcfce7;color:#166534}.b-pending{background:#e2e8f0;color:#475569}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:18px}.modal.open{display:flex}
.box{background:#fff;border-radius:14px;width:100%;max-width:760px;max-height:92vh;overflow:auto}.box-head{padding:16px 20px;border-bottom:1px solid var(--b);display:flex;justify-content:space-between;align-items:center}
.box-body{padding:18px 20px}.box-foot{padding:14px 20px;border-top:1px solid var(--b);display:flex;justify-content:flex-end;gap:8px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.fg{margin-bottom:10px}.fg label{display:block;font-size:11px;font-weight:700;color:var(--m);margin-bottom:4px}
.fg input,.fg select{width:100%;padding:9px 11px;border:1px solid var(--b);border-radius:8px;font-size:12px}
.elist{border:1px solid var(--b);border-radius:8px;padding:8px;max-height:220px;overflow:auto;background:#fafafa}.eitem{display:flex;gap:8px;align-items:center;font-size:12px;padding:6px;border-radius:6px}.eitem:hover{background:#f1f5f9}
.muted{color:var(--m);font-size:11px}.payslip h4{font-size:16px;color:var(--d)}.payslip .row{display:grid;grid-template-columns:1fr 1fr;gap:10px;background:#f8fafc;border:1px solid var(--b);border-radius:10px;padding:12px;margin:12px 0}
.payslip table td,.payslip table th{padding:8px 10px;font-size:12px}.payslip table th{background:#f1f5f9}.net{background:#22c55e;color:#fff;border-radius:9px;padding:12px;margin-top:12px;display:flex;justify-content:space-between}
@media(max-width:900px){.stats,.grid,.payslip .row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">
<div class="page-header">
    <div><h1><i class="fas fa-money-check-alt"></i> Payroll and Payslip PDF</h1><p><?= date('F Y', strtotime($monthFilter . '-01')) ?> - <?= count($records) ?> record(s)</p></div>
    <button class="btn btn-primary" onclick="openModal('genModal')"><i class="fas fa-cogs"></i> Generate Payroll</button>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="stats">
    <div class="stat"><i class="fas fa-money-bill-wave si1"></i><div><h3>PHP <?= number_format($totalGross,2) ?></h3><p>Total Gross</p></div></div>
    <div class="stat"><i class="fas fa-minus-circle si2"></i><div><h3>PHP <?= number_format($totalDed,2) ?></h3><p>Total Deductions</p></div></div>
    <div class="stat"><i class="fas fa-hand-holding-usd si3"></i><div><h3>PHP <?= number_format($totalNet,2) ?></h3><p>Total Net</p></div></div>
</div>
<form method="GET" class="filters">
    <input type="month" name="month" value="<?= htmlspecialchars($monthFilter) ?>">
    <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
    <a class="btn btn-outline" href="providers.php">Reset</a>
</form>
<div class="card"><div class="table-wrap"><table>
<thead><tr><th>Employee</th><th>Period</th><th>Days</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($records)): ?>
<tr><td colspan="8" style="text-align:center;padding:40px;color:#64748b">No payroll records for this month.</td></tr>
<?php endif; ?>
<?php foreach ($records as $r): ?>
<tr>
    <td><strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></strong><br><span class="muted"><?= htmlspecialchars($r['employee_code']) ?> | <?= htmlspecialchars($r['position'] ?? '-') ?></span></td>
    <td><?= date('M d, Y', strtotime($r['pay_period_start'])) ?> to <?= date('M d, Y', strtotime($r['pay_period_end'])) ?><br><span class="muted"><?= htmlspecialchars($r['pay_period_name'] ?? '') ?></span></td>
    <td><?= (int)$r['days_worked'] ?></td>
    <td>PHP <?= number_format((float)$r['gross_salary'], 2) ?></td>
    <td style="color:#b91c1c">PHP <?= number_format((float)$r['deductions'], 2) ?></td>
    <td style="font-weight:700;color:#15803d">PHP <?= number_format((float)$r['net_salary'], 2) ?></td>
    <td><span class="badge <?= $r['status'] === 'paid' ? 'b-paid' : 'b-pending' ?>"><?= ucfirst($r['status']) ?></span></td>
    <td style="display:flex;gap:6px;flex-wrap:wrap">
        <button type="button" class="btn btn-sm btn-outline" data-pay='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' onclick="showPayslip(this)"><i class="fas fa-file-invoice"></i> Payslip</button>
        <button type="button" class="btn btn-sm btn-primary" data-pay='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' onclick="downloadPdfFromBtn(this)"><i class="fas fa-file-pdf"></i> PDF</button>
        <?php if ($r['status'] !== 'paid'): ?>
        <form method="POST" onsubmit="return confirm('Mark as paid?')">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="payroll_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Paid</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div></div>
</div></div></div>

<div class="modal" id="genModal"><div class="box">
    <div class="box-head"><h3><i class="fas fa-cogs"></i> Generate Payroll</h3><button class="btn btn-sm btn-outline" type="button" onclick="closeModal('genModal')">Close</button></div>
    <form method="POST">
        <input type="hidden" name="action" value="generate">
        <div class="box-body">
            <div class="grid">
                <div class="fg">
                    <label>Payroll Type</label>
                    <select name="period_type" id="periodType" onchange="setEndDate()">
                        <option value="15days">15 Days</option>
                        <option value="monthly">1 Month</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Pay Period Name (Optional)</label>
                    <input type="text" name="pay_period_name" placeholder="Example: March 2026 First Half">
                </div>
            </div>
            <div class="grid">
                <div class="fg">
                    <label>Start Date</label>
                    <input type="date" id="periodStart" name="period_start" required onchange="setEndDate()">
                </div>
                <div class="fg">
                    <label>End Date</label>
                    <input type="date" id="periodEnd" name="period_end" required>
                </div>
            </div>
            <div class="fg">
                <label>Select Employees</label>
                <div style="margin-bottom:6px"><button class="btn btn-sm btn-outline" type="button" onclick="toggleEmployees()">Select All / Unselect All</button></div>
                <div class="elist" id="elist">
                    <?php foreach ($employees as $e): ?>
                    <label class="eitem">
                        <input type="checkbox" name="emp_ids[]" value="<?= (int)$e['id'] ?>">
                        <span><?= htmlspecialchars($e['employee_code']) ?> - <?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?> <span class="muted">| PHP <?= number_format((float)$e['basic_salary'],2) ?>/month</span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="box-foot">
            <button class="btn btn-outline" type="button" onclick="closeModal('genModal')">Cancel</button>
            <button class="btn btn-primary" type="submit"><i class="fas fa-calculator"></i> Generate</button>
        </div>
    </form>
</div></div>

<div class="modal" id="payslipModal"><div class="box">
    <div class="box-head">
        <h3><i class="fas fa-file-invoice"></i> Payslip Preview</h3>
        <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-primary" type="button" onclick="downloadCurrentPdf()"><i class="fas fa-file-pdf"></i> Download PDF</button>
            <button class="btn btn-sm btn-outline" type="button" onclick="closeModal('payslipModal')">Close</button>
        </div>
    </div>
    <div class="box-body payslip" id="payslipContent"></div>
</div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
let activePayslip = null;

function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal').forEach(function(m){ m.addEventListener('click', function(e){ if(e.target===m) m.classList.remove('open'); }); });

function toISO(d){
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    return y + '-' + m + '-' + day;
}

function setEndDate(){
    const start = document.getElementById('periodStart').value;
    const type = document.getElementById('periodType').value;
    if(!start) return;
    const d = new Date(start + 'T00:00:00');
    if(type === '15days'){
        d.setDate(d.getDate() + 14);
    } else {
        d.setMonth(d.getMonth() + 1);
        d.setDate(d.getDate() - 1);
    }
    document.getElementById('periodEnd').value = toISO(d);
}

function toggleEmployees(){
    const list = document.querySelectorAll('#elist input[type=checkbox]');
    const allChecked = Array.from(list).every(function(i){ return i.checked; });
    list.forEach(function(i){ i.checked = !allChecked; });
}

function parsePayload(btn){
    try { return JSON.parse(btn.getAttribute('data-pay')); }
    catch(e){ return null; }
}

function money(v){
    return new Intl.NumberFormat('en-PH', { style:'currency', currency:'PHP' }).format(parseFloat(v || 0));
}

function fdate(v){
    if(!v) return '-';
    return new Date(v + 'T00:00:00').toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
}

function showPayslip(btn){
    const p = parsePayload(btn);
    if(!p) return;
    activePayslip = p;
    document.getElementById('payslipContent').innerHTML = `
        <h4>PAYSLIP</h4>
        <div class="muted">${fdate(p.pay_period_start)} to ${fdate(p.pay_period_end)} | ${p.pay_period_name || ''}</div>
        <div class="row">
            <div><div class="muted">Employee</div><strong>${p.first_name} ${p.last_name}</strong></div>
            <div><div class="muted">Employee Code</div><strong>${p.employee_code || '-'}</strong></div>
            <div><div class="muted">Position</div><strong>${p.position || '-'}</strong></div>
            <div><div class="muted">Department</div><strong>${p.department || '-'}</strong></div>
            <div><div class="muted">Date Hired</div><strong>${fdate(p.date_hired)}</strong></div>
            <div><div class="muted">Days Worked</div><strong>${p.days_worked || 0} day(s)</strong></div>
        </div>
        <table style="width:100%;border-collapse:collapse">
            <tr><th style="text-align:left">Details</th><th style="text-align:right">Amount</th></tr>
            <tr><td>Basic Salary for Period</td><td style="text-align:right">${money(p.basic_salary)}</td></tr>
            <tr><td>Gross Salary</td><td style="text-align:right">${money(p.gross_salary)}</td></tr>
            <tr><td>SSS Employee</td><td style="text-align:right">- ${money(p.sss_employee)}</td></tr>
            <tr><td>PhilHealth Employee</td><td style="text-align:right">- ${money(p.philhealth_employee)}</td></tr>
            <tr><td>Pag-IBIG Employee</td><td style="text-align:right">- ${money(p.pagibig_employee)}</td></tr>
            <tr><td>Withholding Tax</td><td style="text-align:right">- ${money(p.withholding_tax)}</td></tr>
            <tr><td>Total Deductions</td><td style="text-align:right">- ${money(p.deductions)}</td></tr>
        </table>
        <div class="net"><span>Total Salary (Net Pay)</span><strong>${money(p.net_salary)}</strong></div>
    `;
    openModal('payslipModal');
}

function makePdf(p){
    if(!(window.jspdf && window.jspdf.jsPDF)){ alert('PDF library not loaded.'); return; }
    const doc = new window.jspdf.jsPDF({ unit:'mm', format:'a4' });
    let y = 15;
    const line = function(a,b){ doc.setFontSize(10); doc.text(a,14,y); doc.text(String(b),80,y); y += 6; };

    doc.setFontSize(16); doc.text('Pestify Payslip', 14, y); y += 8;
    doc.setFontSize(11); doc.text((p.pay_period_name || '') + ' | ' + fdate(p.pay_period_start) + ' to ' + fdate(p.pay_period_end), 14, y); y += 10;

    line('Employee', (p.first_name || '') + ' ' + (p.last_name || ''));
    line('Employee Code', p.employee_code || '-');
    line('Position', p.position || '-');
    line('Department', p.department || '-');
    line('Date Hired', fdate(p.date_hired));
    line('Days Worked', String(p.days_worked || 0));
    y += 3;
    line('Basic Salary for Period', money(p.basic_salary));
    line('Gross Salary', money(p.gross_salary));
    line('SSS Employee', '- ' + money(p.sss_employee));
    line('PhilHealth Employee', '- ' + money(p.philhealth_employee));
    line('Pag-IBIG Employee', '- ' + money(p.pagibig_employee));
    line('Withholding Tax', '- ' + money(p.withholding_tax));
    line('Total Deductions', '- ' + money(p.deductions));
    y += 4;
    doc.setFontSize(13); doc.text('Total Salary (Net Pay): ' + money(p.net_salary), 14, y);

    const fn = 'Payslip_' + (p.employee_code || 'EMP') + '_' + (p.pay_period_start || 'period') + '.pdf';
    doc.save(fn);
}

function downloadPdfFromBtn(btn){
    const p = parsePayload(btn);
    if(!p) return;
    makePdf(p);
}

function downloadCurrentPdf(){
    if(!activePayslip) return;
    makePdf(activePayslip);
}
</script>
</body>
</html>
