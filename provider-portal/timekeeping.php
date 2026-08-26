<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Timekeeping'); exit; }

// ── Timezone fix ─────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

$is_hr    = ($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'all');
$is_emp   = ($_SESSION['portal_account_type'] ?? 'staff') === 'employee';

// Both employees AND portal staff (finance/HR managers) can use the clock
// portal_employee_id is set for employees directly, and also for staff who have a matching employees record
$self_emp_id = (int)($_SESSION['portal_employee_id'] ?? 0);

// If neither HR/owner nor an employee with a record, block
if (!$is_hr && !$is_emp && !$self_emp_id) { header('Location: dashboard.php'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeRow($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){return null;}}

// ── Fetch default work schedule ──────────────────────────────────────
$schedule = safeRow($db,
    "SELECT * FROM hr_work_schedules WHERE (provider_id=:p OR provider_id IS NULL) AND is_default=1 ORDER BY provider_id DESC LIMIT 1",
    [':p'=>$pid]
);

function getScheduleForDate($schedule, $date) {
    if (!$schedule) return null;
    $map = ['Sun'=>'sun','Mon'=>'mon','Tue'=>'tue','Wed'=>'wed','Thu'=>'thu','Fri'=>'fri','Sat'=>'sat'];
    $key = $map[date('D', strtotime($date))] ?? null;
    if (!$key) return null;
    $start = $schedule[$key.'_start'] ?? null;
    $end   = $schedule[$key.'_end']   ?? null;
    if (!$start || !$end) return null;
    return ['start'=>$start,'end'=>$end,'grace'=>(int)($schedule['grace_period']??15)];
}

function calcTimings($time_in, $time_out, $sched) {
    $r = ['late_min'=>0,'undertime_min'=>0,'overtime_min'=>0,'hours_worked'=>0,'regular_hours'=>0,'overtime_hours'=>0,'status'=>'present'];
    if (!$time_in) return $r;
    $ti_ts = strtotime($time_in);
    $to_ts = $time_out ? strtotime($time_out) : null;
    if ($sched) {
        $ss   = strtotime($sched['start']);
        $se   = strtotime($sched['end']);
        $grace= $sched['grace'] * 60;
        $sh   = ($se - $ss) / 3600;
        if ($ti_ts > $ss + $grace) { $r['late_min'] = (int)(($ti_ts - $ss)/60); $r['status'] = 'late'; }
        if ($to_ts) {
            $h = max(0, ($to_ts - $ti_ts) / 3600);
            $r['hours_worked'] = round($h, 2);
            if ($to_ts < $se) $r['undertime_min'] = (int)(($se - $to_ts)/60);
            if ($to_ts > $se) { $r['overtime_min'] = (int)(($to_ts-$se)/60); $r['overtime_hours'] = round(($to_ts-$se)/3600,2); }
            $r['regular_hours'] = round(min($h, $sh), 2);
        }
    } else {
        if ($to_ts) {
            $h = max(0, ($to_ts - $ti_ts)/3600);
            $r['hours_worked']   = round($h,2);
            $r['overtime_hours'] = round(max(0,$h-8),2);
            $r['regular_hours']  = round(min($h,8),2);
            if ($r['overtime_hours']>0) $r['overtime_min']=(int)($r['overtime_hours']*60);
        }
    }
    if ($r['late_min']>0 && $r['undertime_min']>0) $r['status']='half_day';
    return $r;
}

// ── POST handlers ────────────────────────────────────────────────────
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Self Time-In ──
    if ($action === 'timein' && $self_emp_id) {
        $today = date('Y-m-d');
        $now   = date('H:i:s');
        $existing = safeRow($db,
            "SELECT id, time_in FROM timekeeping WHERE provider_id=:p AND employee_id=:e AND work_date=:d",
            [':p'=>$pid,':e'=>$self_emp_id,':d'=>$today]
        );
        if ($existing && $existing['time_in']) {
            $error = "You already timed in today at " . date('h:i A', strtotime($existing['time_in'])) . ".";
        } else {
            $sched = getScheduleForDate($schedule, $today);
            // ── Working hours restriction ──────────────────
            if ($sched) {
                $now_ts       = strtotime($now);
                $sched_start  = strtotime($sched['start']);
                $sched_end    = strtotime($sched['end']);
                $two_hrs_before = $sched_start - (2 * 3600); // allow 2h early
                $two_hrs_after  = $sched_end   + (2 * 3600); // allow 2h late
                if ($now_ts < $two_hrs_before || $now_ts > $two_hrs_after) {
                    $error = "Time-in is only allowed between " . date('h:i A', $two_hrs_before) . " and " . date('h:i A', $two_hrs_after) . " (2 hours before/after your shift).";
                }
            }
            if (!$error) {
            $late = 0; $status = 'present';
            if ($sched) {
                $ss = strtotime($sched['start']);
                $grace = $sched['grace'] * 60;
                if (strtotime($now) > $ss + $grace) { $late = (int)((strtotime($now)-$ss)/60); $status = 'late'; }
            }
            try {
                $db->prepare("INSERT INTO timekeeping (provider_id,employee_id,work_date,time_in,late_minutes,notes,created_at)
                              VALUES (:p,:e,:d,:ti,:late,'Self time-in',NOW())
                              ON DUPLICATE KEY UPDATE time_in=:ti, late_minutes=:late")
                   ->execute([':p'=>$pid,':e'=>$self_emp_id,':d'=>$today,':ti'=>$now,':late'=>$late]);
                $db->prepare("INSERT INTO attendance (provider_id,employee_id,date,time_in,late_minutes,status,time_in_mode,created_at)
                              VALUES (:p,:e,:d,:ti,:late,:st,'system',NOW())
                              ON DUPLICATE KEY UPDATE time_in=:ti, late_minutes=:late, status=:st")
                   ->execute([':p'=>$pid,':e'=>$self_emp_id,':d'=>$today,':ti'=>$now,':late'=>$late,':st'=>$status]);
                $success = "Time-in recorded at <strong>" . date('h:i A') . "</strong>" .
                    ($late > 0 ? " &mdash; <span style='color:#d97706'>{$late} min late</span>"
                               : " &mdash; <span style='color:#16a34a'>On time ✓</span>");
            } catch(Exception $ex){ $error = $ex->getMessage(); }
            } // end working hours check
        }
    }

    // ── Self Time-Out ──
    elseif ($action === 'timeout' && $self_emp_id) {
        $today = date('Y-m-d');
        $now   = date('H:i:s');
        $existing = safeRow($db,
            "SELECT id, time_in, time_out FROM timekeeping WHERE provider_id=:p AND employee_id=:e AND work_date=:d",
            [':p'=>$pid,':e'=>$self_emp_id,':d'=>$today]
        );
        if (!$existing || !$existing['time_in']) {
            $error = "You haven't timed in yet today.";
        } elseif ($existing['time_out']) {
            $error = "You already timed out today at " . date('h:i A', strtotime($existing['time_out'])) . ".";
        } else {
            $sched = getScheduleForDate($schedule, $today);
            // ── Working hours restriction ──────────────────
            if ($sched) {
                $now_ts      = strtotime($now);
                $sched_end   = strtotime($sched['end']);
                $two_hrs_after = $sched_end + (2 * 3600);
                if ($now_ts > $two_hrs_after) {
                    $error = "Time-out window has passed. Please ask your HR manager to log your time manually.";
                }
            }
            if (!$error) {
            $timings = calcTimings($existing['time_in'], $now, $sched);
            try {
                $db->prepare("UPDATE timekeeping SET time_out=:to, hours_worked=:h, overtime_hours=:ot, regular_hours=:reg
                              WHERE provider_id=:p AND employee_id=:e AND work_date=:d")
                   ->execute([':to'=>$now,':h'=>$timings['hours_worked'],':ot'=>$timings['overtime_hours'],':reg'=>$timings['regular_hours'],':p'=>$pid,':e'=>$self_emp_id,':d'=>$today]);
                $db->prepare("UPDATE attendance SET time_out=:to, undertime_min=:ut, overtime_min=:otm, total_hours=:h, status=:st
                              WHERE provider_id=:p AND employee_id=:e AND date=:d")
                   ->execute([':to'=>$now,':ut'=>$timings['undertime_min'],':otm'=>$timings['overtime_min'],':h'=>$timings['hours_worked'],':st'=>$timings['status'],':p'=>$pid,':e'=>$self_emp_id,':d'=>$today]);

                if ($timings['overtime_min'] > 0)
                    $label = "<span style='color:#7c3aed'>+" . gmdate('G:i', $timings['overtime_min']*60) . " overtime</span>";
                elseif ($timings['undertime_min'] > 0)
                    $label = "<span style='color:#d97706'>" . gmdate('G:i', $timings['undertime_min']*60) . " undertime</span>";
                else
                    $label = "<span style='color:#16a34a'>On time ✓</span>";
                $success = "Time-out recorded at <strong>" . date('h:i A') . "</strong> &mdash; $label";
            } catch(Exception $ex){ $error = $ex->getMessage(); }
            } // end working hours check
        }
    }

    // ── HR: Manual Add/Edit ──
    elseif ($action === 'add' && $is_hr) {
        $eid   = (int)($_POST['employee_id']??0);
        $date  = $_POST['work_date']??date('Y-m-d');
        $ti    = $_POST['time_in']  ?: null;
        $to    = $_POST['time_out'] ?: null;
        $notes = trim($_POST['notes']??'');
        $sched   = getScheduleForDate($schedule, $date);
        $timings = calcTimings($ti, $to, $sched);
        try {
            $db->prepare("INSERT INTO timekeeping (provider_id,employee_id,work_date,time_in,time_out,hours_worked,overtime_hours,regular_hours,late_minutes,notes,created_at)
                          VALUES (:p,:e,:d,:ti,:to,:h,:ot,:reg,:late,:n,NOW())
                          ON DUPLICATE KEY UPDATE time_in=:ti,time_out=:to,hours_worked=:h,overtime_hours=:ot,regular_hours=:reg,late_minutes=:late,notes=:n")
               ->execute([':p'=>$pid,':e'=>$eid,':d'=>$date,':ti'=>$ti,':to'=>$to,':h'=>$timings['hours_worked'],':ot'=>$timings['overtime_hours'],':reg'=>$timings['regular_hours'],':late'=>$timings['late_min'],':n'=>$notes]);
            $db->prepare("INSERT INTO attendance (provider_id,employee_id,date,time_in,time_out,late_minutes,undertime_min,overtime_min,total_hours,status,time_in_mode,created_at)
                          VALUES (:p,:e,:d,:ti,:to,:late,:ut,:otm,:h,:st,'manual',NOW())
                          ON DUPLICATE KEY UPDATE time_in=:ti,time_out=:to,late_minutes=:late,undertime_min=:ut,overtime_min=:otm,total_hours=:h,status=:st")
               ->execute([':p'=>$pid,':e'=>$eid,':d'=>$date,':ti'=>$ti,':to'=>$to,':late'=>$timings['late_min'],':ut'=>$timings['undertime_min'],':otm'=>$timings['overtime_min'],':h'=>$timings['hours_worked'],':st'=>$timings['status']]);
            $success = "Timekeeping record saved.";
        } catch(Exception $ex){ $error = $ex->getMessage(); }
    }
}

// ── Fetch records ────────────────────────────────────────────────────
$month_filter = $_GET['month'] ?? date('Y-m');
$emp_filter   = (int)($_GET['emp'] ?? 0);

$base_select = "SELECT t.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code, e.department,
                a.late_minutes AS att_late, a.undertime_min, a.overtime_min, a.status AS att_status
         FROM timekeeping t
         JOIN employees e ON t.employee_id=e.id
         LEFT JOIN attendance a ON a.employee_id=t.employee_id AND a.date=t.work_date AND a.provider_id=t.provider_id";

if ($is_emp && !$is_hr) {
    $records = safeAll($db, "$base_select WHERE t.provider_id=:p AND t.employee_id=:e AND DATE_FORMAT(t.work_date,'%Y-%m')=:m ORDER BY t.work_date DESC",
        [':p'=>$pid,':e'=>$self_emp_id,':m'=>$month_filter]);
} else {
    $where  = "t.provider_id=:p AND DATE_FORMAT(t.work_date,'%Y-%m')=:m";
    $params = [':p'=>$pid,':m'=>$month_filter];
    if ($emp_filter) { $where .= " AND t.employee_id=:e"; $params[':e']=$emp_filter; }
    $records = safeAll($db, "$base_select WHERE $where ORDER BY t.work_date DESC, e.first_name", $params);
}

$employees = $is_hr ? safeAll($db,
    "SELECT id,first_name,last_name,employee_id FROM employees WHERE provider_id=:p AND status='active' ORDER BY first_name",
    [':p'=>$pid]) : [];

$today_record = $self_emp_id ? safeRow($db,
    "SELECT * FROM timekeeping WHERE provider_id=:p AND employee_id=:e AND work_date=:d",
    [':p'=>$pid,':e'=>$self_emp_id,':d'=>date('Y-m-d')]) : null;

$total_hours = array_sum(array_column($records,'hours_worked'));
$total_ot    = array_sum(array_column($records,'overtime_hours'));
$total_late  = array_sum(array_column($records,'late_minutes'));

$active_menu = 'timekeeping';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Timekeeping · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
<style>
:root{
    --primary:#9b59b6;--primary-dim:rgba(155,89,182,.12);
    --dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096;
    --green:#16a34a;--green-dim:rgba(22,163,74,.1);
    --orange:#d97706;--orange-dim:rgba(217,119,6,.1);
    --red:#dc2626;--red-dim:rgba(220,38,38,.1);
    --blue:#2563eb;--blue-dim:rgba(37,99,235,.1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}

.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}

/* ── Clock panel ── */
.clock-panel{background:var(--white);border-radius:16px;border:1px solid var(--border);box-shadow:0 4px 20px rgba(0,0,0,.08);margin-bottom:24px;overflow:hidden}
.clock-header{background:linear-gradient(135deg,#5b21b6,#9b59b6);padding:18px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.clock-header h2{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;opacity:.95}
.live-time{font-size:26px;font-weight:700;color:#fff;letter-spacing:2px;font-variant-numeric:tabular-nums}
.clock-body{padding:22px 28px;display:flex;align-items:center;gap:24px;flex-wrap:wrap}
.clock-col{min-width:130px}
.clock-col-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:5px}
.clock-col-val{font-size:15px;font-weight:600;color:var(--dark)}
.clock-col-val.good{color:var(--green)}
.clock-col-val.warn{color:var(--orange)}
.clock-col-val.bad {color:var(--red)}
.clock-actions{margin-left:auto;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn-timein {background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s}
.btn-timein:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(22,163,74,.3)}
.btn-timeout{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border:none;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s}
.btn-timeout:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(220,38,38,.3)}
.btn-done{background:#e2e8f0;color:#94a3b8;border:none;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;font-family:inherit;display:flex;align-items:center;gap:8px;cursor:default}

/* ── Stats ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.stat-card{background:#fff;padding:16px 18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:42px;height:42px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;flex-shrink:0}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.si-green{background:linear-gradient(135deg,#27ae60,#1e8449)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.stat-info h3{font-size:19px;font-weight:700;color:var(--dark);line-height:1}
.stat-info p{font-size:11px;color:var(--muted);margin-top:2px}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{filter:brightness(1.1)}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}

/* ── Alert ── */
.alert{padding:13px 16px;border-radius:10px;margin-bottom:16px;font-size:13.5px;display:flex;align-items:flex-start;gap:9px;line-height:1.5;animation:slideIn .25s ease}
@keyframes slideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.alert i{flex-shrink:0;margin-top:1px}
.alert-success{background:var(--green-dim);border:1px solid rgba(22,163,74,.22);color:#14532d}
.alert-error  {background:var(--red-dim);  border:1px solid rgba(220,38,38,.22);  color:#7f1d1d}

/* ── Schedule badge ── */
.sched-badge{background:#f6f0ff;border:1px solid #d8b4fe;border-radius:10px;padding:10px 16px;font-size:12.5px;color:#5b21b6;margin-bottom:20px;display:flex;align-items:center;gap:8px}

/* ── Filters ── */
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
.filters input,.filters select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#2d3748}

/* ── Table ── */
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-head{padding:14px 20px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left;white-space:nowrap}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}

/* ── Pills ── */
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.pill-green {background:var(--green-dim);  color:var(--green)}
.pill-orange{background:var(--orange-dim); color:var(--orange)}
.pill-red   {background:var(--red-dim);    color:var(--red)}
.pill-purple{background:var(--primary-dim);color:#6b21a8}
.pill-blue  {background:var(--blue-dim);   color:var(--blue)}
.pill-gray  {background:#f1f5f9;           color:#475569}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;transition:border .2s}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-dim)}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}

.calc-preview{background:#f6f0ff;border:1px solid #d8b4fe;border-radius:10px;padding:14px 16px;margin:0 0 14px;display:none}
.calc-preview.show{display:block}
.calc-row{display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid #ede9fe}
.calc-row:last-child{border-bottom:none;padding-top:8px;margin-top:4px}
.calc-row span:first-child{color:var(--muted)}
.calc-row span:last-child{font-weight:700;color:var(--dark)}

.empty-state{text-align:center;padding:50px;color:var(--muted)}
.empty-state i{font-size:36px;margin-bottom:12px;opacity:.25;display:block}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <div>
        <h1><i class="fas fa-clock"></i> Timekeeping</h1>
        <p><?= date('F Y', strtotime($month_filter.'-01')) ?> &mdash; <?= count($records) ?> record(s)</p>
    </div>
    <?php if ($is_hr): ?>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Record Manually
    </button>
    <?php endif; ?>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?= $success ?></span></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
<?php endif; ?>

<?php if ($schedule): ?>
<div class="sched-badge">
    <i class="fas fa-calendar-check"></i>
    <strong>Active Schedule:</strong>&nbsp;<?= htmlspecialchars($schedule['name']) ?>
    &nbsp;&bull;&nbsp; M–F <?= date('h:i A',strtotime($schedule['mon_start'])) ?>–<?= date('h:i A',strtotime($schedule['mon_end'])) ?>
    &nbsp;&bull;&nbsp; <?= $schedule['grace_period'] ?> min grace period
</div>
<?php endif; ?>

<!-- ══ Self-Service Clock Panel ══ -->
<?php if ($self_emp_id):
    $has_in  = !empty($today_record['time_in']);
    $has_out = !empty($today_record['time_out']);
    $sched_t = getScheduleForDate($schedule, date('Y-m-d'));
?>
<div class="clock-panel">
    <div class="clock-header">
        <h2><i class="fas fa-fingerprint"></i> <?= date('l, F j, Y') ?></h2>
        <div class="live-time" id="liveClock">--:--:-- --</div>
    </div>
    <div class="clock-body">
        <div class="clock-col">
            <div class="clock-col-label">Time In</div>
            <div class="clock-col-val <?= $has_in ? (($today_record['late_minutes']??0)>0?'bad':'good') : '' ?>">
                <?php if ($has_in): ?>
                    <?= date('h:i A', strtotime($today_record['time_in'])) ?>
                    <?php if (($today_record['late_minutes']??0) > 0): ?>
                        <span style="font-size:11px;color:var(--red)"> · <?= $today_record['late_minutes'] ?>m late</span>
                    <?php else: ?>
                        <span style="font-size:11px;color:var(--green)"> · On time</span>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </div>
        </div>
        <div class="clock-col">
            <div class="clock-col-label">Time Out</div>
            <div class="clock-col-val <?= $has_out ? 'good' : '' ?>">
                <?= $has_out ? date('h:i A', strtotime($today_record['time_out'])) : '—' ?>
            </div>
        </div>
        <?php if ($sched_t): ?>
        <div class="clock-col">
            <div class="clock-col-label">Shift</div>
            <div class="clock-col-val"><?= date('h:i A',strtotime($sched_t['start'])) ?> – <?= date('h:i A',strtotime($sched_t['end'])) ?></div>
        </div>
        <?php endif; ?>
        <div class="clock-actions">
            <?php if (!$has_in): ?>
                <form method="POST"><input type="hidden" name="action" value="timein">
                    <button type="submit" class="btn-timein"><i class="fas fa-play"></i> Time In</button>
                </form>
            <?php elseif (!$has_out): ?>
                <div class="btn-done"><i class="fas fa-check"></i> Timed In</div>
                <form method="POST"><input type="hidden" name="action" value="timeout">
                    <button type="submit" class="btn-timeout"><i class="fas fa-stop"></i> Time Out</button>
                </form>
            <?php else: ?>
                <div class="btn-done"><i class="fas fa-check-double"></i> Day Complete</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-list-check"></i></div><div class="stat-info"><h3><?= count($records) ?></h3><p>Total Entries</p></div></div>
    <div class="stat-card"><div class="stat-icon si-blue"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><h3><?= number_format($total_hours,1) ?>h</h3><p>Hours Worked</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-arrow-trend-up"></i></div><div class="stat-info"><h3><?= number_format($total_ot,1) ?>h</h3><p>Total Overtime</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-clock-rotate-left"></i></div><div class="stat-info"><h3><?= $total_late ?>m</h3><p>Late Minutes</p></div></div>
</div>

<!-- Filters -->
<form method="GET" class="filters">
    <input type="month" name="month" value="<?= $month_filter ?>">
    <?php if ($is_hr): ?>
    <select name="emp">
        <option value="">All Employees</option>
        <?php foreach($employees as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $emp_filter==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    <a href="timekeeping.php" class="btn btn-outline"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Records Table -->
<div class="card">
    <div class="card-head"><i class="fas fa-table-list" style="color:var(--primary)"></i> Timekeeping Log</div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr>
            <?php if ($is_hr): ?><th>Employee</th><?php endif; ?>
            <th>Date</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>Hours</th>
            <th>Regular</th>
            <th>Overtime</th>
            <th>Status</th>
            <th>Late</th>
            <th>Undertime</th>
        </tr></thead>
        <tbody>
        <?php if (empty($records)): ?>
        <tr><td colspan="<?= $is_hr ? 10 : 9 ?>">
            <div class="empty-state"><i class="fas fa-clock"></i><p>No records found for this period.</p></div>
        </td></tr>
        <?php endif; ?>
        <?php foreach($records as $r):
            $att_status = $r['att_status'] ?? 'present';
            $late_min   = (int)($r['late_minutes'] ?? $r['att_late'] ?? 0);
            $ut_min     = (int)($r['undertime_min'] ?? 0);
            $ot_hrs     = (float)($r['overtime_hours'] ?? 0);
        ?>
        <tr>
            <?php if ($is_hr): ?>
            <td>
                <strong style="font-size:13px"><?= htmlspecialchars($r['emp_name']) ?></strong>
                <br><code style="font-size:10px;color:#6b46c1;background:#f0e6ff;padding:1px 5px;border-radius:4px"><?= htmlspecialchars($r['emp_code']??'') ?></code>
            </td>
            <?php endif; ?>
            <td style="white-space:nowrap;font-size:13px"><?= date('D, M j', strtotime($r['work_date'])) ?></td>
            <td style="white-space:nowrap">
                <?= $r['time_in']
                    ? '<strong>'.date('h:i A',strtotime($r['time_in'])).'</strong>'
                    : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td style="white-space:nowrap">
                <?= $r['time_out']
                    ? date('h:i A',strtotime($r['time_out']))
                    : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td><strong><?= number_format($r['hours_worked']??0,2) ?>h</strong></td>
            <td><?= number_format($r['regular_hours']??0,2) ?>h</td>
            <td>
                <?php if ($ot_hrs > 0): ?>
                <span class="pill pill-purple"><i class="fas fa-plus"></i><?= number_format($ot_hrs,1) ?>h</span>
                <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td>
                <?php
                [$pc,$pi,$pt] = match($att_status){
                    'present'  => ['pill-green', 'fa-circle-check',        'Present'],
                    'late'     => ['pill-orange', 'fa-circle-exclamation',  'Late'],
                    'half_day' => ['pill-blue',   'fa-circle-half-stroke',  'Half Day'],
                    'absent'   => ['pill-red',    'fa-circle-xmark',        'Absent'],
                    default    => ['pill-gray',   'fa-circle',              ucfirst($att_status)],
                };
                ?>
                <span class="pill <?= $pc ?>"><i class="fas <?= $pi ?>"></i> <?= $pt ?></span>
            </td>
            <td>
                <?= $late_min > 0
                    ? '<span class="pill pill-red"><i class="fas fa-arrow-right-to-bracket"></i> '.$late_min.'m</span>'
                    : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td>
                <?= $ut_min > 0
                    ? '<span class="pill pill-orange"><i class="fas fa-arrow-left-from-line"></i> '.$ut_min.'m</span>'
                    : '<span style="color:var(--muted)">—</span>' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ══ HR Manual Add Modal ══ -->
<?php if ($is_hr): ?>
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-clock" style="color:var(--primary)"></i> Add / Edit Timekeeping Record</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group">
        <label>Employee *</label>
        <select name="employee_id" required>
            <option value="">Select Employee</option>
            <?php foreach($employees as $e): ?>
            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?> &mdash; <?= htmlspecialchars($e['employee_id']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Work Date *</label>
        <input type="date" name="work_date" id="m_date" value="<?= date('Y-m-d') ?>" required oninput="liveCalc()">
    </div>
    <div class="form-group">
        <label>Time In</label>
        <input type="time" name="time_in" id="m_ti" oninput="liveCalc()">
    </div>
    <div class="form-group">
        <label>Time Out</label>
        <input type="time" name="time_out" id="m_to" oninput="liveCalc()">
    </div>
    <!-- Live preview -->
    <div class="calc-preview" id="calcPreview">
        <div class="calc-row"><span>Hours Worked</span><span id="pv_h">—</span></div>
        <div class="calc-row"><span>Regular Hours</span><span id="pv_r">—</span></div>
        <div class="calc-row"><span>Overtime</span><span id="pv_o">—</span></div>
        <div class="calc-row"><span>Late</span><span id="pv_l">—</span></div>
        <div class="calc-row"><span>Undertime</span><span id="pv_u">—</span></div>
        <div class="calc-row"><span><strong>Status</strong></span><span id="pv_s" style="color:var(--primary)">—</span></div>
    </div>
    <div class="form-group"><label>Notes</label><textarea name="notes" rows="2" style="resize:vertical"></textarea></div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Record</button>
    </div>
    </form>
</div>
</div>
<?php endif; ?>

<script>
// ── Live clock ─────────────────────────────────────
(function tick(){
    const el = document.getElementById('liveClock');
    if (!el) return;
    const n = new Date();
    let h = n.getHours(), m = n.getMinutes(), s = n.getSeconds();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    el.textContent = String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')+' '+ap;
    setTimeout(tick, 1000);
})();

// ── Schedule data ──────────────────────────────────
<?php if ($schedule): ?>
const SCHED = {
    mon:{s:'<?= substr($schedule['mon_start']??'',0,5) ?>',e:'<?= substr($schedule['mon_end']??'',0,5) ?>'},
    tue:{s:'<?= substr($schedule['tue_start']??'',0,5) ?>',e:'<?= substr($schedule['tue_end']??'',0,5) ?>'},
    wed:{s:'<?= substr($schedule['wed_start']??'',0,5) ?>',e:'<?= substr($schedule['wed_end']??'',0,5) ?>'},
    thu:{s:'<?= substr($schedule['thu_start']??'',0,5) ?>',e:'<?= substr($schedule['thu_end']??'',0,5) ?>'},
    fri:{s:'<?= substr($schedule['fri_start']??'',0,5) ?>',e:'<?= substr($schedule['fri_end']??'',0,5) ?>'},
    sat:{s:'<?= substr($schedule['sat_start']??'',0,5) ?>',e:'<?= substr($schedule['sat_end']??'',0,5) ?>'},
    sun:{s:'<?= substr($schedule['sun_start']??'',0,5) ?>',e:'<?= substr($schedule['sun_end']??'',0,5) ?>'},
    grace:<?= (int)($schedule['grace_period']??15) ?>
};
<?php else: ?>const SCHED = null;<?php endif; ?>

const DAYS = ['sun','mon','tue','wed','thu','fri','sat'];
function tMin(t){if(!t)return null;const[h,m]=t.split(':').map(Number);return h*60+m;}
function fMin(m){if(m<=0)return'—';const h=Math.floor(m/60),mn=m%60;return h>0?`${h}h ${mn}m`:`${mn}m`;}

function liveCalc(){
    const dateEl=document.getElementById('m_date');
    const ti=document.getElementById('m_ti').value;
    const to=document.getElementById('m_to').value;
    const prev=document.getElementById('calcPreview');
    if(!ti){prev.classList.remove('show');return;}
    prev.classList.add('show');

    const day=DAYS[new Date(dateEl.value+'T00:00').getDay()];
    let ss=null,se=null,grace=15;
    if(SCHED&&SCHED[day]&&SCHED[day].s){ss=tMin(SCHED[day].s);se=tMin(SCHED[day].e);grace=SCHED.grace;}

    const tiM=tMin(ti),toM=to?tMin(to):null;
    let late=0,ut=0,ot=0,h=0,reg=0,otH=0,status='Present';
    const schedH=ss!==null?(se-ss)/60:8;

    if(ss!==null&&tiM>ss+grace){late=tiM-ss;status='Late';}
    if(toM!==null){
        h=Math.max(0,(toM-tiM)/60);
        if(se!==null){
            if(toM<se)ut=se-toM;
            if(toM>se){ot=toM-se;otH=ot/60;}
        } else {if(h>8){otH=h-8;ot=otH*60;}}
        reg=Math.min(h,schedH);
        if(late>0&&ut>0)status='Half Day';
        else if(late>0)status='Late';
        else if(ut>0)status='Undertime';
        else if(ot>0)status='Overtime';
        else status='On Time';
    }

    document.getElementById('pv_h').textContent=h.toFixed(2)+'h';
    document.getElementById('pv_r').textContent=reg.toFixed(2)+'h';
    document.getElementById('pv_o').textContent=otH>0?'+'+otH.toFixed(2)+'h':'—';
    document.getElementById('pv_l').textContent=fMin(late);
    document.getElementById('pv_u').textContent=fMin(ut);
    document.getElementById('pv_s').textContent=status;
}

document.querySelectorAll('.modal-overlay').forEach(o=>
    o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));
</script>
</div></div></div>
</body>
</html>