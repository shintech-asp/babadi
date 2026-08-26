<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$can_crm = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');
if (!$can_crm) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';

$is_emp   = ($_SESSION['portal_account_type'] ?? 'staff') === 'employee';
$self_emp_id = (int)($_SESSION['portal_employee_id'] ?? 0);

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // File a new request
    if ($action === 'add') {
        $item   = trim($_POST['item_name'] ?? '');
        $type   = $_POST['item_type'] ?? 'consumable';
        $qty    = (int)($_POST['quantity_requested'] ?? 1);
        $unit   = trim($_POST['unit'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $urgency= $_POST['urgency'] ?? 'normal';
        $availed= (int)($_POST['availed_id'] ?? 0) ?: null;
        if ($item && $qty > 0) {
            try {
                $db->prepare("INSERT INTO inventory_requests (provider_id,requested_by,availed_id,item_name,item_type,quantity_requested,unit,reason,urgency,status,created_at)
                              VALUES (:p,:rb,:av,:item,:type,:qty,:unit,:reason,:urgency,'pending',NOW())")
                   ->execute([':p'=>$pid,':rb'=>$self_emp_id?:null,':av'=>$availed,':item'=>$item,':type'=>$type,':qty'=>$qty,':unit'=>$unit,':reason'=>$reason,':urgency'=>$urgency]);
                $success = "Inventory request submitted successfully.";
            } catch(Exception $e){ $error = $e->getMessage(); }
        } else { $error = "Item name and quantity are required."; }
    }

    // Owner/manager approve or reject
    elseif (in_array($action,['approve','reject','fulfill']) && $portal_role === 'owner') {
        $rid = (int)($_POST['request_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $status_map = ['approve'=>'approved','reject'=>'rejected','fulfill'=>'fulfilled'];
        $new_status = $status_map[$action];
        $extra = $new_status === 'fulfilled' ? ", fulfilled_at=NOW()" : ($new_status === 'approved' ? ", approved_at=NOW(), approved_by=:by" : "");
        $params = [':s'=>$new_status,':n'=>$notes,':id'=>$rid,':p'=>$pid];
        if ($new_status === 'approved') $params[':by'] = $_SESSION['portal_staff_id'] ?? 0;
        $db->prepare("UPDATE inventory_requests SET status=:s, notes=:n$extra WHERE id=:id AND provider_id=:p")->execute($params);
        $success = "Request " . ucfirst($action) . "d.";
    }
}

$status_filter = $_GET['status'] ?? '';
$where  = "ir.provider_id=:p";
$params = [':p'=>$pid];
// Employees only see their own requests
if ($is_emp && $self_emp_id) { $where .= " AND ir.requested_by=:rb"; $params[':rb']=$self_emp_id; }
if ($status_filter) { $where .= " AND ir.status=:s"; $params[':s']=$status_filter; }

$requests = safeAll($db,"SELECT ir.*, e.first_name, e.last_name, e.department,
                          as2.service_name AS booking_service
                   FROM inventory_requests ir
                   LEFT JOIN employees e ON ir.requested_by=e.id
                   LEFT JOIN availed_services as2 ON ir.availed_id=as2.id
                   WHERE $where ORDER BY
                   FIELD(ir.status,'pending','approved','fulfilled','rejected'),
                   ir.created_at DESC",$params);

$pending   = safeCount($db,"SELECT COUNT(*) FROM inventory_requests WHERE provider_id=:p AND status='pending'",[':p'=>$pid]);
$approved  = safeCount($db,"SELECT COUNT(*) FROM inventory_requests WHERE provider_id=:p AND status='approved'",[':p'=>$pid]);
$fulfilled = safeCount($db,"SELECT COUNT(*) FROM inventory_requests WHERE provider_id=:p AND status='fulfilled'",[':p'=>$pid]);

// Bookings for dropdown (active ones)
$active_bookings = safeAll($db,"SELECT id,full_name,service_name FROM availed_services WHERE provider_id=:p AND status IN('preparing','on_the_way','in_progress') ORDER BY preferred_date DESC",[':p'=>$pid]);

$active_menu = 'crm_requests';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventory Requests · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#1abc9c;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.alert-error{background:#fee2e2;border:1px solid rgba(220,38,38,.2);color:#991b1b}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;padding:16px 18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:42px;height:42px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;flex-shrink:0}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.si-teal{background:linear-gradient(135deg,#1abc9c,#16a085)}
.si-green{background:linear-gradient(135deg,#27ae60,#1e8449)}
.stat-info h3{font-size:20px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted);margin-top:2px}
.status-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
.stab{padding:7px 16px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid var(--border);color:var(--muted);background:#fff;transition:all .2s}
.stab.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-success{background:#27ae60;color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-orange{background:#fef3c7;color:#92400e}
.pill-teal{background:#d1fae5;color:#065f46}
.pill-green{background:#dcfce7;color:#166534}
.pill-red{background:#fee2e2;color:#991b1b}
.pill-blue{background:#dbeafe;color:#1e40af}
.pill-gray{background:#f1f5f9;color:#475569}
.urgency-urgent{color:#dc2626;font-weight:700}
.urgency-normal{color:#2563eb}
.urgency-low{color:#6b7280}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
.empty-state i{font-size:36px;opacity:.2;display:block;margin-bottom:10px}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:18px;display:flex;align-items:center;gap:8px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;transition:border .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--primary)}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid var(--border)}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

<?php if (!$tier_is_paid): ?>
<div style="background:#eef2ff;border:1.5px solid #c7d2fe;border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#3730a3">
    <i class="fas fa-lock" style="flex-shrink:0;font-size:16px"></i>
    <div><strong>Free Tier — View Only.</strong> Filing and managing requests requires a Pro subscription. <a href="subscriptions.php" style="color:#4f46e5;font-weight:700">Upgrade to Pro &rarr;</a></div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-box-open"></i> Inventory Requests</h1>
        <p>Request tools or consumables needed for service operations</p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Request
    </button>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><h3><?= $pending ?></h3><p>Pending Requests</p></div></div>
    <div class="stat-card"><div class="stat-icon si-teal"><i class="fas fa-circle-check"></i></div><div class="stat-info"><h3><?= $approved ?></h3><p>Approved</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-check-double"></i></div><div class="stat-info"><h3><?= $fulfilled ?></h3><p>Fulfilled</p></div></div>
</div>

<div class="status-tabs">
    <a href="crm-requests.php" class="stab <?= $status_filter===''?'active':'' ?>">All</a>
    <a href="crm-requests.php?status=pending"   class="stab <?= $status_filter==='pending'?'active':'' ?>">Pending</a>
    <a href="crm-requests.php?status=approved"  class="stab <?= $status_filter==='approved'?'active':'' ?>">Approved</a>
    <a href="crm-requests.php?status=fulfilled" class="stab <?= $status_filter==='fulfilled'?'active':'' ?>">Fulfilled</a>
    <a href="crm-requests.php?status=rejected"  class="stab <?= $status_filter==='rejected'?'active':'' ?>">Rejected</a>
</div>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>Requested By</th><th>Item</th><th>Type</th><th>Qty</th><th>Linked Booking</th><th>Urgency</th><th>Status</th><th>Notes</th><?php if ($portal_role==='owner'): ?><th>Actions</th><?php endif; ?></tr></thead>
    <tbody>
    <?php if (empty($requests)): ?>
    <tr><td colspan="<?= $portal_role==='owner'?9:8 ?>"><div class="empty-state"><i class="fas fa-box-open"></i><p>No requests found.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach ($requests as $r):
        $name = trim(($r['first_name']??'').' '.($r['last_name']??'')) ?: 'Unknown';
        [$pc,$pt] = match($r['status']) {
            'pending'   => ['pill-orange','Pending'],
            'approved'  => ['pill-teal',  'Approved'],
            'fulfilled' => ['pill-green', 'Fulfilled'],
            'rejected'  => ['pill-red',   'Rejected'],
            default     => ['pill-gray',  ucfirst($r['status'])],
        };
    ?>
    <tr>
        <td><strong><?= htmlspecialchars($name) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($r['department']??'') ?></small></td>
        <td><strong><?= htmlspecialchars($r['item_name']) ?></strong></td>
        <td><span class="pill pill-gray"><?= ucfirst($r['item_type']) ?></span></td>
        <td><strong><?= $r['quantity_requested'] ?></strong> <?= htmlspecialchars($r['unit']??'') ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= $r['booking_service'] ? '#'.($r['availed_id']).' — '.htmlspecialchars($r['booking_service']) : '—' ?></td>
        <td><span class="urgency-<?= $r['urgency'] ?>"><?= ucfirst($r['urgency']) ?></span></td>
        <td><span class="pill <?= $pc ?>"><?= $pt ?></span></td>
        <td style="font-size:12px;color:var(--muted);max-width:120px"><?= htmlspecialchars($r['reason']??'—') ?></td>
        <?php if ($portal_role === 'owner'): ?>
        <td style="white-space:nowrap">
            <?php if ($r['status'] === 'pending'): ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i></button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Reject this request?')">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i></button>
            </form>
            <?php elseif ($r['status'] === 'approved'): ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="fulfill">
                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:#8b5cf6;color:#fff"><i class="fas fa-check-double"></i> Fulfill</button>
            </form>
            <?php else: ?>
            <span style="color:var(--muted);font-size:12px">—</span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Add Request Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-box-open" style="color:var(--primary)"></i> New Inventory Request</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group">
        <label>Item Name *</label>
        <input type="text" name="item_name" required placeholder="e.g. Termiticide, Spray Pump">
    </div>
    <div class="form-grid-2">
        <div class="form-group">
            <label>Type *</label>
            <select name="item_type">
                <option value="consumable">Consumable</option>
                <option value="equipment">Equipment</option>
            </select>
        </div>
        <div class="form-group">
            <label>Urgency</label>
            <select name="urgency">
                <option value="normal">Normal</option>
                <option value="urgent">Urgent</option>
                <option value="low">Low</option>
            </select>
        </div>
        <div class="form-group">
            <label>Quantity *</label>
            <input type="number" name="quantity_requested" min="1" value="1" required>
        </div>
        <div class="form-group">
            <label>Unit</label>
            <input type="text" name="unit" placeholder="e.g. liters, pcs, kg">
        </div>
    </div>
    <div class="form-group">
        <label>Linked Booking (optional)</label>
        <select name="availed_id">
            <option value="">— Not linked to a booking —</option>
            <?php foreach ($active_bookings as $ab): ?>
            <option value="<?= $ab['id'] ?>">#<?= $ab['id'] ?> — <?= htmlspecialchars($ab['full_name']) ?> (<?= htmlspecialchars($ab['service_name']??'') ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Reason / Details</label>
        <textarea name="reason" rows="3" placeholder="Why is this item needed?" style="resize:vertical"></textarea>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
    </div>
    </form>
</div>
</div>

<script>
document.querySelectorAll('.modal-overlay').forEach(o =>
    o.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('active'); })
);
</script>
</body></html>