<?php
// provider-portal/my-payslips.php — employee self-service, read-only payslip view
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$self_emp_id = (int)($_SESSION['portal_employee_id'] ?? 0);
if (!$self_emp_id) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeRow($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){return null;}}

$employee = safeRow($db, "SELECT * FROM employees WHERE id=:id AND provider_id=:p", [':id'=>$self_emp_id, ':p'=>$pid]);
$payslips = safeAll($db,
    "SELECT * FROM payroll WHERE employee_id=:e AND provider_id=:p ORDER BY pay_period_start DESC",
    [':e'=>$self_emp_id, ':p'=>$pid]
);

$active_menu = 'my_payslips';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Salary · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{margin-bottom:22px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;margin-bottom:18px}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;font-weight:700;color:var(--dark)}
.basic-info{padding:16px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
.basic-info div span{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.basic-info div strong{font-size:15px;color:var(--dark)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f8fafc;text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);border-bottom:1px solid var(--border)}
td{padding:12px 14px;border-bottom:1px solid #f1f5f9}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.pill-pending{background:#fef9c3;color:#854d0e}
.pill-processed{background:#dbeafe;color:#1e40af}
.pill-paid{background:#d1fae5;color:#065f46}
.net{font-weight:700;color:var(--primary)}
.empty-state{padding:30px;text-align:center;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

    <div class="page-header">
        <h1><i class="fas fa-money-check-alt"></i> My Salary</h1>
        <p>Your processed payroll records. Read-only — contact HR/Finance for corrections.</p>
    </div>

    <?php if ($employee): ?>
    <div class="card">
        <div class="card-header">Basic Info</div>
        <div class="basic-info">
            <div><span>Employee ID</span><strong><?= htmlspecialchars($employee['employee_id'] ?? '—') ?></strong></div>
            <div><span>Position</span><strong><?= htmlspecialchars($employee['position'] ?? '—') ?></strong></div>
            <div><span>Basic Salary</span><strong>₱<?= number_format((float)($employee['basic_salary'] ?? 0), 2) ?></strong></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">Payslip History</div>
        <?php if (empty($payslips)): ?>
            <div class="empty-state">No payroll records yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Pay Period</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><th>Status</th><th>Payment Date</th></tr></thead>
            <tbody>
            <?php foreach ($payslips as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['pay_period_name'] ?: (date('M j', strtotime($p['pay_period_start'])) . ' – ' . date('M j, Y', strtotime($p['pay_period_end'])))) ?></td>
                    <td>₱<?= number_format((float)$p['gross_salary'], 2) ?></td>
                    <td>₱<?= number_format((float)$p['deductions'], 2) ?></td>
                    <td class="net">₱<?= number_format((float)$p['net_salary'], 2) ?></td>
                    <td><span class="pill pill-<?= htmlspecialchars($p['status']) ?>"><?= ucfirst(htmlspecialchars($p['status'])) ?></span></td>
                    <td><?= $p['payment_date'] ? htmlspecialchars(date('M j, Y', strtotime($p['payment_date']))) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div></div>
</div>
</body></html>
