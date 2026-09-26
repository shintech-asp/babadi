<?php
// admin/hr/payroll.php
$require_dept = 'hr';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();

$success = $error = '';

// ── Generate Payroll ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'generate') {
    $emp_ids     = $_POST['emp_ids'] ?? [];
    $period_type = $_POST['period_type'] ?? 'monthly';
    $period_start= $_POST['period_start'] ?? '';
    $period_end  = $_POST['period_end']   ?? '';

    if (empty($emp_ids) || !$period_start || !$period_end) {
        $error = 'Select at least one employee and set the pay period.';
    } else {
        $generated = 0;
        foreach ($emp_ids as $emp_id) {
            $emp_id = (int)$emp_id;
            $emp = $db->prepare("SELECT * FROM employees WHERE id=:id"); $emp->execute([':id'=>$emp_id]);
            $emp_row = $emp->fetch(PDO::FETCH_ASSOC);
            if (!$emp_row) continue;

            // Count attendance
            $att = $db->prepare("SELECT COUNT(*) as present, SUM(late_minutes) as late_mins, SUM(overtime_minutes) as ot_mins FROM attendance WHERE employee_id=:e AND date BETWEEN :s AND :end AND status IN('present','late','overtime')");
            $att->execute([':e'=>$emp_id,':s'=>$period_start,':end'=>$period_end]);
            $att_row = $att->fetch(PDO::FETCH_ASSOC);

            // Work days in period (M-F)
            $work_days = 0; $d = new DateTime($period_start); $end_d = new DateTime($period_end);
            while ($d <= $end_d) { $dow = (int)$d->format('N'); if ($dow <= 5) $work_days++; $d->modify('+1 day'); }

            $days_worked      = (float)($att_row['present'] ?? 0);
            $absent_days      = max(0, $work_days - $days_worked);
            $late_mins        = (int)($att_row['late_mins'] ?? 0);
            $ot_mins          = (int)($att_row['ot_mins'] ?? 0);

            // Salary computation
            $basic  = (float)$emp_row['basic_salary'];
            $daily  = $basic / 26;
            $hourly = $daily / 8;
            $basic_period  = $period_type === '15days' ? $basic/2 : $basic;
            $overtime_pay  = round($ot_mins / 60 * $hourly * 1.25, 2);
            $late_deduct   = round($late_mins / 60 * $hourly, 2);
            $absent_deduct = round($absent_days * $daily, 2);
            $gross         = $basic_period + $overtime_pay;

            // Government deductions (simplified BIR brackets)
            $sss        = round(min($basic * 0.045, 900), 2);
            $philhealth = round($basic * 0.025, 2);
            $pagibig    = 100.00;
            $taxable    = max(0, $gross - $sss - $philhealth - $pagibig);
            $tax        = $taxable > 20833 ? round(($taxable - 20833) * 0.20, 2) : 0;
            $other_ded  = $late_deduct + $absent_deduct;
            $total_ded  = $sss + $philhealth + $pagibig + $tax + $other_ded;
            $net        = round($gross - $total_ded, 2);

            // Check if already exists
            $existing = $db->prepare("SELECT id FROM payroll WHERE employee_id=:e AND period_start=:s AND period_end=:end");
            $existing->execute([':e'=>$emp_id,':s'=>$period_start,':end'=>$period_end]);
            if ($existing->rowCount()) {
                $db->prepare("UPDATE payroll SET basic_salary=:b,overtime_pay=:ot,gross_pay=:g,sss_deduction=:sss,philhealth_deduction=:ph,pagibig_deduction=:pi,tax_deduction=:tax,other_deductions=:od,total_deductions=:td,net_pay=:net,days_worked=:dw,absent_days=:ad,late_minutes=:lm,status='draft' WHERE employee_id=:e AND period_start=:s AND period_end=:pend")
                ->execute([':b'=>$basic_period,':ot'=>$overtime_pay,':g'=>$gross,':sss'=>$sss,':ph'=>$philhealth,':pi'=>$pagibig,':tax'=>$tax,':od'=>$other_ded,':td'=>$total_ded,':net'=>$net,':dw'=>$days_worked,':ad'=>$absent_days,':lm'=>$late_mins,':e'=>$emp_id,':s'=>$period_start,':pend'=>$period_end]);
            } else {
                $db->prepare("INSERT INTO payroll (employee_id,period_type,period_start,period_end,basic_salary,overtime_pay,gross_pay,sss_deduction,philhealth_deduction,pagibig_deduction,tax_deduction,other_deductions,total_deductions,net_pay,days_worked,absent_days,late_minutes,generated_by) VALUES(:e,:pt,:s,:end,:b,:ot,:g,:sss,:ph,:pi,:tax,:od,:td,:net,:dw,:ad,:lm,:gb)")
                ->execute([':e'=>$emp_id,':pt'=>$period_type,':s'=>$period_start,':end'=>$period_end,':b'=>$basic_period,':ot'=>$overtime_pay,':g'=>$gross,':sss'=>$sss,':ph'=>$philhealth,':pi'=>$pagibig,':tax'=>$tax,':od'=>$other_ded,':td'=>$total_ded,':net'=>$net,':dw'=>$days_worked,':ad'=>$absent_days,':lm'=>$late_mins,':gb'=>$_SESSION['admin_id']]);
            }
            $generated++;
        }
        $success = "Payroll generated for $generated employee(s).";
    }
}

// ── Approve payroll ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'approve') {
    $db->prepare("UPDATE payroll SET status='approved' WHERE id=:id")->execute([':id'=>(int)$_POST['payroll_id']]);
    $success = 'Payroll approved.';
}

// ── Fetch payroll list ────────────────────────────────────────
// employees has no employee_code column — employee_id is the real code column.
$payrolls = $db->query("SELECT p.*, e.first_name, e.last_name, e.employee_id AS employee_code, e.position FROM payroll p JOIN employees e ON p.employee_id=e.id ORDER BY p.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$employees = $db->query("SELECT id,employee_id AS employee_code,first_name,last_name FROM employees WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'payroll';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll - Pestify HR</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{background:#8e44ad}
.btn-sm{padding:5px 11px;font-size:12px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#e9d8fd;color:#6b46c1;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
.card-body{padding:22px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:600;color:#2d3748;margin-bottom:5px}
.form-control{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary)}
.emp-checklist{max-height:200px;overflow-y:auto;border:1.5px solid var(--border);border-radius:8px;padding:10px}
.emp-check-item{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;font-size:13px;cursor:pointer}
.emp-check-item:hover{background:#f8fafc}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-draft{background:#e2e8f0;color:#4a5568}.b-approved{background:#c6f6d5;color:#276749}.b-paid{background:#bee3f8;color:#2b6cb0}
/* Payslip modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:680px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.payslip{padding:32px;font-family:'DM Sans',sans-serif}
.payslip-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;padding-bottom:18px;border-bottom:2px solid #e2e8f0}
.payslip-logo{font-size:22px;font-weight:800;color:#1a2744;display:flex;align-items:center;gap:8px}
.payslip-logo span{color:#2E8B57}
.payslip-title{text-align:right;color:#718096;font-size:13px}
.payslip-title h3{font-size:16px;font-weight:700;color:#1a2744;margin-bottom:3px}
.payslip-emp{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;background:#f8fafc;padding:16px;border-radius:10px}
.ps-field{font-size:12px;color:#718096;margin-bottom:3px}
.ps-val{font-size:14px;font-weight:600;color:#1a2744}
.payslip-table{width:100%;border-collapse:collapse;margin-bottom:16px}
.payslip-table th{background:#f0f4ff;padding:10px 14px;font-size:12px;font-weight:700;text-align:left;color:#3b5bdb}
.payslip-table td{padding:9px 14px;border-bottom:1px solid #f0f0f0;font-size:13px}
.payslip-table .amount{text-align:right;font-weight:600}
.payslip-net{background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff;padding:18px 22px;border-radius:12px;display:flex;justify-content:space-between;align-items:center;margin-top:16px}
.payslip-net .label{font-size:14px;font-weight:600}
.payslip-net .amount{font-size:26px;font-weight:800}
@media print{.modal-overlay{position:static;background:none;display:block;padding:0}.modal-box{box-shadow:none;max-height:none}.no-print{display:none}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-money-check-alt"></i> Payroll</h1>
    <button class="btn btn-primary" onclick="document.getElementById('genModal').classList.add('open')"><i class="fas fa-cogs"></i> Generate Payroll</button>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-list"></i> Payroll Records</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Employee</th><th>Period</th><th>Type</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (empty($payrolls)): ?>
        <tr><td colspan="8" style="text-align:center;padding:50px;color:var(--muted)">No payroll records yet. Generate payroll above.</td></tr>
        <?php endif; ?>
        <?php foreach ($payrolls as $p): ?>
        <tr>
            <td>
                <div style="font-weight:600"><?=htmlspecialchars($p['first_name'].' '.$p['last_name'])?></div>
                <div style="font-size:11px;color:var(--muted)"><?=$p['employee_code']?> · <?=htmlspecialchars($p['position']??'')?></div>
            </td>
            <td style="font-size:12px"><?=date('M d',strtotime($p['period_start']))?> – <?=date('M d, Y',strtotime($p['period_end']))?></td>
            <td><span style="background:#f0e6ff;color:#6b46c1;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700"><?=ucfirst($p['period_type'])?></span></td>
            <td style="font-weight:600">₱<?=number_format($p['gross_pay'],2)?></td>
            <td style="color:#e74c3c">-₱<?=number_format($p['total_deductions'],2)?></td>
            <td style="font-weight:700;color:#27ae60;font-size:15px">₱<?=number_format($p['net_pay'],2)?></td>
            <td><span class="badge b-<?=$p['status']?>"><?=ucfirst($p['status'])?></span></td>
            <td style="display:flex;gap:6px">
                <button class="btn btn-sm" style="background:#e9d8fd;color:#6b46c1" onclick="showPayslip(<?=htmlspecialchars(json_encode($p))?>)"><i class="fas fa-file-pdf"></i> Payslip</button>
                <?php if ($p['status']==='draft'): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="payroll_id" value="<?=$p['id']?>">
                    <button class="btn btn-sm" style="background:#c6f6d5;color:#276749" type="submit"><i class="fas fa-check"></i> Approve</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></div>

<!-- Generate Modal -->
<div class="modal-overlay" id="genModal">
<div class="modal-box">
    <div class="card-header"><h2><i class="fas fa-cogs"></i> Generate Payroll</h2><button class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('genModal').classList.remove('open')">✕</button></div>
    <form method="POST" style="padding:22px">
        <input type="hidden" name="action" value="generate">
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Period Type</label>
                <select name="period_type" class="form-control">
                    <option value="15days">15 Days (Semi-monthly)</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Period Start</label>
                <input type="date" name="period_start" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Period End</label>
                <input type="date" name="period_end" class="form-control" required>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Select Employees</label>
            <label style="font-size:12px;color:var(--primary);cursor:pointer;display:block;margin-bottom:6px" onclick="toggleAll()"><i class="fas fa-check-square"></i> Select All</label>
            <div class="emp-checklist" id="empList">
                <?php foreach ($employees as $e): ?>
                <label class="emp-check-item">
                    <input type="checkbox" name="emp_ids[]" value="<?=$e['id']?>" style="accent-color:var(--primary)">
                    <span>[<?=$e['employee_code']?>] <?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
            <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('genModal').classList.remove('open')">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-cogs"></i> Generate</button>
        </div>
    </form>
</div>
</div>

<!-- Payslip Modal -->
<div class="modal-overlay" id="payslipModal">
<div class="modal-box">
    <div class="no-print" style="padding:14px 22px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
        <strong>Payslip Preview</strong>
        <div style="display:flex;gap:10px">
            <button class="btn btn-sm" style="background:#27ae60;color:#fff" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
            <button class="btn btn-sm" style="background:#f0f0f0;color:#555" onclick="document.getElementById('payslipModal').classList.remove('open')">✕ Close</button>
        </div>
    </div>
    <div class="payslip" id="payslipContent"></div>
</div>
</div>

<script>
function toggleAll() {
    const boxes = document.querySelectorAll('#empList input[type=checkbox]');
    const allChecked = Array.from(boxes).every(b=>b.checked);
    boxes.forEach(b=>b.checked=!allChecked);
}
document.getElementById('genModal').addEventListener('click',e=>{if(e.target===document.getElementById('genModal'))e.target.classList.remove('open')});
document.getElementById('payslipModal').addEventListener('click',e=>{if(e.target===document.getElementById('payslipModal'))e.target.classList.remove('open')});

function showPayslip(p) {
    const fmt = n => '₱' + parseFloat(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
    const fmtDate = s => new Date(s).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
    document.getElementById('payslipContent').innerHTML = `
    <div class="payslip-header">
        <div class="payslip-logo"><i class="fas fa-bug" style="color:#2E8B57"></i> Pesti<span>fy</span></div>
        <div class="payslip-title"><h3>PAYSLIP</h3><div>${fmtDate(p.period_start)} – ${fmtDate(p.period_end)}</div><div>${p.period_type==='15days'?'Semi-Monthly':'Monthly'}</div></div>
    </div>
    <div class="payslip-emp">
        <div><div class="ps-field">Employee</div><div class="ps-val">${p.first_name} ${p.last_name}</div></div>
        <div><div class="ps-field">Employee Code</div><div class="ps-val">${p.employee_code}</div></div>
        <div><div class="ps-field">Position</div><div class="ps-val">${p.position||'—'}</div></div>
        <div><div class="ps-field">Days Worked</div><div class="ps-val">${p.days_worked} days</div></div>
    </div>
    <table class="payslip-table">
        <tr><th colspan="2">EARNINGS</th></tr>
        <tr><td>Basic Salary</td><td class="amount">${fmt(p.basic_salary)}</td></tr>
        <tr><td>Overtime Pay</td><td class="amount">${fmt(p.overtime_pay)}</td></tr>
        <tr style="background:#f0fdf4"><td><strong>Gross Pay</strong></td><td class="amount"><strong>${fmt(p.gross_pay)}</strong></td></tr>
    </table>
    <table class="payslip-table">
        <tr><th colspan="2">DEDUCTIONS</th></tr>
        <tr><td>SSS</td><td class="amount">${fmt(p.sss_deduction)}</td></tr>
        <tr><td>PhilHealth</td><td class="amount">${fmt(p.philhealth_deduction)}</td></tr>
        <tr><td>Pag-IBIG</td><td class="amount">${fmt(p.pagibig_deduction)}</td></tr>
        <tr><td>Withholding Tax</td><td class="amount">${fmt(p.tax_deduction)}</td></tr>
        <tr><td>Other (Late/Absent)</td><td class="amount">${fmt(p.other_deductions)}</td></tr>
        <tr style="background:#fff5f5"><td><strong>Total Deductions</strong></td><td class="amount"><strong style="color:#e53e3e">${fmt(p.total_deductions)}</strong></td></tr>
    </table>
    <div class="payslip-net"><span class="label">NET PAY</span><span class="amount">${fmt(p.net_pay)}</span></div>
    <div style="margin-top:24px;display:grid;grid-template-columns:1fr 1fr;gap:20px;padding-top:20px;border-top:1px solid #e2e8f0">
        <div><div style="font-size:12px;color:#718096;margin-bottom:20px">Employee Signature</div><div style="border-top:1px solid #1a2744;padding-top:4px;font-size:11px;color:#718096">${p.first_name} ${p.last_name}</div></div>
        <div><div style="font-size:12px;color:#718096;margin-bottom:20px">Authorized By</div><div style="border-top:1px solid #1a2744;padding-top:4px;font-size:11px;color:#718096">HR / Finance Officer</div></div>
    </div>`;
    document.getElementById('payslipModal').classList.add('open');
}
</script>
</body></html>