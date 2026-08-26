<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$can_crm = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');
if (!$can_crm) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('CRM Dashboard'); exit; }

function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}
function safeSum($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(float)$s->fetchColumn();}catch(Exception $e){return 0;}}
function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

// Stats
$total_bookings    = safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p",[':p'=>$pid]);
$pending_bookings  = safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);
$active_bookings   = safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status IN('waiting_provider_confirmation','preparing','on_the_way','in_progress')",[':p'=>$pid]);
$completed_bookings= safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status='completed'",[':p'=>$pid]);
$cancelled_bookings= safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND status IN('cancelled','rejected')",[':p'=>$pid]);
$total_revenue     = safeSum($db,"SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE provider_id=:p AND status='completed'",[':p'=>$pid]);
$pending_requests  = safeCount($db,"SELECT COUNT(*) FROM inventory_requests WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);
$active_services   = safeCount($db,"SELECT COUNT(*) FROM services WHERE provider_id=:p AND status='active'",[':p'=>$pid]);

// Monthly chart data (last 6 months)
$chart_labels = []; $chart_bookings = []; $chart_revenue = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chart_labels[]   = date('M', strtotime("-$i months"));
    $chart_bookings[] = safeCount($db,"SELECT COUNT(*) FROM availed_services WHERE provider_id=:p AND DATE_FORMAT(created_at,'%Y-%m')=:m",[':p'=>$pid,':m'=>$m]);
    $chart_revenue[]  = safeSum($db,"SELECT COALESCE(SUM(total_amount),0) FROM availed_services WHERE provider_id=:p AND status='completed' AND DATE_FORMAT(created_at,'%Y-%m')=:m",[':p'=>$pid,':m'=>$m]);
}

// Recent bookings
$recent_bookings = safeAll($db,"SELECT * FROM availed_services WHERE provider_id=:p ORDER BY created_at DESC LIMIT 6",[':p'=>$pid]);

// Pending inventory requests
$pending_inv = safeAll($db,"SELECT ir.*, e.first_name, e.last_name FROM inventory_requests ir LEFT JOIN employees e ON ir.requested_by=e.id WHERE ir.provider_id=:p AND ir.status='pending' ORDER BY ir.created_at DESC LIMIT 5",[':p'=>$pid]);

$active_menu = 'crm_dash';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CRM Dashboard · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--primary:#1abc9c;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{margin-bottom:24px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.stat-card{background:#fff;padding:18px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.1)}
.stat-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0}
.si-teal{background:linear-gradient(135deg,#1abc9c,#16a085)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.si-green{background:linear-gradient(135deg,#27ae60,#1e8449)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.stat-info h3{font-size:22px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted);margin-top:2px}
.grid-2{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:22px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
.card-body{padding:18px 20px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:9px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:9px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-teal{background:#d1fae5;color:#065f46}
.pill-blue{background:#dbeafe;color:#1e40af}
.pill-orange{background:#fef3c7;color:#92400e}
.pill-red{background:#fee2e2;color:#991b1b}
.pill-gray{background:#f1f5f9;color:#475569}
.pill-purple{background:#ede9fe;color:#5b21b6}
.quick-links{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:16px}
.ql-btn{border-radius:10px;padding:14px;text-decoration:none;display:flex;align-items:center;gap:10px;color:#fff;font-weight:600;font-size:13px;transition:all .2s}
.ql-btn:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.15)}
.ql-btn i{font-size:18px}
.empty-state{text-align:center;padding:30px;color:var(--muted);font-size:13px}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-headset"></i> CRM / Operations Dashboard</h1>
    <p><?= htmlspecialchars($portal_company) ?> &nbsp;·&nbsp; <?= date('l, F j, Y') ?></p>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-teal"><i class="fas fa-calendar-check"></i></div><div class="stat-info"><h3><?= $total_bookings ?></h3><p>Total Bookings</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-clock"></i></div><div class="stat-info"><h3><?= $pending_bookings ?></h3><p>Pending</p></div></div>
    <div class="stat-card"><div class="stat-icon si-blue"><i class="fas fa-spinner"></i></div><div class="stat-info"><h3><?= $active_bookings ?></h3><p>Active / In Progress</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div><div class="stat-info"><h3><?= $completed_bookings ?></h3><p>Completed</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-circle-xmark"></i></div><div class="stat-info"><h3><?= $cancelled_bookings ?></h3><p>Cancelled</p></div></div>
    <div class="stat-card"><div class="stat-icon si-teal"><i class="fas fa-peso-sign"></i></div><div class="stat-info"><h3>₱<?= number_format($total_revenue,0) ?></h3><p>Total Revenue</p></div></div>
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-box"></i></div><div class="stat-info"><h3><?= $pending_requests ?></h3><p>Pending Requests</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-briefcase"></i></div><div class="stat-info"><h3><?= $active_services ?></h3><p>Active Services</p></div></div>
</div>

<div class="grid-2">
    <!-- Chart -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-chart-bar"></i> Bookings & Revenue (Last 6 Months)</h2></div>
        <div class="card-body"><canvas id="crmChart" height="120"></canvas></div>
    </div>
    <!-- Quick Links -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-th-large"></i> Quick Access</h2></div>
        <div class="quick-links">
            <a href="crm-bookings.php" class="ql-btn" style="background:linear-gradient(135deg,#1abc9c,#16a085)"><i class="fas fa-calendar-check"></i> Bookings</a>
            <a href="crm-services.php" class="ql-btn" style="background:linear-gradient(135deg,#3498db,#2980b9)"><i class="fas fa-briefcase"></i> My Services</a>
            <a href="crm-requests.php" class="ql-btn" style="background:linear-gradient(135deg,#e67e22,#d35400)"><i class="fas fa-box-open"></i> Inventory Requests</a>
            <a href="timekeeping.php"  class="ql-btn" style="background:linear-gradient(135deg,#9b59b6,#8e44ad)"><i class="fas fa-clock"></i> Timekeeping</a>
        </div>
    </div>
</div>

<!-- Recent Bookings -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-list-check"></i> Recent Bookings</h2>
        <a href="crm-bookings.php" class="btn btn-primary"><i class="fas fa-eye"></i> View All</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>#</th><th>Client</th><th>Service</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($recent_bookings)): ?>
        <tr><td colspan="6" class="empty-state">No bookings yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent_bookings as $b):
            $st = $b['status'];
            [$pc,$pt] = match(true) {
                $st==='pending'                          => ['pill-orange','Pending'],
                $st==='waiting_provider_confirmation'    => ['pill-purple','Awaiting Confirm'],
                $st==='preparing'                        => ['pill-blue',  'Preparing'],
                in_array($st,['on_the_way','in_progress'])=>['pill-blue', ucwords(str_replace('_',' ',$st))],
                $st==='completed'                        => ['pill-teal',  'Completed'],
                default                                  => ['pill-red',   ucwords(str_replace('_',' ',$st))],
            };
        ?>
        <tr>
            <td style="color:var(--muted);font-size:12px">#<?= $b['id'] ?></td>
            <td><strong><?= htmlspecialchars($b['full_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($b['contact_number']) ?></small></td>
            <td style="font-size:12px"><?= htmlspecialchars($b['service_name']??'—') ?></td>
            <td style="font-size:12px;white-space:nowrap"><?= date('M j, Y', strtotime($b['preferred_date'])) ?></td>
            <td style="font-weight:600"><?= $b['total_amount']>0?'₱'.number_format($b['total_amount'],0):'—' ?></td>
            <td><span class="pill <?= $pc ?>"><?= $pt ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Pending Inventory Requests -->
<?php if (!empty($pending_inv)): ?>
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-box-open"></i> Pending Inventory Requests</h2>
        <a href="crm-requests.php" class="btn btn-primary"><i class="fas fa-eye"></i> View All</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Requested By</th><th>Item</th><th>Qty</th><th>Urgency</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($pending_inv as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars(($r['first_name']??'').' '.($r['last_name']??'Unknown')) ?></strong></td>
            <td><?= htmlspecialchars($r['item_name']) ?> <span style="font-size:11px;color:var(--muted)">(<?= $r['item_type'] ?>)</span></td>
            <td><?= $r['quantity_requested'] ?> <?= htmlspecialchars($r['unit']??'') ?></td>
            <td><span class="pill <?= $r['urgency']==='urgent'?'pill-red':($r['urgency']==='normal'?'pill-blue':'pill-gray') ?>"><?= ucfirst($r['urgency']) ?></span></td>
            <td style="font-size:12px"><?= date('M j', strtotime($r['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div></div></div>
<script>
new Chart(document.getElementById('crmChart'),{
    type:'bar',
    data:{
        labels:<?= json_encode($chart_labels) ?>,
        datasets:[
            {label:'Bookings',data:<?= json_encode($chart_bookings) ?>,backgroundColor:'rgba(26,188,156,.7)',borderRadius:5,yAxisID:'y'},
            {label:'Revenue (₱)',data:<?= json_encode($chart_revenue) ?>,backgroundColor:'rgba(52,152,219,.5)',borderRadius:5,yAxisID:'y1'}
        ]
    },
    options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{
        x:{grid:{display:false}},
        y:{beginAtZero:true,position:'left',title:{display:true,text:'Bookings'}},
        y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{callback:v=>'₱'+(v>=1000?(v/1000).toFixed(0)+'k':v)}}
    }}
});
</script>
</body></html>