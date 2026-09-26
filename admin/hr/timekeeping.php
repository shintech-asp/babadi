<?php
// admin/hr/timekeeping.php
$require_dept = 'hr';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();

$success = $error = '';
$today = date('Y-m-d');

// ── Manual Time In/Out ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'time_in') {
    $emp_id = (int)$_POST['emp_id'];
    $now    = date('Y-m-d H:i:s');
    // Check if already timed in today. timekeeping's date column is
    // actually named work_date, and it has no method column at all —
    // the manual/qr distinction is tracked on attendance.time_in_mode instead.
    $chk = $db->prepare("SELECT id, time_in, time_out FROM timekeeping WHERE employee_id=:e AND work_date=:d");
    $chk->execute([':e'=>$emp_id,':d'=>$today]);
    $rec = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        $db->prepare("INSERT INTO timekeeping (employee_id,work_date,time_in) VALUES(:e,:d,:t)")->execute([':e'=>$emp_id,':d'=>$today,':t'=>$now]);
        // Attendance record
        $late = (strtotime($now) > strtotime("$today 08:00:00")) ? (int)((strtotime($now)-strtotime("$today 08:00:00"))/60) : 0;
        $status = $late > 0 ? 'late' : 'present';
        $db->prepare("INSERT INTO attendance (employee_id,date,time_in,status,late_minutes,time_in_mode) VALUES(:e,:d,:t,:s,:l,'manual') ON DUPLICATE KEY UPDATE time_in=:t,status=:s,late_minutes=:l,time_in_mode='manual'")->execute([':e'=>$emp_id,':d'=>$today,':t'=>$now,':s'=>$status,':l'=>$late]);
        $success = "Time In recorded.";
    } elseif (!$rec['time_out']) {
        $db->prepare("UPDATE timekeeping SET time_out=:t WHERE employee_id=:e AND work_date=:d")->execute([':t'=>$now,':e'=>$emp_id,':d'=>$today]);
        // OT/Undertime — attendance's real columns are overtime_min/undertime_min.
        $end_sched = strtotime("$today 17:00:00");
        $ot   = max(0,(int)((strtotime($now)-$end_sched)/60));
        $ut   = max(0,(int)(($end_sched-strtotime($now))/60));
        $db->prepare("UPDATE attendance SET time_out=:t,overtime_min=:ot,undertime_min=:ut WHERE employee_id=:e AND date=:d")->execute([':t'=>$now,':ot'=>$ot,':ut'=>$ut,':e'=>$emp_id,':d'=>$today]);
        $success = "Time Out recorded.";
    } else { $error = "Employee already timed in and out today."; }
}

// ── QR Scan time in/out ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'qr_scan') {
    $token  = trim($_POST['qr_token']??'');
    $emp    = $db->prepare("SELECT * FROM employees WHERE qr_token=:t AND status='active'");
    $emp->execute([':t'=>$token]);
    $emp_row = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$emp_row) { $error = "Invalid QR code."; }
    else {
        $now = date('Y-m-d H:i:s');
        $chk = $db->prepare("SELECT id,time_in,time_out FROM timekeeping WHERE employee_id=:e AND work_date=:d");
        $chk->execute([':e'=>$emp_row['id'],':d'=>$today]);
        $rec = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            $db->prepare("INSERT INTO timekeeping (employee_id,work_date,time_in) VALUES(:e,:d,:t)")->execute([':e'=>$emp_row['id'],':d'=>$today,':t'=>$now]);
            $late   = (strtotime($now) > strtotime("$today 08:00:00")) ? (int)((strtotime($now)-strtotime("$today 08:00:00"))/60) : 0;
            $status = $late > 0 ? 'late' : 'present';
            $db->prepare("INSERT INTO attendance (employee_id,date,time_in,status,late_minutes,time_in_mode) VALUES(:e,:d,:t,:s,:l,'qr') ON DUPLICATE KEY UPDATE time_in=:t,status=:s,late_minutes=:l,time_in_mode='qr'")->execute([':e'=>$emp_row['id'],':d'=>$today,':t'=>$now,':s'=>$status,':l'=>$late]);
            $success = "✅ Time In: " . $emp_row['first_name'] . " " . $emp_row['last_name'] . " — " . date('h:i A');
        } elseif (!$rec['time_out']) {
            $db->prepare("UPDATE timekeeping SET time_out=:t WHERE employee_id=:e AND work_date=:d")->execute([':t'=>$now,':e'=>$emp_row['id'],':d'=>$today]);
            $success = "✅ Time Out: " . $emp_row['first_name'] . " " . $emp_row['last_name'] . " — " . date('h:i A');
        } else { $error = $emp_row['first_name'] . " already completed timekeeping today."; }
    }
}

// ── Today's records ───────────────────────────────────────────
// employees has no employee_code column — employee_id is the real code column.
// timekeeping's date column is actually named work_date.
$records = $db->prepare("SELECT tk.*, e.first_name, e.last_name, e.employee_id AS employee_code, e.position, e.department FROM timekeeping tk JOIN employees e ON tk.employee_id=e.id WHERE tk.work_date=:d ORDER BY tk.time_in DESC");
$records->execute([':d'=>$today]);
$today_records = $records->fetchAll(PDO::FETCH_ASSOC);

$employees = $db->query("SELECT id,employee_id AS employee_code,first_name,last_name,qr_token FROM employees WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'hr_time';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Timekeeping - Pestify HR</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border)}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
.card-body{padding:20px 22px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{background:#8e44ad}
.btn-green{background:#27ae60;color:#fff}.btn-green:hover{background:#219a52}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#e9d8fd;color:#6b46c1;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.form-control{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;margin-bottom:12px}
.form-control:focus{outline:none;border-color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-present{background:#c6f6d5;color:#276749}.b-late{background:#feebc8;color:#c05621}
.qr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;padding:4px}
.qr-item{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}
.qr-item h4{font-size:12px;font-weight:700;margin-top:8px;color:var(--dark)}
.qr-item p{font-size:10px;color:var(--muted)}
/* QR scanner input */
.qr-input-wrap{position:relative}
.qr-input-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:16px}
.qr-input{width:100%;padding:12px 14px 12px 38px;border:2px solid var(--border);border-radius:10px;font-size:15px;font-family:inherit}
.qr-input:focus{outline:none;border-color:var(--primary)}
@media(max-width:900px){.grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-qrcode"></i> Timekeeping</h1>
    <span style="font-size:13px;color:var(--muted)"><?= date('l, F j, Y') ?></span>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div><?php endif; ?>

<div class="grid-2">
    <!-- QR Scanner -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-camera"></i> QR Scan Time In/Out</h2></div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--muted);margin-bottom:14px">Have the employee scan their QR code, or paste the QR token below:</p>
            <form method="POST">
                <input type="hidden" name="action" value="qr_scan">
                <div class="qr-input-wrap">
                    <i class="fas fa-qrcode"></i>
                    <input type="text" name="qr_token" class="qr-input" id="qrInput" placeholder="Scan or paste QR token here..." autofocus autocomplete="off">
                </div>
                <button type="submit" class="btn btn-green" style="margin-top:10px;width:100%"><i class="fas fa-check"></i> Record Time</button>
            </form>
        </div>
    </div>

    <!-- Manual Entry -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-hand-pointer"></i> Manual Time In/Out</h2></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="time_in">
                <label style="font-size:12px;font-weight:600;color:#2d3748;display:block;margin-bottom:6px">Select Employee</label>
                <select name="emp_id" class="form-control" required>
                    <option value="">Choose employee...</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?=$e['id']?>">[<?=$e['employee_code']?>] <?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary" style="width:100%"><i class="fas fa-clock"></i> Record Time In / Time Out</button>
            </form>
        </div>
    </div>
</div>

<!-- Today's Log -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header"><h2><i class="fas fa-list"></i> Today's Timekeeping Log — <?= date('F d, Y') ?></h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Employee</th><th>Dept</th><th>Time In</th><th>Time Out</th><th>Method</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($today_records)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">No timekeeping records yet today</td></tr>
        <?php endif; ?>
        <?php foreach ($today_records as $r): ?>
        <tr>
            <td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 6px;border-radius:5px"><?=$r['employee_code']?></code></td>
            <td><strong><?=htmlspecialchars($r['first_name'].' '.$r['last_name'])?></strong><br><span style="font-size:11px;color:var(--muted)"><?=htmlspecialchars($r['position']??'')?></span></td>
            <td style="font-size:12px"><?=ucfirst($r['department']??'—')?></td>
            <td style="color:#27ae60;font-weight:600"><?=$r['time_in']?date('h:i A',strtotime($r['time_in'])):'—'?></td>
            <td style="color:#e74c3c;font-weight:600"><?=$r['time_out']?date('h:i A',strtotime($r['time_out'])):'<span style="color:var(--muted)">Not yet</span>'?></td>
            <?php
            // timekeeping has no method column — the manual/qr distinction lives
            // on the matching attendance row's time_in_mode instead.
            $att=$db->prepare("SELECT status, time_in_mode FROM attendance WHERE employee_id=:e AND date=:d");$att->execute([':e'=>$r['employee_id'],':d'=>$today]);$arow=$att->fetch(PDO::FETCH_ASSOC);
            $method = $arow['time_in_mode'] ?? 'manual';
            ?>
            <td><span style="background:<?=$method==='qr'?'#e9d8fd':'#e2e8f0'?>;color:<?=$method==='qr'?'#6b46c1':'#4a5568'?>;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700"><?=strtoupper($method)?></span></td>
            <td>
                <span class="badge b-<?=$arow['status']??'present'?>"><?=ucfirst($arow['status']??'Present')?></span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- QR Code Generator -->
<div class="card">
    <div class="card-header"><h2><i class="fas fa-qrcode"></i> Employee QR Codes</h2></div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Print and distribute these QR codes to employees for scanning.</p>
        <div class="qr-grid" id="qrGrid"></div>
    </div>
</div>

</div></div>
<script>
// Auto-submit QR input after scan (QR scanners typically append Enter)
document.getElementById('qrInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); this.closest('form').submit(); }
});

// Generate QR codes
const employees = <?= json_encode(array_map(fn($e)=>['code'=>$e['employee_code'],'name'=>$e['first_name'].' '.$e['last_name'],'token'=>$e['qr_token']], $employees)) ?>;
const grid = document.getElementById('qrGrid');
employees.forEach(emp => {
    const div = document.createElement('div');
    div.className = 'qr-item';
    const qrDiv = document.createElement('div');
    qrDiv.id = 'qr_' + emp.code;
    div.appendChild(qrDiv);
    const h4 = document.createElement('h4');
    h4.textContent = emp.name;
    const p = document.createElement('p');
    p.textContent = emp.code;
    div.appendChild(h4);
    div.appendChild(p);
    grid.appendChild(div);
    new QRCode(qrDiv, { text: emp.token, width: 110, height: 110, correctLevel: QRCode.CorrectLevel.M });
});
</script>
</body></html>