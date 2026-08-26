<?php
// admin/finance/timekeeping.php
$require_dept = 'finance';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();

$month  = $_GET['month'] ?? date('Y-m');
$records = $db->prepare("SELECT tk.*, e.first_name, e.last_name, e.employee_code, e.department, e.basic_salary FROM timekeeping tk JOIN employees e ON tk.employee_id=e.id WHERE DATE_FORMAT(tk.date,'%Y-%m')=:m ORDER BY tk.date DESC, e.first_name");
$records->execute([':m'=>$month]);
$records = $records->fetchAll(PDO::FETCH_ASSOC);

// Summary
$total_hours = 0;
foreach ($records as $r) {
    if ($r['time_in'] && $r['time_out']) {
        $total_hours += (strtotime($r['time_out']) - strtotime($r['time_in'])) / 3600;
    }
}

$active_menu = 'fin_time';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Timekeeping - Pestify Finance</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#27ae60;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.info-bar{display:flex;gap:16px;margin-bottom:20px}
.info-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 22px;flex:1;text-align:center;box-shadow:0 2px 6px rgba(0,0,0,.05)}
.info-card h3{font-size:26px;font-weight:700;color:var(--dark)}
.info-card p{font-size:12px;color:var(--muted);margin-top:3px}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">
<div class="page-header">
    <h1><i class="fas fa-clock"></i> Timekeeping (Finance View)</h1>
    <form method="GET" style="display:flex;gap:10px">
        <input type="month" name="month" value="<?=htmlspecialchars($month)?>" style="padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    </form>
</div>

<div class="info-bar">
    <div class="info-card"><h3><?=count($records)?></h3><p>Total Records</p></div>
    <div class="info-card"><h3><?=round($total_hours,1)?> hrs</h3><p>Total Hours Worked</p></div>
    <div class="info-card"><h3><?=count(array_unique(array_column($records,'employee_id')))?></h3><p>Unique Employees</p></div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-list"></i> Timekeeping Records — <?=date('F Y',strtotime($month.'-01'))?></h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Employee</th><th>Dept</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Method</th></tr></thead>
        <tbody>
        <?php if (empty($records)): ?><tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">No timekeeping records for this period</td></tr><?php endif; ?>
        <?php foreach ($records as $r):
            $hrs = ($r['time_in'] && $r['time_out']) ? round((strtotime($r['time_out'])-strtotime($r['time_in']))/3600,2) : 0;
        ?>
        <tr>
            <td><code style="font-size:11px;background:#f0fdf4;color:#276749;padding:2px 6px;border-radius:5px"><?=$r['employee_code']?></code></td>
            <td style="font-weight:600"><?=htmlspecialchars($r['first_name'].' '.$r['last_name'])?></td>
            <td style="font-size:12px;color:var(--muted)"><?=ucfirst($r['department']??'—')?></td>
            <td style="font-size:12px"><?=date('M d, Y',strtotime($r['date']))?> <span style="color:var(--muted)"><?=date('D',strtotime($r['date']))?></span></td>
            <td style="color:#27ae60;font-weight:600"><?=$r['time_in']?date('h:i A',strtotime($r['time_in'])):'—'?></td>
            <td style="color:#e74c3c"><?=$r['time_out']?date('h:i A',strtotime($r['time_out'])):'—'?></td>
            <td style="font-weight:700"><?=$hrs>0?$hrs.'h':'—'?></td>
            <td><span style="background:<?=$r['method']==='qr'?'#c6f6d5':'#e2e8f0'?>;color:<?=$r['method']==='qr'?'#276749':'#4a5568'?>;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700"><?=strtoupper($r['method']??'manual')?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</div></div>
</body></html>