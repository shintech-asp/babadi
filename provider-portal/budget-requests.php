<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

if (!($portal_role === 'owner' || $portal_dept === 'finance' || $portal_dept === 'all')) {
    header('Location: dashboard.php'); exit;
}

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Budget Requests'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}
function safeSum($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(float)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $dept = trim($_POST['department']??'');
        $amount = (float)($_POST['amount']??0);
        $purpose = trim($_POST['purpose']??'');
        $req_date = date('Y-m-d');
        $needed = $_POST['needed_date']??null;
        // FIX: Use null instead of 0 to avoid FK constraint violation on employees table
        $requested_by = !empty($portal_staff_id) ? (int)$portal_staff_id : null;
        try {
            $db->prepare("INSERT INTO budget_requests (provider_id,department,requested_by,amount,purpose,request_date,needed_date,status) VALUES (:p,:d,:rb,:a,:pu,:rd,:nd,'pending')")
               ->execute([':p'=>$pid,':d'=>$dept,':rb'=>$requested_by,':a'=>$amount,':pu'=>$purpose,':rd'=>$req_date,':nd'=>$needed]);
            $success = "Budget request submitted for ₱".number_format($amount,2);
        } catch(Exception $ex){ $error = $ex->getMessage(); }
    } elseif ($_POST['action'] === 'approve') {
        $rid = (int)($_POST['request_id']??0);
        $approved_amt = (float)($_POST['approved_amount']??0);
        $remarks = trim($_POST['remarks']??'');
        $status = $approved_amt > 0 ? 'approved' : 'rejected';
        // FIX: Use null instead of 0 to avoid FK constraint violation on employees table
        $approved_by = !empty($portal_staff_id) ? (int)$portal_staff_id : null;
        $db->prepare("UPDATE budget_requests SET status=:s,approved_amount=:aa,approved_by=:by,remarks=:r,updated_at=NOW() WHERE id=:id AND provider_id=:p")
           ->execute([':s'=>$status,':aa'=>$approved_amt,':by'=>$approved_by,':r'=>$remarks,':id'=>$rid,':p'=>$pid]);
        $success = "Budget request ".ucfirst($status).".";
    } elseif ($_POST['action'] === 'reject') {
        $rid = (int)($_POST['request_id']??0);
        $remarks = trim($_POST['remarks']??'');
        // FIX: Use null instead of 0 to avoid FK constraint violation on employees table
        $approved_by = !empty($portal_staff_id) ? (int)$portal_staff_id : null;
        $db->prepare("UPDATE budget_requests SET status='rejected',approved_by=:by,remarks=:r,updated_at=NOW() WHERE id=:id AND provider_id=:p")
           ->execute([':by'=>$approved_by,':r'=>$remarks,':id'=>$rid,':p'=>$pid]);
        $success = "Budget request rejected.";
    }
}

$status_filter = $_GET['status']??'';
$where = "provider_id=:p"; $params=[':p'=>$pid];
if ($status_filter) { $where .= " AND status=:s"; $params[':s']=$status_filter; }

$requests = safeAll($db,"SELECT * FROM budget_requests WHERE $where ORDER BY created_at DESC",$params);

$pending = safeCount($db,"SELECT COUNT(*) FROM budget_requests WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);
$pending_amt = safeSum($db,"SELECT COALESCE(SUM(amount),0) FROM budget_requests WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);
$approved_amt_total = safeSum($db,"SELECT COALESCE(SUM(approved_amount),0) FROM budget_requests WHERE provider_id=:p AND status='approved' AND YEAR(created_at)=YEAR(NOW())",[':p'=>$pid]);
$total_requests = safeCount($db,"SELECT COUNT(*) FROM budget_requests WHERE provider_id=:p",[':p'=>$pid]);

$active_menu='budget';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Budget Requests - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#e67e22;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
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
.stat-card{background:#fff;padding:18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:44px;height:44px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.stat-info h3{font-size:16px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-success{background:#27ae60;color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:10px;margin-bottom:20px}
.filters select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
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
.badge-gray{background:#e2e8f0;color:#4a5568}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.review-detail{background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:14px;border:1px solid var(--border);font-size:13px}
.review-detail p{margin-bottom:6px;color:var(--muted)}
.review-detail strong{color:var(--dark)}
.empty-state{text-align:center;padding:40px;color:var(--muted)}
@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <div>
        <h1><i class="fas fa-file-invoice-dollar"></i> Budget Requests</h1>
        <p><?= count($requests) ?> request(s)</p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-plus"></i> New Request</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-blue"><i class="fas fa-list"></i></div><div class="stat-info"><h3><?= $total_requests ?></h3><p>Total Requests</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><h3><?= $pending ?></h3><p>Pending Review</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-coins"></i></div><div class="stat-info"><h3>₱<?= number_format($pending_amt,0) ?></h3><p>Pending Amount</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3>₱<?= number_format($approved_amt_total,0) ?></h3><p>Approved This Year</p></div></div>
</div>

<form method="GET" class="filters">
    <select name="status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="pending" <?= $status_filter==='pending'?'selected':'' ?>>Pending</option>
        <option value="approved" <?= $status_filter==='approved'?'selected':'' ?>>Approved</option>
        <option value="partially_approved" <?= $status_filter==='partially_approved'?'selected':'' ?>>Partially Approved</option>
        <option value="rejected" <?= $status_filter==='rejected'?'selected':'' ?>>Rejected</option>
    </select>
    <?php if($status_filter): ?><a href="budget-requests.php" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
</form>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>Department</th><th>Purpose</th><th>Requested</th><th>Needed By</th><th>Approved Amt</th><th>Status</th><th>Remarks</th><?php if($portal_role==='owner'): ?><th>Action</th><?php endif; ?></tr></thead>
    <tbody>
    <?php if(empty($requests)): ?>
    <tr><td colspan="8"><div class="empty-state"><i class="fas fa-file-invoice-dollar" style="font-size:32px;opacity:.2;display:block;margin-bottom:10px"></i>No budget requests found.</div></td></tr>
    <?php endif; ?>
    <?php foreach($requests as $r): ?>
    <tr>
        <td><strong><?= htmlspecialchars($r['department']) ?></strong><br><small style="color:var(--muted)"><?= date('M d, Y', strtotime($r['request_date'])) ?></small></td>
        <td style="max-width:200px;font-size:12px"><?= htmlspecialchars(substr($r['purpose']??'—',0,80)).(strlen($r['purpose']??'')>80?'…':'') ?></td>
        <td style="font-weight:700;color:#e67e22">₱<?= number_format($r['amount'],2) ?></td>
        <td style="font-size:12px"><?= $r['needed_date'] ? date('M d, Y', strtotime($r['needed_date'])) : '—' ?></td>
        <td><?= $r['approved_amount'] ? '<span style="color:#27ae60;font-weight:700">₱'.number_format($r['approved_amount'],2).'</span>' : '—' ?></td>
        <td><span class="badge <?= $r['status']==='approved'?'badge-green':($r['status']==='rejected'?'badge-red':($r['status']==='partially_approved'?'badge-blue':'badge-orange')) ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars(substr($r['remarks']??'—',0,50)) ?></td>
        <?php if($portal_role==='owner'): ?>
        <td>
        <?php if($r['status']==='pending'): ?>
            <button onclick="openReview(<?= htmlspecialchars(json_encode($r)) ?>)" class="btn btn-primary btn-sm"><i class="fas fa-gavel"></i> Review</button>
        <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> New Budget Request</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label>Department *</label><input type="text" name="department" placeholder="e.g. HR, Operations, Marketing" required></div>
    <div class="form-group"><label>Amount Requested (₱) *</label><input type="number" name="amount" min="0" step="0.01" required></div>
    <div class="form-group"><label>Purpose *</label><textarea name="purpose" rows="3" required style="resize:vertical"></textarea></div>
    <div class="form-group"><label>Date Needed</label><input type="date" name="needed_date"></div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
    </div>
    </form>
</div>
</div>

<!-- Review Modal -->
<div class="modal-overlay" id="reviewModal">
<div class="modal">
    <h3><i class="fas fa-gavel" style="color:var(--primary)"></i> Review Budget Request</h3>
    <div class="review-detail" id="reviewDetail"></div>
    <form method="POST">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="request_id" id="review_id">
    <div class="form-group"><label>Approved Amount (₱) <small style="text-transform:none;letter-spacing:0">(enter 0 to reject)</small></label><input type="number" name="approved_amount" id="review_amt" min="0" step="0.01"></div>
    <div class="form-group"><label>Remarks</label><textarea name="remarks" rows="2" style="resize:vertical"></textarea></div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('reviewModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Submit Decision</button>
    </div>
    </form>
</div>
</div>

<script>
function openReview(r) {
    document.getElementById('review_id').value = r.id;
    document.getElementById('review_amt').value = r.amount;
    document.getElementById('reviewDetail').innerHTML = `
        <p><strong>Department:</strong> ${r.department}</p>
        <p><strong>Requested:</strong> ₱${parseFloat(r.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</p>
        <p><strong>Purpose:</strong> ${r.purpose||'—'}</p>
        <p><strong>Date Needed:</strong> ${r.needed_date||'Not specified'}</p>
    `;
    document.getElementById('reviewModal').classList.add('active');
}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));
</script>
</body></html>