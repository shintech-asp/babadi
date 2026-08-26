<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

if (!($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'all')) {
    header('Location: dashboard.php'); exit;
}

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Payroll'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate') {
        $eid = (int)($_POST['employee_id']??0);
        $start = $_POST['pay_period_start']??'';
        $end = $_POST['pay_period_end']??'';
        $period_name = trim($_POST['pay_period_name']??'');
        $emp = safeAll($db,"SELECT * FROM employees WHERE id=:id AND provider_id=:p",[':id'=>$eid,':p'=>$pid]);
        if (empty($emp)) { $error="Employee not found."; }
        else {
            $e = $emp[0];
            $basic = (float)$e['basic_salary'];
            $freq = $e['pay_frequency']??'semi_monthly';
            $gross = $freq === 'semi_monthly' ? $basic / 2 : $basic;
            $sss_e = round($gross * 0.045, 2);
            $phil_e = round($gross * 0.02, 2);
            $pag_e = round(min($gross, 5000) * 0.02, 2);
            $sss_er = round($gross * 0.095, 2);
            $phil_er = round($gross * 0.02, 2);
            $pag_er = round(min($gross, 5000) * 0.02, 2);
            $tax = 0;
            if ($gross > 33332) $tax = ($gross - 33332) * 0.20;
            elseif ($gross > 8333) $tax = ($gross - 8333) * 0.15;
            $deductions = $sss_e + $phil_e + $pag_e + $tax;
            $net = $gross - $deductions;
            try {
                $db->prepare("INSERT INTO payroll (provider_id,employee_id,pay_period_name,pay_period_start,pay_period_end,basic_salary,gross_salary,sss_employee,philhealth_employee,pagibig_employee,withholding_tax,sss_employer,philhealth_employer,pagibig_employer,deductions,net_salary,status) VALUES (:pid,:eid,:pn,:ps,:pe,:bs,:gs,:sse,:phe,:pae,:wt,:sser,:pher,:paer,:ded,:net,'pending')")
                   ->execute([':pid'=>$pid,':eid'=>$eid,':pn'=>$period_name,':ps'=>$start,':pe'=>$end,':bs'=>$basic,':gs'=>$gross,':sse'=>$sss_e,':phe'=>$phil_e,':pae'=>$pag_e,':wt'=>$tax,':sser'=>$sss_er,':pher'=>$phil_er,':paer'=>$pag_er,':ded'=>$deductions,':net'=>$net]);
                $success = "Payroll generated for {$e['first_name']} {$e['last_name']}. Net Pay: ₱".number_format($net,2);
            } catch(Exception $ex){ $error = $ex->getMessage(); }
        }
    } elseif ($_POST['action'] === 'mark_paid') {
        $rid = (int)($_POST['payroll_id']??0);
        $db->prepare("UPDATE payroll SET status='paid',payment_date=NOW() WHERE id=:id AND provider_id=:p")->execute([':id'=>$rid,':p'=>$pid]);
        $success = "Payroll marked as paid.";
    }
}

$month_filter = $_GET['month'] ?? date('Y-m');
$records = safeAll($db,"SELECT p.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_code, e.department, e.position FROM payroll p JOIN employees e ON p.employee_id=e.id WHERE p.provider_id=:p AND DATE_FORMAT(p.pay_period_start,'%Y-%m')=:m ORDER BY p.created_at DESC",[':p'=>$pid,':m'=>$month_filter]);
$employees = safeAll($db,"SELECT id,first_name,last_name,employee_code,basic_salary,pay_frequency FROM employees WHERE provider_id=:p AND status='active' ORDER BY first_name",[':p'=>$pid]);

$total_gross = array_sum(array_column($records,'gross_salary'));
$total_net = array_sum(array_column($records,'net_salary'));
$total_ded = array_sum(array_column($records,'deductions'));

$active_menu='payroll';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll - <?= htmlspecialchars($portal_company) ?></title>
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
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;padding:18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:44px;height:44px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.stat-info h3{font-size:18px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-success{background:#27ae60;color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:10px;margin-bottom:20px}
.filters input{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-green{background:#c6f6d5;color:#276749}
.badge-orange{background:#feebc8;color:#c05621}
.badge-gray{background:#e2e8f0;color:#4a5568}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.preview-box{background:#f8fafc;border-radius:10px;padding:14px;margin-top:10px;border:1px solid var(--border)}
.preview-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px solid var(--border)}
.preview-row:last-child{border-bottom:none;font-weight:700;font-size:14px}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <div>
        <h1><i class="fas fa-money-bill-wave"></i> Payroll</h1>
        <p><?= date('F Y', strtotime($month_filter.'-01')) ?> — <?= count($records) ?> record(s)</p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-plus"></i> Generate Payroll</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-money-bill"></i></div><div class="stat-info"><h3>₱<?= number_format($total_gross,0) ?></h3><p>Gross Payroll</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-minus-circle"></i></div><div class="stat-info"><h3>₱<?= number_format($total_ded,0) ?></h3><p>Total Deductions</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-hand-holding-usd"></i></div><div class="stat-info"><h3>₱<?= number_format($total_net,0) ?></h3><p>Net Payroll</p></div></div>
</div>

<form method="GET" class="filters">
    <input type="month" name="month" value="<?= $month_filter ?>" onchange="this.form.submit()">
</form>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>Employee</th><th>Period</th><th>Gross</th><th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>Tax</th><th>Net Pay</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if(empty($records)): ?>
    <tr><td colspan="10"><div class="empty-state"><i class="fas fa-money-bill-wave" style="font-size:36px;opacity:.2;display:block;margin-bottom:10px"></i><p>No payroll records for this month.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach($records as $r): ?>
    <tr>
        <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['position']??'—') ?></small></td>
        <td style="font-size:11px"><?= date('M d', strtotime($r['pay_period_start'])) ?> – <?= date('M d', strtotime($r['pay_period_end'])) ?><br><small style="color:var(--muted)"><?= htmlspecialchars($r['pay_period_name']??'') ?></small></td>
        <td>₱<?= number_format($r['gross_salary'],2) ?></td>
        <td style="color:#c53030">₱<?= number_format($r['sss_employee'],2) ?></td>
        <td style="color:#c53030">₱<?= number_format($r['philhealth_employee'],2) ?></td>
        <td style="color:#c53030">₱<?= number_format($r['pagibig_employee'],2) ?></td>
        <td style="color:#c53030">₱<?= number_format($r['withholding_tax'],2) ?></td>
        <td style="font-weight:700;color:#276749">₱<?= number_format($r['net_salary'],2) ?></td>
        <td><span class="badge <?= $r['status']==='paid'?'badge-green':($r['status']==='processed'?'badge-orange':'badge-gray') ?>"><?= ucfirst($r['status']) ?></span></td>
        <td>
        <?php if($r['status']!=='paid'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Mark as paid?')">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="payroll_id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Paid</button>
        </form>
        <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Generate Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-calculator" style="color:var(--primary)"></i> Generate Payroll</h3>
    <form method="POST">
    <input type="hidden" name="action" value="generate">
    <div class="form-group">
        <label>Employee *</label>
        <select name="employee_id" required onchange="updatePreview(this)">
            <option value="">Select Employee</option>
            <?php foreach($employees as $e): ?>
            <option value="<?= $e['id'] ?>" data-salary="<?= $e['basic_salary'] ?>" data-freq="<?= $e['pay_frequency'] ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?> — ₱<?= number_format($e['basic_salary'],0) ?>/mo</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label>Pay Period Name</label><input type="text" name="pay_period_name" placeholder="e.g. March 2026 1st Half"></div>
    <div class="form-group"><label>Period Start *</label><input type="date" name="pay_period_start" required></div>
    <div class="form-group"><label>Period End *</label><input type="date" name="pay_period_end" required></div>
    <div class="preview-box" id="previewBox" style="display:none">
        <div style="font-size:11px;font-weight:700;color:var(--muted);margin-bottom:8px;text-transform:uppercase">Pay Preview</div>
        <div class="preview-row"><span>Gross Pay</span><span id="pGross">—</span></div>
        <div class="preview-row" style="color:#c53030"><span>SSS (4.5%)</span><span id="pSSS">—</span></div>
        <div class="preview-row" style="color:#c53030"><span>PhilHealth (2%)</span><span id="pPhil">—</span></div>
        <div class="preview-row" style="color:#c53030"><span>Pag-IBIG (2%)</span><span id="pPag">—</span></div>
        <div class="preview-row" style="color:#c53030"><span>Withholding Tax</span><span id="pTax">—</span></div>
        <div class="preview-row" style="color:#276749"><span>Net Pay</span><span id="pNet">—</span></div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-cogs"></i> Generate</button>
    </div>
    </form>
</div>
</div>

<script>
function fmt(n){return'₱'+n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function updatePreview(sel){
    const opt=sel.options[sel.selectedIndex];
    const salary=parseFloat(opt.dataset.salary||0);
    const freq=opt.dataset.freq||'semi_monthly';
    if(!salary){document.getElementById('previewBox').style.display='none';return;}
    const gross=freq==='semi_monthly'?salary/2:salary;
    const sss=+(gross*0.045).toFixed(2);
    const phil=+(gross*0.02).toFixed(2);
    const pag=+(Math.min(gross,5000)*0.02).toFixed(2);
    let tax=0;
    if(gross>33332)tax=(gross-33332)*0.20;
    else if(gross>8333)tax=(gross-8333)*0.15;
    tax=+tax.toFixed(2);
    const net=gross-sss-phil-pag-tax;
    document.getElementById('pGross').textContent=fmt(gross);
    document.getElementById('pSSS').textContent='-'+fmt(sss);
    document.getElementById('pPhil').textContent='-'+fmt(phil);
    document.getElementById('pPag').textContent='-'+fmt(pag);
    document.getElementById('pTax').textContent='-'+fmt(tax);
    document.getElementById('pNet').textContent=fmt(net);
    document.getElementById('previewBox').style.display='block';
}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));
</script>
</body></html>