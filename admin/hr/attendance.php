<?php
// admin/hr/attendance.php
$require_dept = 'hr';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();

$success = $error = '';
$today = date('Y-m-d');

// ── Manual attendance entry ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'mark') {
    $emp_id = (int)$_POST['emp_id'];
    $date   = $_POST['att_date'] ?? $today;
    $status = $_POST['status'] ?? 'present';
    $notes  = trim($_POST['notes']??'');
    $db->prepare("INSERT INTO attendance (employee_id,date,status,notes) VALUES(:e,:d,:s,:n) ON DUPLICATE KEY UPDATE status=:s,notes=:n")
       ->execute([':e'=>$emp_id,':d'=>$date,':s'=>$status,':n'=>$notes]);
    $success = 'Attendance marked.';
}

// ── Filters ───────────────────────────────────────────────────
$view_date  = $_GET['date']   ?? $today;
$emp_filter = (int)($_GET['emp_id'] ?? 0);
$view_mode  = $_GET['view']   ?? 'daily'; // daily | employee

// ── Daily view: all employees for a date ─────────────────────
$daily_att = [];
if ($view_mode === 'daily') {
    $sql = "SELECT a.*, e.first_name, e.last_name, e.employee_code, e.position, e.department
            FROM attendance a JOIN employees e ON a.employee_id=e.id
            WHERE a.date=:d";
    $params = [':d'=>$view_date];
    if ($emp_filter) { $sql .= " AND a.employee_id=:e"; $params[':e']=$emp_filter; }
    $sql .= " ORDER BY e.first_name";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $daily_att = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Employee monthly view ─────────────────────────────────────
$emp_month_att = [];
$emp_stats     = [];
if ($view_mode === 'employee' && $emp_filter) {
    $month = $_GET['month'] ?? date('Y-m');
    $stmt  = $db->prepare("SELECT * FROM attendance WHERE employee_id=:e AND DATE_FORMAT(date,'%Y-%m')=:m ORDER BY date");
    $stmt->execute([':e'=>$emp_filter,':m'=>$month]);
    $emp_month_att = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $emp_stats = [
        'present'   => count(array_filter($emp_month_att, fn($r)=>$r['status']==='present')),
        'late'      => count(array_filter($emp_month_att, fn($r)=>$r['status']==='late')),
        'absent'    => count(array_filter($emp_month_att, fn($r)=>$r['status']==='absent')),
        'on_leave'  => count(array_filter($emp_month_att, fn($r)=>$r['status']==='on_leave')),
        'late_mins' => array_sum(array_column($emp_month_att,'late_minutes')),
        'ot_mins'   => array_sum(array_column($emp_month_att,'overtime_minutes')),
    ];
}

$employees = $db->query("SELECT id,employee_code,first_name,last_name FROM employees WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// Employees not yet marked today (for quick mark)
$marked_ids = array_column($daily_att, 'employee_id');
$unmarked   = array_filter($employees, fn($e) => !in_array($e['id'], $marked_ids));

$active_menu = 'attendance';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance - Pestify HR</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.tabs{display:flex;gap:0;margin-bottom:20px;border:1.5px solid var(--border);border-radius:10px;overflow:hidden;width:fit-content}
.tab-btn{padding:10px 22px;border:none;background:#fff;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;color:var(--muted);transition:all .2s}
.tab-btn.active{background:var(--primary);color:#fff}
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
.filters input,.filters select{padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}.btn-sm{padding:5px 11px;font-size:12px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#e9d8fd;color:#6b46c1;border-left:4px solid var(--primary)}
.stats-bar{display:flex;gap:14px;margin-bottom:20px;flex-wrap:wrap}
.stat-pill{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 18px;text-align:center;min-width:90px}
.stat-pill h3{font-size:22px;font-weight:700}
.stat-pill p{font-size:11px;color:var(--muted);margin-top:2px}
.s-green h3{color:#27ae60}.s-orange h3{color:#e67e22}.s-red h3{color:#e74c3c}.s-blue h3{color:#3498db}.s-purple h3{color:#9b59b6}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:11px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.att-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.ab-present{background:#c6f6d5;color:#276749}.ab-late{background:#feebc8;color:#c05621}
.ab-absent{background:#fed7d7;color:#c53030}.ab-on_leave{background:#bee3f8;color:#2b6cb0}
.ab-undertime{background:#e9d8fd;color:#6b46c1}.ab-overtime{background:#c6f6d5;color:#276749}
.ab-half_day{background:#fefcbf;color:#744210}
select.status-select{padding:5px 8px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;font-family:inherit}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-user-clock"></i> Attendance</h1>
    <a href="timekeeping.php" class="btn btn-primary"><i class="fas fa-qrcode"></i> Go to Timekeeping</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?=$success?></div><?php endif; ?>

<!-- View Tabs -->
<div class="tabs">
    <a href="?view=daily&date=<?=$view_date?>" class="tab-btn <?=$view_mode==='daily'?'active':''?>"><i class="fas fa-calendar-day"></i> Daily View</a>
    <a href="?view=employee&emp_id=<?=$emp_filter?>&month=<?=date('Y-m')?>" class="tab-btn <?=$view_mode==='employee'?'active':''?>"><i class="fas fa-user"></i> Per Employee</a>
</div>

<?php if ($view_mode === 'daily'): ?>
<!-- Daily View -->
<form method="GET" class="filters">
    <input type="hidden" name="view" value="daily">
    <input type="date" name="date" value="<?=htmlspecialchars($view_date)?>">
    <select name="emp_id">
        <option value="">All Employees</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?=$e['id']?>" <?=$emp_filter==$e['id']?'selected':''?>>[<?=$e['employee_code']?>] <?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
</form>

<!-- Quick mark unmarked employees -->
<?php if (!empty($unmarked) && $view_date === $today): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header"><h2><i class="fas fa-exclamation-circle"></i> <?=count($unmarked)?> Employee(s) Not Yet Marked Today</h2></div>
    <div style="padding:16px 20px">
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="action" value="mark">
        <input type="hidden" name="att_date" value="<?=$today?>">
        <select name="emp_id" class="filters" style="border:1.5px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit">
            <?php foreach ($unmarked as $e): ?>
            <option value="<?=$e['id']?>">[<?=$e['employee_code']?>] <?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" style="border:1.5px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit">
            <option value="present">Present</option><option value="absent">Absent</option>
            <option value="late">Late</option><option value="on_leave">On Leave</option><option value="half_day">Half Day</option>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Mark</button>
    </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-list"></i> Attendance — <?=date('F d, Y',strtotime($view_date))?></h2>
        <span style="font-size:12px;color:var(--muted)"><?=count($daily_att)?> record(s)</span>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Employee</th><th>Dept</th><th>Time In</th><th>Time Out</th><th>Late (min)</th><th>OT (min)</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($daily_att)): ?><tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">No attendance records for this date</td></tr><?php endif; ?>
        <?php foreach ($daily_att as $r): ?>
        <tr>
            <td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 6px;border-radius:5px"><?=$r['employee_code']?></code></td>
            <td><strong><?=htmlspecialchars($r['first_name'].' '.$r['last_name'])?></strong></td>
            <td style="font-size:12px;color:var(--muted)"><?=ucfirst($r['department']??'—')?></td>
            <td style="color:#27ae60;font-weight:600"><?=$r['time_in']?date('h:i A',strtotime($r['time_in'])):'—'?></td>
            <td style="color:#e74c3c"><?=$r['time_out']?date('h:i A',strtotime($r['time_out'])):'—'?></td>
            <td style="color:<?=$r['late_minutes']>0?'#e67e22':'var(--muted)'?>"><?=$r['late_minutes']?:0?></td>
            <td style="color:<?=$r['overtime_minutes']>0?'#27ae60':'var(--muted)'?>"><?=$r['overtime_minutes']?:0?></td>
            <td><span class="att-badge ab-<?=$r['status']?>"><?=ucfirst(str_replace('_',' ',$r['status']))?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php else: ?>
<!-- Per Employee View -->
<form method="GET" class="filters">
    <input type="hidden" name="view" value="employee">
    <select name="emp_id" required>
        <option value="">Select Employee...</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?=$e['id']?>" <?=$emp_filter==$e['id']?'selected':''?>>[<?=$e['employee_code']?>] <?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?></option>
        <?php endforeach; ?>
    </select>
    <input type="month" name="month" value="<?=$_GET['month']??date('Y-m')?>">
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> View</button>
</form>

<?php if ($emp_filter && !empty($emp_month_att)): ?>
<div class="stats-bar">
    <div class="stat-pill s-green"><h3><?=$emp_stats['present']?></h3><p>Present</p></div>
    <div class="stat-pill s-orange"><h3><?=$emp_stats['late']?></h3><p>Late</p></div>
    <div class="stat-pill s-red"><h3><?=$emp_stats['absent']?></h3><p>Absent</p></div>
    <div class="stat-pill s-blue"><h3><?=$emp_stats['on_leave']?></h3><p>On Leave</p></div>
    <div class="stat-pill s-orange"><h3><?=$emp_stats['late_mins']?></h3><p>Late Mins</p></div>
    <div class="stat-pill s-purple"><h3><?=$emp_stats['ot_mins']?></h3><p>OT Mins</p></div>
</div>
<div class="card">
    <div class="card-header"><h2><i class="fas fa-calendar"></i> Monthly Attendance</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Date</th><th>Day</th><th>Time In</th><th>Time Out</th><th>Late (min)</th><th>OT (min)</th><th>Status</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($emp_month_att as $r): ?>
        <tr>
            <td><?=date('M d, Y',strtotime($r['date']))?></td>
            <td style="color:var(--muted);font-size:12px"><?=date('D',strtotime($r['date']))?></td>
            <td style="color:#27ae60"><?=$r['time_in']?date('h:i A',strtotime($r['time_in'])):'—'?></td>
            <td style="color:#e74c3c"><?=$r['time_out']?date('h:i A',strtotime($r['time_out'])):'—'?></td>
            <td><?=$r['late_minutes']?:0?></td>
            <td><?=$r['overtime_minutes']?:0?></td>
            <td><span class="att-badge ab-<?=$r['status']?>"><?=ucfirst(str_replace('_',' ',$r['status']))?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($r['notes']??'')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php elseif ($emp_filter): ?>
<div style="text-align:center;padding:60px;color:var(--muted)"><i class="fas fa-calendar-times" style="font-size:40px;display:block;margin-bottom:12px;opacity:.3"></i>No attendance records for this period</div>
<?php endif; ?>
<?php endif; ?>

</div></div>
</body></html>  