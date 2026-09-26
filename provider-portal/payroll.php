<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once 'includes/portal-settings.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$can_hr      = ($portal_role === 'owner' || $portal_dept === 'hr'      || $portal_dept === 'all');
$can_finance = ($portal_role === 'owner' || $portal_dept === 'finance' || $portal_dept === 'all');
if (!$can_hr && !$can_finance) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Payroll'); exit; }

require_once __DIR__ . '/../includes/hr_schedule_helper.php';
require_once __DIR__ . '/../includes/payroll_helper.php';

// ── Configured rates (Settings > HR / Finance) — see provider-portal/settings.php.
// Also used purely for display below; the actual generation math lives in
// includes/payroll_helper.php's generatePayrollForPeriod(), which re-reads
// these same settings itself. SSS/PhilHealth/Pag-IBIG are NOT here anymore —
// they're the fixed government schedule via computeStatutoryDeductions().
$ot_multiplier  = (float)getSetting($db,$pid,'overtime_rate','1.25');
$working_days_per_month = max(1, (int)getSetting($db,$pid,'working_days_per_month','22'));
$cutoff1        = max(1, min(28, (int)getSetting($db,$pid,'payroll_cutoff_1','15')));
$cutoff2        = max($cutoff1 + 1, (int)getSetting($db,$pid,'payroll_cutoff_2','30'));

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeRow($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){return null;}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── HR: bulk-generate payroll for a period, computed from real attendance ──
    if ($_POST['action'] === 'generate' && $can_hr) {
        $month = $_POST['month'] ?? date('Y-m');
        $half  = ($_POST['half'] ?? '1') === '2' ? '2' : '1';
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $error = 'Invalid month.';
        } else {
            $result = generatePayrollForPeriod($db, $pid, $month, $half);
            $success = "Generated payroll for {$result['generated']} employee(s) — {$result['period_name']}."
                . ($result['skipped'] > 0 ? " ({$result['skipped']} already had payroll for this period.)" : '');
        }

    // ── Finance: approve a pending payroll run (pending -> processed) ──
    } elseif ($_POST['action'] === 'approve' && $can_finance) {
        $rid = (int)($_POST['payroll_id'] ?? 0);
        $db->prepare("UPDATE payroll SET status='processed' WHERE id=:id AND provider_id=:p AND status='pending'")
           ->execute([':id'=>$rid, ':p'=>$pid]);
        $success = "Payroll approved and ready to pay.";

    // ── Finance: mark an approved payroll as paid ──
    } elseif ($_POST['action'] === 'mark_paid' && $can_finance) {
        $rid = (int)($_POST['payroll_id'] ?? 0);
        $db->prepare("UPDATE payroll SET status='paid', payment_date=NOW() WHERE id=:id AND provider_id=:p AND status='processed'")
           ->execute([':id'=>$rid, ':p'=>$pid]);
        $success = "Payroll marked as paid.";
    }
}

$month_filter = $_GET['month'] ?? date('Y-m');
$records = safeAll($db,
    "SELECT p.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code, e.department, e.position
     FROM payroll p JOIN employees e ON p.employee_id=e.id
     WHERE p.provider_id=:p AND DATE_FORMAT(p.pay_period_start,'%Y-%m')=:m
     ORDER BY p.created_at DESC",
    [':p'=>$pid, ':m'=>$month_filter]
);

$total_gross = array_sum(array_column($records,'gross_salary'));
$total_net = array_sum(array_column($records,'net_salary'));
$total_ded = array_sum(array_column($records,'deductions')) + array_sum(array_column($records,'absent_deduction')) + array_sum(array_column($records,'late_deduction'));

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
.btn-info{background:#2563eb;color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:10px;margin-bottom:20px}
.filters input{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.rate-note{background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--muted);margin-bottom:16px}
.rate-note a{color:var(--primary);font-weight:600;text-decoration:none}
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
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:8px;display:flex;align-items:center;gap:8px}
.modal p.hint{font-size:12px;color:var(--muted);margin-bottom:18px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
.small-cell{font-size:11px;color:var(--muted)}
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
    <?php if ($can_hr): ?>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-calculator"></i> Generate Payroll</button>
    <?php endif; ?>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="rate-note">
    <i class="fas fa-circle-info"></i> Computed from actual attendance using: working days/month = <strong><?= $working_days_per_month ?></strong>,
    overtime = <strong><?= $ot_multiplier ?>×</strong>. SSS/PhilHealth/Pag-IBIG use the fixed government schedule (not editable per company).
    <?php if ($can_hr): ?><a href="settings.php?tab=finance">View rates in Settings →</a><?php endif; ?>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-money-bill"></i></div><div class="stat-info"><h3>₱<?= number_format($total_gross,0) ?></h3><p>Gross Payroll</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-minus-circle"></i></div><div class="stat-info"><h3>₱<?= number_format($total_ded,0) ?></h3><p>Total Deductions</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-hand-holding-usd"></i></div><div class="stat-info"><h3>₱<?= number_format($total_net,0) ?></h3><p>Net Payroll</p></div></div>
</div>

<form method="GET" class="filters">
    <input type="month" name="month" value="<?= htmlspecialchars($month_filter) ?>" onchange="this.form.submit()">
</form>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>Employee</th><th>Period</th><th>Days</th><th>Gross</th><th>Absent</th><th>Late</th><th>OT Pay</th><th>Statutory</th><th>Net Pay</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if(empty($records)): ?>
    <tr><td colspan="11"><div class="empty-state"><i class="fas fa-money-bill-wave" style="font-size:36px;opacity:.2;display:block;margin-bottom:10px"></i><p>No payroll records for this month.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach($records as $r): ?>
    <tr>
        <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong><br><small class="small-cell"><?= htmlspecialchars($r['position']??'—') ?></small></td>
        <td class="small-cell"><?= date('M d', strtotime($r['pay_period_start'])) ?> – <?= date('M d', strtotime($r['pay_period_end'])) ?></td>
        <td class="small-cell">Worked <?= (int)$r['days_worked'] ?> · Absent <?= (int)$r['days_absent'] ?></td>
        <td>₱<?= number_format($r['gross_salary'],2) ?></td>
        <td style="color:#c53030">-₱<?= number_format($r['absent_deduction'],2) ?></td>
        <td style="color:#c53030">-₱<?= number_format($r['late_deduction'],2) ?></td>
        <td style="color:#276749">+₱<?= number_format($r['overtime_pay'],2) ?></td>
        <td style="color:#c53030">-₱<?= number_format($r['deductions'],2) ?></td>
        <td style="font-weight:700;color:#276749">₱<?= number_format($r['net_salary'],2) ?></td>
        <td><span class="badge <?= $r['status']==='paid'?'badge-green':($r['status']==='processed'?'badge-orange':'badge-gray') ?>"><?= ucfirst($r['status']) ?></span></td>
        <td>
        <?php if($r['status']==='pending' && $can_finance): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Approve this payroll for payment?')">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="payroll_id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn btn-info btn-sm"><i class="fas fa-check"></i> Approve</button>
        </form>
        <?php elseif($r['status']==='processed' && $can_finance): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Mark as paid?')">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="payroll_id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Mark Paid</button>
        </form>
        <?php elseif($r['status']==='pending'): ?>
            <span class="small-cell">Awaiting Finance approval</span>
        <?php else: ?><span class="small-cell">—</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Generate Modal -->
<?php if ($can_hr): ?>
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-calculator" style="color:var(--primary)"></i> Generate Payroll</h3>
    <p class="hint">Computes pay for every active employee in the chosen period from their actual attendance. Sent to Finance for approval before payment.</p>
    <form method="POST">
    <input type="hidden" name="action" value="generate">
    <div class="form-group">
        <label>Month</label>
        <input type="month" name="month" value="<?= date('Y-m') ?>" required>
    </div>
    <div class="form-group">
        <label>Pay Period</label>
        <select name="half" required>
            <option value="1">1st Half (1 – <?= $cutoff1 ?>)</option>
            <option value="2">2nd Half (<?= $cutoff1+1 ?> – <?= $cutoff2 ?>)</option>
        </select>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-cogs"></i> Generate for All Active Employees</button>
    </div>
    </form>
</div>
</div>
<?php endif; ?>

</div></div></div>
<script>
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));
</script>
</body></html>
