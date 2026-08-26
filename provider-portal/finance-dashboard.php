<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

if (!($portal_role === 'owner' || $portal_dept === 'finance' || $portal_dept === 'all')) {
    header('Location: dashboard.php'); exit;
}

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Finance Dashboard'); exit; }

function safeSum($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(float)$s->fetchColumn();}catch(Exception $e){return 0;}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}
function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

$year = date('Y');
$month = date('Y-m');

$income_year  = safeSum($db,"SELECT COALESCE(SUM(amount),0) FROM income_records WHERE provider_id=:p AND YEAR(income_date)=:y",[':p'=>$pid,':y'=>$year]);
$service_rev  = safeSum($db,"SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE provider_id=:p AND status='completed' AND YEAR(created_at)=:y",[':p'=>$pid,':y'=>$year]);
$income_year += $service_rev;
$expense_year = safeSum($db,"SELECT COALESCE(SUM(amount),0) FROM expense_records WHERE provider_id=:p AND YEAR(expense_date)=:y",[':p'=>$pid,':y'=>$year]);
$net_year = $income_year - $expense_year;

$income_month  = safeSum($db,"SELECT COALESCE(SUM(amount),0) FROM income_records WHERE provider_id=:p AND DATE_FORMAT(income_date,'%Y-%m')=:m",[':p'=>$pid,':m'=>$month]);
$income_month += safeSum($db,"SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE provider_id=:p AND status='completed' AND DATE_FORMAT(created_at,'%Y-%m')=:m",[':p'=>$pid,':m'=>$month]);
$expense_month = safeSum($db,"SELECT COALESCE(SUM(amount),0) FROM expense_records WHERE provider_id=:p AND DATE_FORMAT(expense_date,'%Y-%m')=:m",[':p'=>$pid,':m'=>$month]);
$pending_budget = safeCount($db,"SELECT COUNT(*) FROM budget_requests WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);

$recent_income = safeAll($db,"SELECT * FROM income_records WHERE provider_id=:p ORDER BY created_at DESC LIMIT 5",[':p'=>$pid]);
$recent_expense = safeAll($db,"SELECT * FROM expense_records WHERE provider_id=:p ORDER BY created_at DESC LIMIT 5",[':p'=>$pid]);

$chart_labels = []; $chart_income = []; $chart_expense = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chart_labels[] = date('M Y', strtotime("-$i months"));
    $inc = safeSum($db,"SELECT COALESCE(SUM(amount),0) FROM income_records WHERE provider_id=:p AND DATE_FORMAT(income_date,'%Y-%m')=:m",[':p'=>$pid,':m'=>$m]);
    $inc += safeSum($db,"SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE provider_id=:p AND status='completed' AND DATE_FORMAT(created_at,'%Y-%m')=:m",[':p'=>$pid,':m'=>$m]);
    $exp = safeSum($db,"SELECT COALESCE(SUM(amount),0) FROM expense_records WHERE provider_id=:p AND DATE_FORMAT(expense_date,'%Y-%m')=:m",[':p'=>$pid,':m'=>$m]);
    $chart_income[] = round($inc,2);
    $chart_expense[] = round($exp,2);
}

$active_menu='finance';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finance Dashboard - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--primary:#27ae60;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{margin-bottom:26px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:#fff;padding:18px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px;transition:all .25s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.1)}
.stat-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.stat-info h3{font-size:19px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.analytics-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:22px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
.card-body{padding:16px 20px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
.quick-links{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:16px 20px}
.ql-btn{border-radius:10px;padding:14px;text-decoration:none;display:flex;align-items:center;gap:10px;color:#fff;font-weight:600;font-size:13px;transition:all .2s}
.ql-btn:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.15)}
.ql-btn i{font-size:20px}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.summary-box{border-radius:10px;padding:16px;margin-bottom:12px}
.summary-box h4{font-size:11px;color:var(--muted);margin-bottom:4px}
.summary-box .amount{font-size:24px;font-weight:800}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr)}.analytics-grid{grid-template-columns:1fr}.two-col{grid-template-columns:1fr}}
@media(max-width:768px){.stats-grid{grid-template-columns:1fr}.quick-links{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-chart-line"></i> Finance Dashboard</h1>
    <p><?= htmlspecialchars($portal_company) ?> &nbsp;·&nbsp; <?= date('l, F j, Y') ?></p>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-arrow-up"></i></div><div class="stat-info"><h3>₱<?= number_format($income_year,0) ?></h3><p>Income This Year</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-arrow-down"></i></div><div class="stat-info"><h3>₱<?= number_format($expense_year,0) ?></h3><p>Expenses This Year</p></div></div>
    <div class="stat-card"><div class="stat-icon <?= $net_year>=0?'si-blue':'si-red' ?>"><i class="fas fa-balance-scale"></i></div><div class="stat-info"><h3><?= $net_year<0?'-':'' ?>₱<?= number_format(abs($net_year),0) ?></h3><p>Net Profit (Year)</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-file-invoice-dollar"></i></div><div class="stat-info"><h3><?= $pending_budget ?></h3><p>Pending Budget Req.</p></div></div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-th-large"></i> Finance Modules</h2></div>
    <div class="quick-links">
        <a href="income.php" class="ql-btn" style="background:linear-gradient(135deg,#27ae60,#16a085)"><i class="fas fa-arrow-circle-up"></i> Income / Sales</a>
        <a href="expenses.php" class="ql-btn" style="background:linear-gradient(135deg,#e74c3c,#c0392b)"><i class="fas fa-arrow-circle-down"></i> Expenses</a>
        <a href="budget-requests.php" class="ql-btn" style="background:linear-gradient(135deg,#e67e22,#d35400)"><i class="fas fa-file-invoice-dollar"></i> Budget Requests</a>
    </div>
</div>

<div class="analytics-grid">
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-chart-bar"></i> Income vs Expenses (Last 6 Months)</h2></div>
        <div class="card-body"><canvas id="finChart" height="110"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-calendar"></i> This Month</h2></div>
        <div class="card-body">
            <div class="summary-box" style="background:#f0fdf4;border:1px solid #bbf7d0">
                <h4>Income — <?= date('F Y') ?></h4>
                <div class="amount" style="color:#27ae60">₱<?= number_format($income_month,0) ?></div>
            </div>
            <div class="summary-box" style="background:#fff5f5;border:1px solid #fed7d7">
                <h4>Expenses — <?= date('F Y') ?></h4>
                <div class="amount" style="color:#e74c3c">₱<?= number_format($expense_month,0) ?></div>
            </div>
            <div class="summary-box" style="background:<?= ($income_month-$expense_month)>=0?'#ebf8ff':'#fff5f5' ?>;border:1px solid <?= ($income_month-$expense_month)>=0?'#90cdf4':'#fed7d7' ?>">
                <h4>Net — <?= date('F Y') ?></h4>
                <div class="amount" style="color:<?= ($income_month-$expense_month)>=0?'#2b6cb0':'#e74c3c' ?>"><?= ($income_month-$expense_month)<0?'-':'' ?>₱<?= number_format(abs($income_month-$expense_month),0) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-arrow-up"></i> Recent Income</h2>
            <a href="income.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View All</a>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
            <tbody>
            <?php if(empty($recent_income)): ?>
            <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--muted)">No income records.</td></tr>
            <?php endif; ?>
            <?php foreach($recent_income as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['income_type']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['received_from']??'—') ?></small></td>
                <td style="color:#27ae60;font-weight:700">₱<?= number_format($r['amount'],2) ?></td>
                <td style="font-size:12px"><?= date('M d, Y', strtotime($r['income_date'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-arrow-down"></i> Recent Expenses</h2>
            <a href="expenses.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View All</a>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
            <tbody>
            <?php if(empty($recent_expense)): ?>
            <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--muted)">No expense records.</td></tr>
            <?php endif; ?>
            <?php foreach($recent_expense as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['expense_type']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['paid_to']??'—') ?></small></td>
                <td style="color:#e74c3c;font-weight:700">₱<?= number_format($r['amount'],2) ?></td>
                <td style="font-size:12px"><?= date('M d, Y', strtotime($r['expense_date'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

</div></div></div>
<script>
new Chart(document.getElementById('finChart'),{type:'bar',data:{labels:<?= json_encode($chart_labels) ?>,datasets:[{label:'Income',data:<?= json_encode($chart_income) ?>,backgroundColor:'rgba(39,174,96,.7)',borderRadius:5},{label:'Expenses',data:<?= json_encode($chart_expense) ?>,backgroundColor:'rgba(231,76,60,.5)',borderRadius:5}]},options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{callback:v=>'₱'+(v>=1000?(v/1000).toFixed(0)+'k':v)}}}}});
</script>
</body></html>