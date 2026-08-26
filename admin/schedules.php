<?php
// admin/schedules.php
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
$database = new Database(); $db = $database->getConnection();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title = trim($_POST['title']??'');
        $date  = $_POST['schedule_date'] ?? '';
        $start = $_POST['start_time'] ?? null;
        $end   = $_POST['end_time']   ?? null;
        $type  = $_POST['type']       ?? 'work';
        $dept  = trim($_POST['department']??'');
        $emp_id= (int)($_POST['employee_id']??0) ?: null;
        $desc  = trim($_POST['description']??'');
        if (!$title || !$date) { $error = 'Title and date are required.'; }
        else {
            $db->prepare("INSERT INTO schedules (employee_id,department,title,description,schedule_date,start_time,end_time,type,created_by) VALUES(:e,:d,:t,:de,:sd,:st,:et,:ty,:by)")
            ->execute([':e'=>$emp_id,':d'=>$dept,':t'=>$title,':de'=>$desc,':sd'=>$date,':st'=>$start,':et'=>$end,':ty'=>$type,':by'=>$_SESSION['admin_id']]);
            $success = 'Schedule added.';
        }
    } elseif ($action === 'delete') {
        $db->prepare("DELETE FROM schedules WHERE id=:id")->execute([':id'=>(int)$_POST['sched_id']]);
        $success = 'Schedule deleted.';
    }
}

$month = $_GET['month'] ?? date('Y-m');
$scheds = $db->prepare("SELECT s.*, e.first_name, e.last_name FROM schedules s LEFT JOIN employees e ON s.employee_id=e.id WHERE DATE_FORMAT(s.schedule_date,'%Y-%m')=:m ORDER BY s.schedule_date, s.start_time");
$scheds->execute([':m'=>$month]);
$schedules = $scheds->fetchAll(PDO::FETCH_ASSOC);

// Build calendar data
$cal_events = [];
foreach ($schedules as $s) {
    $cal_events[date('j',strtotime($s['schedule_date']))][] = $s;
}

$employees = $db->query("SELECT id,employee_code,first_name,last_name FROM employees WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'schedules';
$month_start = new DateTime($month . '-01');
$days_in_month = (int)$month_start->format('t');
$first_dow = (int)$month_start->format('w');
$type_colors = ['work'=>'#3b82f6','meeting'=>'#f59e0b','training'=>'#9b59b6','event'=>'#27ae60','other'=>'#718096'];
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schedules - Pestify Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}.btn-sm{padding:5px 11px;font-size:12px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#c6f6d5;color:#276749;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
/* Calendar */
.cal-nav{display:flex;align-items:center;gap:12px}
.cal-nav a{text-decoration:none;color:var(--primary);font-size:18px;padding:4px 8px}
.cal-nav h2{font-size:18px;font-weight:700;color:var(--dark);min-width:160px;text-align:center}
.calendar{background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.cal-head{display:grid;grid-template-columns:repeat(7,1fr);background:#f8fafc;border-bottom:2px solid var(--border)}
.cal-head span{padding:10px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted)}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr)}
.cal-cell{min-height:90px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:6px;position:relative}
.cal-cell:nth-child(7n){border-right:none}
.cal-day-num{font-size:12px;font-weight:700;color:var(--muted);margin-bottom:4px}
.cal-cell.today .cal-day-num{background:var(--primary);color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center}
.cal-event{font-size:10px;padding:2px 6px;border-radius:4px;color:#fff;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}
.cal-empty{background:#fafbfc}
/* List card */
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:11px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:20px}.modal-foot{padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
.form-group{margin-bottom:13px}.form-label{display:block;font-size:12px;font-weight:600;color:#2d3748;margin-bottom:4px}
.form-control{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-calendar-alt"></i> Schedules</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')"><i class="fas fa-plus"></i> Add Schedule</button>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?=$success?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?=$error?></div><?php endif; ?>

<div class="top-bar">
    <div class="cal-nav">
        <?php $prev = date('Y-m',strtotime($month.'-01 -1 month')); $next = date('Y-m',strtotime($month.'-01 +1 month')); ?>
        <a href="?month=<?=$prev?>"><i class="fas fa-chevron-left"></i></a>
        <h2><?=$month_start->format('F Y')?></h2>
        <a href="?month=<?=$next?>"><i class="fas fa-chevron-right"></i></a>
    </div>
    <form method="GET"><input type="month" name="month" value="<?=$month?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px"><button type="submit" class="btn btn-primary" style="margin-left:8px"><i class="fas fa-search"></i></button></form>
</div>

<!-- Calendar -->
<div class="calendar">
    <div class="cal-head">
        <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?><span><?=$d?></span><?php endforeach; ?>
    </div>
    <div class="cal-grid">
        <?php for ($i=0;$i<$first_dow;$i++): ?><div class="cal-cell cal-empty"></div><?php endfor; ?>
        <?php for ($d=1;$d<=$days_in_month;$d++):
            $isToday = ($month === date('Y-m') && $d === (int)date('j'));
        ?>
        <div class="cal-cell <?=$isToday?'today':''?>">
            <div class="cal-day-num"><?=$d?></div>
            <?php foreach ($cal_events[$d] ?? [] as $ev): $col = $type_colors[$ev['type']] ?? '#718096'; ?>
            <div class="cal-event" style="background:<?=$col?>" title="<?=htmlspecialchars($ev['title'])?>"><?=htmlspecialchars($ev['title'])?></div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- List View -->
<div class="card">
    <div class="card-header"><h2><i class="fas fa-list"></i> Schedule List</h2><span style="font-size:12px;color:var(--muted)"><?=count($schedules)?> event(s)</span></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Title</th><th>Type</th><th>Employee / Dept</th><th>Date</th><th>Time</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (empty($schedules)): ?><tr><td colspan="6" style="text-align:center;padding:40px;color:var(--muted)">No schedules this month</td></tr><?php endif; ?>
        <?php foreach ($schedules as $s): $col=$type_colors[$s['type']]??'#718096'; ?>
        <tr>
            <td style="font-weight:600"><?=htmlspecialchars($s['title'])?><?php if ($s['description']): ?><div style="font-size:11px;color:var(--muted)"><?=htmlspecialchars(substr($s['description'],0,60))?></div><?php endif; ?></td>
            <td><span style="background:<?=$col?>22;color:<?=$col?>;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700"><?=ucfirst($s['type'])?></span></td>
            <td style="font-size:12px">
                <?php if ($s['first_name']): ?><?=htmlspecialchars($s['first_name'].' '.$s['last_name'])?><?php endif; ?>
                <?php if ($s['department']): ?><span style="color:var(--muted)"><?=$s['first_name']?' · ':''?><?=htmlspecialchars($s['department'])?></span><?php endif; ?>
            </td>
            <td style="font-size:12px"><?=date('M d, Y',strtotime($s['schedule_date']))?></td>
            <td style="font-size:12px;color:var(--muted)"><?=$s['start_time']?date('h:i A',strtotime($s['start_time'])):'—'?><?=$s['end_time']?' – '.date('h:i A',strtotime($s['end_time'])):''?></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="sched_id" value="<?=$s['id']?>">
                    <button class="btn btn-sm" style="background:#fed7d7;color:#c53030" type="submit"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal-box">
    <div class="modal-head"><h3><i class="fas fa-calendar-plus" style="color:var(--primary);margin-right:8px"></i>Add Schedule</h3>
    <button style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required></div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Type</label>
                <select name="type" class="form-control">
                    <option value="work">Work</option><option value="meeting">Meeting</option><option value="training">Training</option><option value="event">Event</option><option value="other">Other</option>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Date *</label><input type="date" name="schedule_date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
        </div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Start Time</label><input type="time" name="start_time" class="form-control" value="08:00"></div>
            <div class="form-group"><label class="form-label">End Time</label><input type="time" name="end_time" class="form-control" value="17:00"></div>
        </div>
        <div class="form-group"><label class="form-label">Department</label><input type="text" name="department" class="form-control" placeholder="e.g. All / Operations"></div>
        <div class="form-group"><label class="form-label">Specific Employee (optional)</label>
            <select name="employee_id" class="form-control">
                <option value="">All / Not specific</option>
                <?php foreach ($employees as $e): ?><option value="<?=$e['id']?>">[<?=$e['employee_code']?>] <?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
    </div></form>
</div>
</div>
<script>document.getElementById('addModal').addEventListener('click',e=>{if(e.target===document.getElementById('addModal'))e.target.classList.remove('open')});</script>
</body></html>