<?php
// admin/hr/dashboard.php
$require_dept = 'hr';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();

// Stats
$total_emp   = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$on_leave    = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$open_jobs   = (int)$db->query("SELECT COUNT(*) FROM recruitment WHERE status='open'")->fetchColumn();
$pending_req = (int)$db->query("SELECT COUNT(*) FROM budget_requests WHERE status='pending'")->fetchColumn();

// Attendance today
$today = date('Y-m-d');
$present_today = (int)$db->prepare("SELECT COUNT(*) FROM attendance WHERE date=:d AND status IN('present','late')")->execute([':d'=>$today]) ? $db->query("SELECT COUNT(*) FROM attendance WHERE date='$today' AND status IN('present','late')")->fetchColumn() : 0;
$absent_today  = $total_emp - $present_today;

// Monthly attendance summary (last 7 days)
$att_days = []; $att_present = []; $att_absent = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $lbl = date('D', strtotime($d));
    $att_days[] = $lbl;
    $p = (int)$db->query("SELECT COUNT(*) FROM attendance WHERE date='$d' AND status IN('present','late')")->fetchColumn();
    $att_present[] = $p;
    $att_absent[]  = max(0, $total_emp - $p);
}

// Department breakdown
$dept_rows = $db->query("SELECT department, COUNT(*) as cnt FROM employees WHERE status='active' GROUP BY department")->fetchAll(PDO::FETCH_ASSOC);

// Recent leave requests
$leaves = $db->query("SELECT lr.*, e.first_name, e.last_name, e.position FROM leave_requests lr JOIN employees e ON lr.employee_id=e.id ORDER BY lr.created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'hr_dash';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HR Dashboard - Pestify</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}
.main-content{flex:1;padding:32px}
.page-header{margin-bottom:28px}
.page-header h1{font-size:26px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:10px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:4px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:28px}
.stat-card{background:var(--white);padding:22px;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:16px;transition:all .25s}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.1)}
.stat-icon{width:54px;height:54px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.stat-info h3{font-size:26px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:12px;color:var(--muted);margin-top:2px}
.analytics-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
.card-body{padding:18px 22px}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:13px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-pending{background:#fff3cd;color:#856404}.b-approved{background:#c6f6d5;color:#276749}.b-rejected{background:#fed7d7;color:#c53030}
.today-bar{display:flex;gap:16px;margin-bottom:24px}
.today-item{flex:1;background:var(--white);border-radius:12px;padding:18px;border:1px solid var(--border);text-align:center;box-shadow:0 2px 6px rgba(0,0,0,.05)}
.today-item h3{font-size:32px;font-weight:700}
.today-item p{font-size:12px;color:var(--muted);margin-top:4px}
.t-green h3{color:#27ae60}.t-red h3{color:#e74c3c}.t-blue h3{color:#3498db}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}.analytics-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-chart-pie"></i> HR Dashboard</h1>
    <p><?= date('l, F j, Y') ?> &nbsp;·&nbsp; Human Resources Department</p>
</div>

<!-- Stat Cards -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-id-badge"></i></div><div class="stat-info"><h3><?= $total_emp ?></h3><p>Active Employees</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-file-alt"></i></div><div class="stat-info"><h3><?= $on_leave ?></h3><p>Pending Leave Requests</p></div></div>
    <div class="stat-card"><div class="stat-icon si-blue"><i class="fas fa-user-plus"></i></div><div class="stat-info"><h3><?= $open_jobs ?></h3><p>Open Job Positions</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-hand-paper"></i></div><div class="stat-info"><h3><?= $pending_req ?></h3><p>Pending HR Requests</p></div></div>
</div>

<!-- Today's Attendance -->
<div class="today-bar">
    <div class="today-item t-green"><h3><?= $present_today ?></h3><p><i class="fas fa-user-check"></i> Present Today</p></div>
    <div class="today-item t-red"><h3><?= $absent_today ?></h3><p><i class="fas fa-user-times"></i> Absent Today</p></div>
    <div class="today-item t-blue"><h3><?= $total_emp ?></h3><p><i class="fas fa-users"></i> Total Employees</p></div>
</div>

<!-- Charts -->
<div class="analytics-grid">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-bar"></i> Attendance (Last 7 Days)</h2>
        </div>
        <div class="card-body"><canvas id="attChart" height="90"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-chart-donut"></i> By Department</h2></div>
        <div class="card-body" style="display:flex;flex-direction:column;align-items:center;gap:14px">
            <canvas id="deptChart" width="160" height="160" style="max-width:160px"></canvas>
            <div style="width:100%">
            <?php $dcolors=['#9b59b6','#3498db','#27ae60','#e67e22','#e74c3c'];$di=0; foreach($dept_rows as $dr): ?>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:7px">
                <span style="display:flex;align-items:center;gap:7px">
                    <span style="width:10px;height:10px;border-radius:50%;background:<?=$dcolors[$di%5]?>;display:inline-block"></span>
                    <?= ucfirst($dr['department']) ?>
                </span>
                <strong><?= $dr['cnt'] ?></strong>
            </div>
            <?php $di++; endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Leave Requests -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-calendar-minus"></i> Recent Leave Requests</h2>
        <a href="requests.php" style="font-size:13px;color:var(--primary);text-decoration:none;font-weight:600">View All</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Employee</th><th>Position</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($leaves)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">No leave requests yet</td></tr>
        <?php endif; ?>
        <?php foreach ($leaves as $l): ?>
        <tr>
            <td><strong><?= htmlspecialchars($l['first_name'].' '.$l['last_name']) ?></strong></td>
            <td style="color:var(--muted);font-size:12px"><?= htmlspecialchars($l['position']??'—') ?></td>
            <td><span style="background:#f0f4ff;color:#3b5bdb;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700"><?= ucfirst($l['leave_type']) ?></span></td>
            <td><?= date('M d, Y', strtotime($l['date_from'])) ?></td>
            <td><?= date('M d, Y', strtotime($l['date_to'])) ?></td>
            <td><?= $l['days'] ?></td>
            <td><span class="badge b-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
</div>
<script>
new Chart(document.getElementById('attChart'),{type:'bar',data:{
    labels: <?= json_encode($att_days) ?>,
    datasets:[
        {label:'Present',data:<?= json_encode($att_present) ?>,backgroundColor:'rgba(39,174,96,.7)',borderRadius:5},
        {label:'Absent',data:<?= json_encode($att_absent) ?>,backgroundColor:'rgba(231,76,60,.25)',borderRadius:5}
    ]},options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'}}}}});

const deptData = <?= json_encode(array_column($dept_rows,'cnt')) ?>;
const deptLabels = <?= json_encode(array_map(fn($r)=>ucfirst($r['department']),$dept_rows)) ?>;
new Chart(document.getElementById('deptChart'),{type:'doughnut',data:{
    labels:deptLabels.length?deptLabels:['No Data'],
    datasets:[{data:deptData.length?deptData:[1],backgroundColor:deptData.length?['#9b59b6','#3498db','#27ae60','#e67e22','#e74c3c']:['#e2e8f0'],borderWidth:0,cutout:'70%'}]
},options:{responsive:false,plugins:{legend:{display:false}}}});
</script>
</body></html>