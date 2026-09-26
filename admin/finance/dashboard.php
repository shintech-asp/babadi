<?php
// admin/finance/dashboard.php
$require_dept = 'finance';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();

// ── Stats ─────────────────────────────────────────────────────
// income_records/expense_records date columns are income_date/expense_date,
// not "date". expense_records also has no status column — every row already
// represents a finalized expense (see admin/finance/expenses.php's Add
// Expense handler, which never wrote anything else), so the old
// "AND status='approved'" filter is just dropped rather than invented.
$total_income   = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM income_records WHERE YEAR(income_date)=YEAR(NOW())")->fetchColumn();
$total_expenses = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM expense_records WHERE YEAR(expense_date)=YEAR(NOW())")->fetchColumn();
$net_profit     = $total_income - $total_expenses;
$pending_budget = (int)$db->query("SELECT COUNT(*) FROM budget_requests WHERE status='pending'")->fetchColumn();

// Service revenue from availed_services this year
$service_rev = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE status='completed' AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

// ── Monthly income vs expenses (last 6 months) ────────────────
$chart_labels = []; $chart_income = []; $chart_expense = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $chart_labels[] = $label;
    $inc = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM income_records WHERE DATE_FORMAT(income_date,'%Y-%m')='$m'")->fetchColumn();
    $inc += (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE status='completed' AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $exp = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM expense_records WHERE DATE_FORMAT(expense_date,'%Y-%m')='$m'")->fetchColumn();
    $chart_income[]  = round($inc, 2);
    $chart_expense[] = round($exp, 2);
}

// ── Expense breakdown by category ─────────────────────────────
$exp_cats = $db->query("SELECT category, SUM(amount) as total FROM expense_records WHERE YEAR(expense_date)=YEAR(NOW()) GROUP BY category ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent transactions ───────────────────────────────────────
// Aliased to a common "date" key so the merged $transactions list below
// (income + expense rows mixed together) can read $t['date'] either way.
// income_records has no category column (income_type is its equivalent) —
// aliased so the shared display table's Category cell isn't blank for income rows.
$recent_inc = $db->query("SELECT *, income_date AS date, income_type AS category, 'income' as rec_type FROM income_records ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$recent_exp = $db->query("SELECT *, expense_date AS date, 'expense' as rec_type FROM expense_records ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$transactions = array_merge($recent_inc, $recent_exp);
usort($transactions, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$transactions = array_slice($transactions, 0, 10);

$active_menu = 'fin_dash';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finance Dashboard - Pestify</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--primary:#27ae60;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{margin-bottom:28px}
.page-header h1{font-size:26px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:10px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:4px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:28px}
.stat-card{background:var(--white);padding:22px;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);transition:all .25s}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.1)}
.stat-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.stat-card h3{font-size:24px;font-weight:700;color:var(--dark)}
.stat-card p{font-size:12px;color:var(--muted);margin-top:3px}
.stat-card .trend{font-size:11px;font-weight:600;margin-top:6px}
.trend-up{color:#27ae60}.trend-down{color:#e74c3c}
.analytics-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
.card-body{padding:18px 22px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.cat-bar{height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-top:4px}
.cat-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#27ae60,#16a085)}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}.analytics-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-chart-line"></i> Finance Dashboard</h1>
    <p><?= date('l, F j, Y') ?> &nbsp;·&nbsp; Finance Department &nbsp;·&nbsp; <?= date('Y') ?> Overview</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><div><p>Total Income</p><h3>₱<?= number_format($total_income,0) ?></h3></div><div class="stat-icon si-green"><i class="fas fa-arrow-up"></i></div></div>
        <div class="trend trend-up"><i class="fas fa-circle" style="font-size:8px"></i> This year</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div><p>Total Expenses</p><h3>₱<?= number_format($total_expenses,0) ?></h3></div><div class="stat-icon si-red"><i class="fas fa-arrow-down"></i></div></div>
        <div class="trend trend-down"><i class="fas fa-circle" style="font-size:8px"></i> This year</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div><p>Net Profit</p><h3 style="color:<?= $net_profit>=0?'#27ae60':'#e74c3c' ?>">₱<?= number_format(abs($net_profit),0) ?></h3></div><div class="stat-icon <?= $net_profit>=0?'si-blue':'si-orange' ?>"><i class="fas fa-balance-scale"></i></div></div>
        <div class="trend <?= $net_profit>=0?'trend-up':'trend-down' ?>"><?= $net_profit>=0?'Profitable':'At a loss' ?> this year</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div><p>Pending Budget Requests</p><h3><?= $pending_budget ?></h3></div><div class="stat-icon si-orange"><i class="fas fa-file-invoice"></i></div></div>
        <div class="trend" style="color:<?= $pending_budget>0?'#e67e22':'#718096' ?>"><?= $pending_budget>0?'Needs review':'All clear' ?></div>
    </div>
</div>

<div class="analytics-grid">
    <!-- Income vs Expense Chart -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-bar"></i> Income vs Expenses (6 months)</h2>
            <div style="display:flex;gap:12px;font-size:11px;color:var(--muted)">
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;background:#27ae60;border-radius:2px;display:inline-block"></span>Income</span>
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;background:#e74c3c;border-radius:2px;display:inline-block"></span>Expenses</span>
            </div>
        </div>
        <div class="card-body"><canvas id="finChart" height="110"></canvas></div>
    </div>

    <!-- Expense Breakdown -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-tags"></i> Expenses by Category</h2></div>
        <div class="card-body">
            <?php if (empty($exp_cats)): ?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px">No expense data yet</p>
            <?php endif; ?>
            <?php $max_exp = max(1, max(array_column($exp_cats,'total'))); foreach ($exp_cats as $cat): ?>
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                    <span style="font-weight:600;color:var(--dark)"><?= ucfirst($cat['category']) ?></span>
                    <span style="color:var(--muted)">₱<?= number_format($cat['total'],0) ?></span>
                </div>
                <div class="cat-bar"><div class="cat-fill" style="width:<?= round($cat['total']/$max_exp*100) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-exchange-alt"></i> Recent Transactions</h2>
        <div style="display:flex;gap:8px">
            <a href="income.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Income</a>
            <a href="expenses.php" class="btn" style="background:#fed7d7;color:#c53030"><i class="fas fa-plus"></i> Add Expense</a>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Type</th><th>Description</th><th>Category</th><th>Amount</th><th>Date</th></tr></thead>
        <tbody>
        <?php if (empty($transactions)): ?><tr><td colspan="5" style="text-align:center;padding:40px;color:var(--muted)">No transactions yet</td></tr><?php endif; ?>
        <?php foreach ($transactions as $t): $is_inc = $t['rec_type']==='income'; ?>
        <tr>
            <td><span style="background:<?=$is_inc?'#c6f6d5':'#fed7d7'?>;color:<?=$is_inc?'#276749':'#c53030'?>;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700"><i class="fas fa-arrow-<?=$is_inc?'up':'down'?>"></i> <?=$is_inc?'Income':'Expense'?></span></td>
            <td style="font-weight:500"><?=htmlspecialchars($t['description'])?></td>
            <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($t['category']??'—')?></td>
            <td style="font-weight:700;color:<?=$is_inc?'#27ae60':'#e74c3c'?>"><?=$is_inc?'+':'-'?>₱<?=number_format($t['amount'],2)?></td>
            <td style="font-size:12px;color:var(--muted)"><?=date('M d, Y',strtotime($t['date']))?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></div>
<script>
new Chart(document.getElementById('finChart'),{type:'bar',data:{
    labels:<?=json_encode($chart_labels)?>,
    datasets:[
        {label:'Income',data:<?=json_encode($chart_income)?>,backgroundColor:'rgba(39,174,96,.7)',borderRadius:5},
        {label:'Expenses',data:<?=json_encode($chart_expense)?>,backgroundColor:'rgba(231,76,60,.5)',borderRadius:5}
    ]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{callback:v=>'₱'+(v>=1000?(v/1000).toFixed(0)+'k':v)}}}}});
</script>
</body></html>