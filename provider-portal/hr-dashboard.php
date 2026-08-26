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
if (!$tier_is_paid) { echo _tierLockedPage('HR Dashboard'); exit; }

function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}
function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

$today = date('Y-m-d');
$job_posted = isset($_GET['job_posted']) && $_GET['job_posted'] === '1';
$total_emp     = safeCount($db,"SELECT COUNT(*) FROM employees WHERE provider_id=:p AND status='active'",[':p'=>$pid]);
$present_today = safeCount($db,"SELECT COUNT(*) FROM attendance WHERE provider_id=:p AND date=:d AND status IN('present','late')",[':p'=>$pid,':d'=>$today]);
$on_leave      = safeCount($db,"SELECT COUNT(*) FROM leave_requests WHERE provider_id=:p AND status='approved' AND :d BETWEEN start_date AND end_date",[':p'=>$pid,':d'=>$today]);
$pending_leave = safeCount($db,"SELECT COUNT(*) FROM leave_requests WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);
$open_jobs     = safeCount($db,"SELECT COUNT(*) FROM recruitment WHERE provider_id=:p AND status='open'",[':p'=>$pid]);

$recent_attendance = safeAll($db,"SELECT a.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.position FROM attendance a JOIN employees e ON a.employee_id=e.id WHERE a.provider_id=:p AND a.date=:d ORDER BY a.time_in DESC LIMIT 10",[':p'=>$pid,':d'=>$today]);
$pending_leaves = safeAll($db,"SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name FROM leave_requests lr JOIN employees e ON lr.employee_id=e.id WHERE lr.provider_id=:p AND lr.status='pending' ORDER BY lr.created_at DESC LIMIT 5",[':p'=>$pid]);
$recent_jobs = safeAll(
    $db,
    "SELECT r.*,
            (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id = r.id) AS applicant_count
     FROM recruitment r
     WHERE r.provider_id = :p
     ORDER BY r.posted_date DESC, r.id DESC
     LIMIT 6",
    [':p'=>$pid]
);

$active_menu='hr';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HR Dashboard - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{margin-bottom:26px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:#fff;padding:18px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px;transition:all .25s}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.1)}
.stat-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.stat-info h3{font-size:22px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted);margin-top:2px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-success{background:#27ae60;color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-green{background:#c6f6d5;color:#276749}
.badge-red{background:#fed7d7;color:#c53030}
.badge-orange{background:#feebc8;color:#c05621}
.badge-gray{background:#e2e8f0;color:#4a5568}
.badge-blue{background:#bee3f8;color:#2b6cb0}
.quick-links{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:16px 20px}
.ql-btn{border-radius:10px;padding:14px;text-decoration:none;display:flex;align-items:center;gap:10px;color:#fff;font-weight:600;font-size:13px;transition:all .2s}
.ql-btn:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.15)}
.ql-btn i{font-size:20px}
.empty-state{text-align:center;padding:30px;color:var(--muted)}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(3,1fr)}.grid-2{grid-template-columns:1fr}}
@media(max-width:768px){.stats-grid{grid-template-columns:repeat(2,1fr)}.quick-links{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-users"></i> HR Dashboard</h1>
    <p><?= htmlspecialchars($portal_company) ?> &nbsp;·&nbsp; <?= date('l, F j, Y') ?></p>
</div>

<?php if($job_posted): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> Job post created successfully.</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-id-badge"></i></div><div class="stat-info"><h3><?= $total_emp ?></h3><p>Active Employees</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?= $present_today ?></h3><p>Present Today</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-umbrella-beach"></i></div><div class="stat-info"><h3><?= $on_leave ?></h3><p>On Leave Today</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-file-alt"></i></div><div class="stat-info"><h3><?= $pending_leave ?></h3><p>Pending Leave</p></div></div>
    <div class="stat-card"><div class="stat-icon si-blue"><i class="fas fa-briefcase"></i></div><div class="stat-info"><h3><?= $open_jobs ?></h3><p>Open Positions</p></div></div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-th-large"></i> HR Modules</h2></div>
    <div class="quick-links">
        <a href="employees.php" class="ql-btn" style="background:linear-gradient(135deg,#9b59b6,#8e44ad)"><i class="fas fa-id-badge"></i> Employees</a>
        <a href="attendance.php" class="ql-btn" style="background:linear-gradient(135deg,#27ae60,#16a085)"><i class="fas fa-calendar-check"></i> Attendance</a>
        <a href="timekeeping.php" class="ql-btn" style="background:linear-gradient(135deg,#3498db,#2980b9)"><i class="fas fa-clock"></i> Timekeeping</a>
        <a href="payroll.php" class="ql-btn" style="background:linear-gradient(135deg,#e67e22,#d35400)"><i class="fas fa-money-bill-wave"></i> Payroll</a>
        <a href="leave-requests.php" class="ql-btn" style="background:linear-gradient(135deg,#e74c3c,#c0392b)"><i class="fas fa-file-alt"></i> Leave Requests</a>
        <a href="recruitment.php" class="ql-btn" style="background:linear-gradient(135deg,#1abc9c,#16a085)"><i class="fas fa-user-plus"></i> Recruitment</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-briefcase"></i> Job Posts</h2>
        <a href="recruitment.php" class="btn btn-primary btn-sm"><i class="fas fa-external-link-alt"></i> Manage</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Job Title</th><th>Department</th><th>Type</th><th>Applicants</th><th>Status</th><th>Posted</th><th>Action</th></tr></thead>
        <tbody>
        <?php if(empty($recent_jobs)): ?>
        <tr><td colspan="7" class="empty-state">No job posts yet.</td></tr>
        <?php endif; ?>
        <?php foreach($recent_jobs as $j): ?>
        <tr>
            <td><strong><?= htmlspecialchars($j['job_title'] ?? '—') ?></strong></td>
            <td><?= htmlspecialchars($j['department'] ?? '—') ?></td>
            <td><?= htmlspecialchars(ucfirst(str_replace('_',' ',(string)($j['employment_type'] ?? '')))) ?></td>
            <td><?= (int)($j['applicant_count'] ?? 0) ?></td>
            <td>
                <?php
                $st = (string)($j['status'] ?? '');
                $stClass = $st === 'open' ? 'badge-green' : ($st === 'on_hold' ? 'badge-orange' : 'badge-gray');
                ?>
                <span class="badge <?= $stClass ?>"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$st))) ?></span>
            </td>
            <td><?= !empty($j['posted_date']) ? date('M d, Y', strtotime($j['posted_date'])) : '—' ?></td>
            <td><a href="recruitment.php?job=<?= (int)$j['id'] ?>" class="btn btn-primary btn-sm">View</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-calendar-check"></i> Today's Attendance</h2>
            <a href="attendance.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View All</a>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Employee</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
            <tbody>
            <?php if(empty($recent_attendance)): ?>
            <tr><td colspan="4" class="empty-state">No attendance records for today.</td></tr>
            <?php endif; ?>
            <?php foreach($recent_attendance as $a): ?>
            <tr>
                <td><strong><?= htmlspecialchars($a['emp_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($a['position']??'—') ?></small></td>
                <td><?= $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '—' ?></td>
                <td><?= $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '—' ?></td>
                <td><span class="badge <?= $a['status']==='present'?'badge-green':($a['status']==='late'?'badge-orange':'badge-red') ?>"><?= ucfirst($a['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-file-alt"></i> Pending Leave Requests</h2>
            <a href="leave-requests.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View All</a>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Action</th></tr></thead>
            <tbody>
            <?php if(empty($pending_leaves)): ?>
            <tr><td colspan="4" class="empty-state">No pending leave requests.</td></tr>
            <?php endif; ?>
            <?php foreach($pending_leaves as $l): ?>
            <tr>
                <td><strong><?= htmlspecialchars($l['emp_name']) ?></strong></td>
                <td><span class="badge badge-blue"><?= ucfirst($l['leave_type']) ?></span></td>
                <td style="font-size:11px"><?= date('M d', strtotime($l['start_date'])) ?> – <?= date('M d', strtotime($l['end_date'])) ?></td>
                <td><a href="leave-requests.php?action=view&id=<?= $l['id'] ?>" class="btn btn-primary btn-sm">Review</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

</div></div></div>
</body></html>
