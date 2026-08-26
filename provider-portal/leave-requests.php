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

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $rid = (int)($_POST['request_id']??0);
    if ($_POST['action'] === 'approve') {
        $db->prepare("UPDATE leave_requests SET status='approved',approved_by=:by,updated_at=NOW() WHERE id=:id AND provider_id=:p")
           ->execute([':by'=>$portal_staff_id??0,':id'=>$rid,':p'=>$pid]);
        $success = "Leave request approved.";
    } elseif ($_POST['action'] === 'reject') {
        $db->prepare("UPDATE leave_requests SET status='rejected',approved_by=:by,updated_at=NOW() WHERE id=:id AND provider_id=:p")
           ->execute([':by'=>$portal_staff_id??0,':id'=>$rid,':p'=>$pid]);
        $success = "Leave request rejected.";
    } elseif ($_POST['action'] === 'add') {
        $eid = (int)($_POST['employee_id']??0);
        $type = $_POST['leave_type']??'annual';
        $start = $_POST['start_date']??'';
        $end = $_POST['end_date']??'';
        $reason = trim($_POST['reason']??'');
        $db->prepare("INSERT INTO leave_requests (provider_id,employee_id,leave_type,start_date,end_date,reason,status) VALUES (:p,:e,:t,:s,:en,:r,'pending')")
           ->execute([':p'=>$pid,':e'=>$eid,':t'=>$type,':s'=>$start,':en'=>$end,':r'=>$reason]);
        $success = "Leave request filed.";
    }
}

$status_filter = $_GET['status']??'';
$where = "lr.provider_id=:p"; $params=[':p'=>$pid];
if ($status_filter) { $where .= " AND lr.status=:s"; $params[':s']=$status_filter; }

$requests = safeAll($db,"SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.position, e.department FROM leave_requests lr JOIN employees e ON lr.employee_id=e.id WHERE $where ORDER BY lr.created_at DESC",$params);
$employees = safeAll($db,"SELECT id,first_name,last_name FROM employees WHERE provider_id=:p AND status='active' ORDER BY first_name",[':p'=>$pid]);

$pending = safeCount($db,"SELECT COUNT(*) FROM leave_requests WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);
$approved = safeCount($db,"SELECT COUNT(*) FROM leave_requests WHERE provider_id=:p AND status='approved'",[':p'=>$pid]);
$rejected = safeCount($db,"SELECT COUNT(*) FROM leave_requests WHERE provider_id=:p AND status='rejected'",[':p'=>$pid]);

$active_menu='leave';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leave Requests - <?= htmlspecialchars($portal_company) ?></title>
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
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;padding:18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px;cursor:pointer;text-decoration:none;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.1)}
.stat-icon{width:44px;height:44px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.stat-info h3{font-size:20px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-success{background:#27ae60;color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filters select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
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
.badge-blue{background:#bee3f8;color:#2b6cb0}
.badge-purple{background:#f0e6ff;color:#6b46c1}
.leave-type{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#e9d8fd;color:#6b21a8;text-transform:capitalize}
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

<?php if (!$tier_is_paid): ?>
<div style="background:#eef2ff;border:1.5px solid #c7d2fe;border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#3730a3">
    <i class="fas fa-lock" style="flex-shrink:0;font-size:16px"></i>
    <div><strong>Free Tier — View Only.</strong> Leave requests are visible but approvals and management require a Pro subscription. <a href="subscriptions.php" style="color:#4f46e5;font-weight:700">Upgrade to Pro &rarr;</a></div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-file-alt"></i> Leave Requests</h1>
        <p><?= count($requests) ?> request(s) found</p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-plus"></i> File Leave</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>

<div class="stats-grid">
    <a href="leave-requests.php?status=pending" class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><h3><?= $pending ?></h3><p>Pending</p></div></a>
    <a href="leave-requests.php?status=approved" class="stat-card"><div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?= $approved ?></h3><p>Approved</p></div></a>
    <a href="leave-requests.php?status=rejected" class="stat-card"><div class="stat-icon si-red"><i class="fas fa-times-circle"></i></div><div class="stat-info"><h3><?= $rejected ?></h3><p>Rejected</p></div></a>
</div>

<form method="GET" class="filters">
    <select name="status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="pending" <?= $status_filter==='pending'?'selected':'' ?>>Pending</option>
        <option value="approved" <?= $status_filter==='approved'?'selected':'' ?>>Approved</option>
        <option value="rejected" <?= $status_filter==='rejected'?'selected':'' ?>>Rejected</option>
    </select>
    <?php if($status_filter): ?><a href="leave-requests.php" class="btn btn-outline btn-sm">Clear Filter</a><?php endif; ?>
</form>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>Employee</th><th>Leave Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($requests)): ?>
    <tr><td colspan="8"><div class="empty-state"><i class="fas fa-file-alt" style="font-size:36px;opacity:.2;display:block;margin-bottom:10px"></i><p>No leave requests found.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach($requests as $r):
        $days = (int)((strtotime($r['end_date'])-strtotime($r['start_date']))/86400)+1;
    ?>
    <tr>
        <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['department']??'—') ?></small></td>
        <td><span class="leave-type"><?= ucfirst($r['leave_type']) ?></span></td>
        <td><?= date('M d, Y', strtotime($r['start_date'])) ?></td>
        <td><?= date('M d, Y', strtotime($r['end_date'])) ?></td>
        <td><strong><?= $days ?></strong></td>
        <td style="max-width:180px;font-size:12px;color:var(--muted)"><?= htmlspecialchars(substr($r['reason']??'—',0,60)).(strlen($r['reason']??'')>60?'…':'') ?></td>
        <td><span class="badge <?= $r['status']==='approved'?'badge-green':($r['status']==='rejected'?'badge-red':'badge-orange') ?>"><?= ucfirst($r['status']) ?></span></td>
        <td>
        <?php if($r['status']==='pending'): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i></button></form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Reject this leave?')"><input type="hidden" name="action" value="reject"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i></button></form>
        <?php else: ?>
            <span style="color:var(--muted);font-size:12px">—</span>
        <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-file-alt" style="color:var(--primary)"></i> File Leave Request</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group">
        <label>Employee *</label>
        <select name="employee_id" required>
            <option value="">Select Employee</option>
            <?php foreach($employees as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Leave Type *</label>
        <select name="leave_type">
            <option value="annual">Annual</option>
            <option value="sick">Sick</option>
            <option value="personal">Personal</option>
            <option value="maternity">Maternity</option>
            <option value="paternity">Paternity</option>
        </select>
    </div>
    <div class="form-group"><label>Start Date *</label><input type="date" name="start_date" required></div>
    <div class="form-group"><label>End Date *</label><input type="date" name="end_date" required></div>
    <div class="form-group"><label>Reason</label><textarea name="reason" rows="3" style="resize:vertical"></textarea></div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Submit</button>
    </div>
    </form>
</div>
</div>
<script>
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));
</script>
</body></html>