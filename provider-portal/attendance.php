<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

if (!($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'all')) {
    header('Location: dashboard.php'); exit;
}

require_once 'includes/portal-tier.php';

// Gate entire page for free tier
if (!$tier_is_paid) {
    echo _tierLockedPage('Attendance Monitoring');
    exit;
}

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';

// ── AJAX: Geofence clock in/out ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['geo_action'])) {
    header('Content-Type: application/json');
    // C3: CSRF check for geo clock AJAX
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Session expired. Please refresh the page.']); exit;
    }
    if ($portal_role === 'owner') { echo json_encode(['ok'=>false,'msg'=>'Owner not required to use biometric clock-in.']); exit; }

    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    $action = $_POST['geo_action']; // 'in' or 'out'

    // Load office coords
    try {
        $s = $db->prepare("SELECT office_lat, office_lng FROM providers WHERE id=:p");
        $s->execute([':p' => $pid]);
        $office = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $office = null; }

    if (!$office || !$office['office_lat'] || !$office['office_lng']) {
        echo json_encode(['ok'=>false,'msg'=>'Office location not configured. Ask your owner to set it in Settings > Office Location.']); exit;
    }

    // Haversine formula — distance in km
    $R    = 6371;
    $dLat = deg2rad((float)$office['office_lat'] - $lat);
    $dLng = deg2rad((float)$office['office_lng'] - $lng);
    $a    = sin($dLat/2)**2 + cos(deg2rad($lat)) * cos(deg2rad((float)$office['office_lat'])) * sin($dLng/2)**2;
    $dist = $R * 2 * asin(sqrt($a));

    if ($dist > 10) {
        echo json_encode(['ok'=>false,'msg'=>sprintf('You are %.1f km from the office (limit: 10 km). Move closer and try again.', $dist)]); exit;
    }

    $today = date('Y-m-d');
    $now   = date('H:i:s');
    $staff_id = (int)($_SESSION['portal_staff_id'] ?? 0);

    // Map portal_staff -> employee record
    try {
        $s = $db->prepare("SELECT e.id AS emp_id FROM employees e JOIN provider_staff ps ON ps.email=e.email WHERE ps.id=:sid AND e.provider_id=:pid LIMIT 1");
        $s->execute([':sid' => $staff_id, ':pid' => $pid]);
        $emp = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $emp = null; }

    if (!$emp) {
        echo json_encode(['ok'=>false,'msg'=>'Your staff account is not linked to an employee record. Contact HR.']); exit;
    }
    $eid = (int)$emp['emp_id'];

    try {
        $exists = $db->prepare("SELECT id, time_in, time_out FROM attendance WHERE provider_id=:p AND employee_id=:e AND date=:d");
        $exists->execute([':p'=>$pid,':e'=>$eid,':d'=>$today]);
        $rec = $exists->fetch(PDO::FETCH_ASSOC);

        if ($action === 'in') {
            if ($rec && $rec['time_in']) { echo json_encode(['ok'=>false,'msg'=>'Already clocked in today.']); exit; }
            if ($rec) {
                $db->prepare("UPDATE attendance SET time_in=:ti, time_in_mode='biometric', status='present' WHERE id=:id")->execute([':ti'=>$now,':id'=>$rec['id']]);
            } else {
                $db->prepare("INSERT INTO attendance (provider_id,employee_id,date,time_in,status,time_in_mode) VALUES (:p,:e,:d,:ti,'present','biometric')")
                   ->execute([':p'=>$pid,':e'=>$eid,':d'=>$today,':ti'=>$now]);
            }
            echo json_encode(['ok'=>true,'msg'=>'Clocked in at '.$now.' ('.round($dist,2).' km from office).','time'=>$now]); exit;
        } else { // out
            if (!$rec || !$rec['time_in']) { echo json_encode(['ok'=>false,'msg'=>'No clock-in record for today.']); exit; }
            if ($rec['time_out'])          { echo json_encode(['ok'=>false,'msg'=>'Already clocked out today.']); exit; }
            $db->prepare("UPDATE attendance SET time_out=:to WHERE id=:id")->execute([':to'=>$now,':id'=>$rec['id']]);
            echo json_encode(['ok'=>true,'msg'=>'Clocked out at '.$now.'.','time'=>$now]); exit;
        }
    } catch (Exception $e) {
        error_log('[geo_clock] DB error for provider '.$pid.': '.$e->getMessage());
        echo json_encode(['ok'=>false,'msg'=>'A server error occurred. Please try again or contact HR.']); exit;
    }
}

// Handle manual attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $eid = (int)($_POST['employee_id']??0);
        $date = $_POST['date']??date('Y-m-d');
        $tin = $_POST['time_in']??null;
        $tout = $_POST['time_out']??null;
        $status = $_POST['status']??'present';
        $notes = trim($_POST['notes']??'');
        try {
            $check = $db->prepare("SELECT id FROM attendance WHERE provider_id=:p AND employee_id=:e AND date=:d");
            $check->execute([':p'=>$pid,':e'=>$eid,':d'=>$date]);
            if ($check->fetch()) {
                $db->prepare("UPDATE attendance SET time_in=:ti,time_out=:to,status=:s,notes=:n WHERE provider_id=:p AND employee_id=:e AND date=:d")
                   ->execute([':ti'=>$tin,':to'=>$tout,':s'=>$status,':n'=>$notes,':p'=>$pid,':e'=>$eid,':d'=>$date]);
                $success = "Attendance record updated.";
            } else {
                $db->prepare("INSERT INTO attendance (provider_id,employee_id,date,time_in,time_out,status,notes,time_in_mode) VALUES (:p,:e,:d,:ti,:to,:s,:n,'manual')")
                   ->execute([':p'=>$pid,':e'=>$eid,':d'=>$date,':ti'=>$tin,':to'=>$tout,':s'=>$status,':n'=>$notes]);
                $success = "Attendance recorded successfully.";
            }
        } catch(Exception $ex){ $error = $ex->getMessage(); }
    }
}

$date_filter = $_GET['date'] ?? date('Y-m-d');
$emp_filter = (int)($_GET['emp']??0);

$where = "a.provider_id=:p AND a.date=:d"; $params=[':p'=>$pid,':d'=>$date_filter];
if ($emp_filter) { $where .= " AND a.employee_id=:e"; $params[':e']=$emp_filter; }

$records = safeAll($db,"SELECT a.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.position, e.department, e.employee_id AS employee_code FROM attendance a JOIN employees e ON a.employee_id=e.id WHERE $where ORDER BY a.time_in DESC",$params);
$employees = safeAll($db,"SELECT id,first_name,last_name,employee_id AS employee_code FROM employees WHERE provider_id=:p AND status='active' ORDER BY first_name",[':p'=>$pid]);

$total = count($records);
$present = count(array_filter($records, fn($r)=>$r['status']==='present'));
$late = count(array_filter($records, fn($r)=>$r['status']==='late'));
$absent = count(array_filter($records, fn($r)=>$r['status']==='absent'));

$active_menu='attendance';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;padding:16px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:42px;height:42px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.stat-info h3{font-size:20px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
.filters input,.filters select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-green{background:#c6f6d5;color:#276749}
.badge-orange{background:#feebc8;color:#c05621}
.badge-red{background:#fed7d7;color:#c53030}
.badge-gray{background:#e2e8f0;color:#4a5568}
.badge-purple{background:#f0e6ff;color:#6b46c1}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <div>
        <h1><i class="fas fa-calendar-check"></i> Attendance</h1>
        <p>Showing records for <?= date('F j, Y', strtotime($date_filter)) ?></p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-plus"></i> Log Attendance</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<!-- Geofence Biometric Widget — shown to non-owner staff only -->
<?php if ($portal_role !== 'owner'): ?>
<div id="geo-widget" style="background:#fff;border-radius:12px;border:1px solid var(--border);padding:18px 22px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.05)">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#2E8B57,#27ae60);display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0">
            <i class="fas fa-fingerprint"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:700;color:var(--dark)">Biometric Clock-In</div>
            <div id="geo-status" style="font-size:12px;color:var(--muted);margin-top:2px">Click a button below to check your location.</div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">
            <button onclick="geoClock('in')" id="btn-clock-in"
                style="padding:10px 20px;background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:6px;box-shadow:0 3px 10px rgba(46,139,87,.3);transition:all .2s">
                <i class="fas fa-sign-in-alt"></i> Clock In
            </button>
            <button onclick="geoClock('out')" id="btn-clock-out"
                style="padding:10px 20px;background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:6px;box-shadow:0 3px 10px rgba(231,76,60,.3);transition:all .2s">
                <i class="fas fa-sign-out-alt"></i> Clock Out
            </button>
        </div>
    </div>
    <div id="geo-result" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px"></div>
</div>
<script>
window._geoCsrf = <?= json_encode(generateCSRFToken()) ?>;
function geoClock(action) {
    var statusEl = document.getElementById('geo-status');
    var resultEl = document.getElementById('geo-result');
    resultEl.style.display = 'none';
    statusEl.textContent = 'Detecting your location…';

    if (!navigator.geolocation) {
        statusEl.textContent = 'Geolocation not supported by your browser.';
        return;
    }

    document.getElementById('btn-clock-in').disabled = true;
    document.getElementById('btn-clock-out').disabled = true;

    navigator.geolocation.getCurrentPosition(function(pos) {
        statusEl.textContent = 'Verifying with server…';
        var fd = new FormData();
        fd.append('geo_action', action);
        fd.append('lat', pos.coords.latitude);
        fd.append('lng', pos.coords.longitude);
        fd.append('csrf_token', window._geoCsrf || '');

        fetch('attendance.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(data) {
            resultEl.style.display = 'block';
            if (data.ok) {
                resultEl.style.background = '#f0fdf4';
                resultEl.style.border = '1px solid #bbf7d0';
                resultEl.style.color = '#166534';
                resultEl.innerHTML = '<i class="fas fa-circle-check"></i> ' + data.msg;
                statusEl.textContent = 'Biometric clock-' + action + ' recorded.';
                setTimeout(function(){ location.reload(); }, 2000);
            } else {
                resultEl.style.background = '#fef2f2';
                resultEl.style.border = '1px solid #fecaca';
                resultEl.style.color = '#991b1b';
                resultEl.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + data.msg;
                statusEl.textContent = 'Clock-' + action + ' failed.';
            }
            document.getElementById('btn-clock-in').disabled = false;
            document.getElementById('btn-clock-out').disabled = false;
        })
        .catch(function() {
            statusEl.textContent = 'Network error. Try again.';
            document.getElementById('btn-clock-in').disabled = false;
            document.getElementById('btn-clock-out').disabled = false;
        });
    }, function(err) {
        statusEl.textContent = 'Location access denied or unavailable. Please allow location in your browser.';
        document.getElementById('btn-clock-in').disabled = false;
        document.getElementById('btn-clock-out').disabled = false;
    }, {enableHighAccuracy:true, timeout:10000});
}
</script>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-users"></i></div><div class="stat-info"><h3><?= $total ?></h3><p>Total Records</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-check"></i></div><div class="stat-info"><h3><?= $present ?></h3><p>Present</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-clock"></i></div><div class="stat-info"><h3><?= $late ?></h3><p>Late</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-times"></i></div><div class="stat-info"><h3><?= $absent ?></h3><p>Absent</p></div></div>
</div>

<form method="GET" class="filters">
    <input type="date" name="date" value="<?= $date_filter ?>">
    <select name="emp">
        <option value="">All Employees</option>
        <?php foreach($employees as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $emp_filter==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    <a href="attendance.php" class="btn btn-outline">Reset</a>
    <div style="margin-left:auto">
        <a href="attendance.php?date=<?= date('Y-m-d', strtotime($date_filter.' -1 day')) ?>" class="btn btn-outline btn-sm"><i class="fas fa-chevron-left"></i></a>
        <a href="attendance.php?date=<?= date('Y-m-d', strtotime($date_filter.' +1 day')) ?>" class="btn btn-outline btn-sm"><i class="fas fa-chevron-right"></i></a>
    </div>
</form>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>Employee</th><th>Code</th><th>Department</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th><th>Mode</th></tr></thead>
    <tbody>
    <?php if(empty($records)): ?>
    <tr><td colspan="8"><div class="empty-state"><i class="fas fa-calendar-check" style="font-size:36px;opacity:.2;margin-bottom:10px;display:block"></i><p>No attendance records for this date.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach($records as $r): ?>
    <tr>
        <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['position']??'—') ?></small></td>
        <td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 6px;border-radius:4px"><?= htmlspecialchars($r['employee_code']??'—') ?></code></td>
        <td><span class="badge badge-purple"><?= ucfirst($r['department']??'—') ?></span></td>
        <td><?= $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '—' ?></td>
        <td><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
        <td><?= $r['total_hours'] ? number_format($r['total_hours'],1).'h' : '—' ?></td>
        <td><span class="badge <?= $r['status']==='present'?'badge-green':($r['status']==='late'?'badge-orange':($r['status']==='half_day'?'badge-orange':'badge-red')) ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
        <td><span class="badge badge-gray"><?= ucfirst($r['time_in_mode']??'manual') ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Add Attendance Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-calendar-check" style="color:var(--primary)"></i> Log Attendance</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group">
        <label>Employee *</label>
        <select name="employee_id" required>
            <option value="">Select Employee</option>
            <?php foreach($employees as $e): ?>
            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?> (<?= $e['employee_code'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label>Date *</label><input type="date" name="date" value="<?= $date_filter ?>" required></div>
    <div class="form-group"><label>Time In</label><input type="time" name="time_in"></div>
    <div class="form-group"><label>Time Out</label><input type="time" name="time_out"></div>
    <div class="form-group">
        <label>Status *</label>
        <select name="status">
            <option value="present">Present</option>
            <option value="late">Late</option>
            <option value="absent">Absent</option>
            <option value="half_day">Half Day</option>
        </select>
    </div>
    <div class="form-group"><label>Notes</label><textarea name="notes" rows="2" style="resize:vertical"></textarea></div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
    </div>
    </form>
</div>
</div>
<script>
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));
</script>
</body></html>