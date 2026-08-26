<?php
// admin/hr/requests.php
$require_dept = 'hr';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_leave') {
        $emp_id = (int)$_POST['employee_id'];
        $type   = $_POST['leave_type'] ?? 'vacation';
        $from   = $_POST['date_from'] ?? '';
        $to     = $_POST['date_to']   ?? '';
        $reason = trim($_POST['reason']??'');
        // Calculate days
        $days = 0;
        if ($from && $to) {
            $d1 = new DateTime($from); $d2 = new DateTime($to);
            $interval = $d1->diff($d2);
            $days = $interval->days + 1;
        }
        if (!$emp_id || !$from || !$to) { $error = 'Employee and dates are required.'; }
        else {
            $db->prepare("INSERT INTO leave_requests (employee_id,leave_type,date_from,date_to,days,reason,status) VALUES(:e,:t,:f,:to,:d,:r,'pending')")
            ->execute([':e'=>$emp_id,':t'=>$type,':f'=>$from,':to'=>$to,':d'=>$days,':r'=>$reason]);
            $success = 'Leave request filed.';
        }
    } elseif (in_array($action,['approve','reject'])) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $db->prepare("UPDATE leave_requests SET status=:s,approved_by=:by,approved_at=NOW() WHERE id=:id")
        ->execute([':s'=>$status,':by'=>$_SESSION['admin_id'],':id'=>(int)$_POST['req_id']]);
        $success = 'Leave request '.$status.'.';
    }
}

$filter = $_GET['status'] ?? '';
$sql = "SELECT lr.*, e.first_name, e.last_name, e.employee_code, e.department FROM leave_requests lr JOIN employees e ON lr.employee_id=e.id WHERE 1=1";
$params = [];
if ($filter) { $sql .= " AND lr.status=:s"; $params[':s']=$filter; }
$sql .= " ORDER BY lr.created_at DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employees = $db->query("SELECT id,employee_code,first_name,last_name FROM employees WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);
$active_menu = 'hr_requests';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HR Requests - Pestify</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}.btn-sm{padding:5px 11px;font-size:12px}
.filters{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.filters a{padding:8px 16px;border-radius:999px;font-size:12px;font-weight:600;text-decoration:none;background:#f0f0f0;color:#555;transition:all .2s}
.filters a.active,.filters a:hover{background:var(--primary);color:#fff}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#e9d8fd;color:#6b46c1;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-pending{background:#fff3cd;color:#856404}.b-approved{background:#c6f6d5;color:#276749}.b-rejected{background:#fed7d7;color:#c53030}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
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
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">
<div class="page-header">
    <h1><i class="fas fa-file-alt"></i> HR Requests</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')"><i class="fas fa-plus"></i> File Leave</button>
</div>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?=$success?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?=$error?></div><?php endif; ?>

<div class="filters">
    <a href="requests.php" class="<?=!$filter?'active':''?>">All</a>
    <a href="?status=pending" class="<?=$filter==='pending'?'active':''?>">Pending</a>
    <a href="?status=approved" class="<?=$filter==='approved'?'active':''?>">Approved</a>
    <a href="?status=rejected" class="<?=$filter==='rejected'?'active':''?>">Rejected</a>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-calendar-minus"></i> Leave Requests</h2><span style="font-size:12px;color:var(--muted)"><?=count($requests)?> requests</span></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (empty($requests)): ?><tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">No requests found</td></tr><?php endif; ?>
        <?php foreach ($requests as $r): ?>
        <tr>
            <td>
                <div style="font-weight:600"><?=htmlspecialchars($r['first_name'].' '.$r['last_name'])?></div>
                <div style="font-size:11px;color:var(--muted)"><?=$r['employee_code']?> · <?=ucfirst($r['department']??'')?></div>
            </td>
            <td><span style="background:#f0e6ff;color:#6b46c1;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700"><?=ucfirst($r['leave_type'])?></span></td>
            <td style="font-size:12px"><?=date('M d, Y',strtotime($r['date_from']))?></td>
            <td style="font-size:12px"><?=date('M d, Y',strtotime($r['date_to']))?></td>
            <td style="font-weight:700"><?=$r['days']?></td>
            <td style="font-size:12px;color:var(--muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($r['reason']??'—')?></td>
            <td><span class="badge b-<?=$r['status']?>"><?=ucfirst($r['status'])?></span></td>
            <td>
                <?php if ($r['status']==='pending'): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="approve"><input type="hidden" name="req_id" value="<?=$r['id']?>">
                    <button class="btn btn-sm" style="background:#c6f6d5;color:#276749" type="submit"><i class="fas fa-check"></i></button>
                </form>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="reject"><input type="hidden" name="req_id" value="<?=$r['id']?>">
                    <button class="btn btn-sm" style="background:#fed7d7;color:#c53030" type="submit"><i class="fas fa-times"></i></button>
                </form>
                <?php else: ?><span style="font-size:11px;color:var(--muted)"><?=$r['approved_at']?date('M d',strtotime($r['approved_at'])):'—'?></span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</div></div>

<!-- File Leave Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal-box">
    <div class="modal-head"><h3><i class="fas fa-file-alt" style="color:var(--primary);margin-right:8px"></i>File Leave Request</h3>
    <button style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button></div>
    <form method="POST"><input type="hidden" name="action" value="add_leave">
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Employee *</label>
            <select name="employee_id" class="form-control" required>
                <option value="">Choose employee...</option>
                <?php foreach ($employees as $e): ?><option value="<?=$e['id']?>">[<?=$e['employee_code']?>] <?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Leave Type</label>
            <select name="leave_type" class="form-control">
                <option value="vacation">Vacation</option><option value="sick">Sick</option><option value="emergency">Emergency</option>
                <option value="maternity">Maternity</option><option value="paternity">Paternity</option><option value="unpaid">Unpaid</option>
            </select>
        </div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Date From *</label><input type="date" name="date_from" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Date To *</label><input type="date" name="date_to" class="form-control" required></div>
        </div>
        <div class="form-group"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3"></textarea></div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit</button>
    </div></form>
</div>
</div>
<script>document.getElementById('addModal').addEventListener('click',e=>{if(e.target===document.getElementById('addModal'))e.target.classList.remove('open')});</script>
</body></html>