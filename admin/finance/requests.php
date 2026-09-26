<?php
// admin/finance/requests.php
$require_dept = 'finance';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title  = trim($_POST['title']??'');
        $amount = (float)($_POST['amount']??0);
        $dept   = trim($_POST['department']??'');
        $desc   = trim($_POST['description']??'');
        if (!$title || $amount <= 0) { $error = 'Title and amount required.'; }
        else {
            // budget_requests has no title/request_type/priority column and no
            // description column (purpose is the one free-text field) — title
            // and description are combined as "title\ndescription" and split
            // back apart for display below; request_type/priority aren't
            // represented in the schema at all so those inputs are dropped
            // (matches the admin_user_id precedent elsewhere in this codebase:
            // don't invent storage the schema doesn't have). request_date is
            // NOT NULL with no default, so it's stamped as today here.
            $purpose = $desc !== '' ? ($title . "\n" . $desc) : $title;
            $db->prepare("INSERT INTO budget_requests (requested_by,department,purpose,amount,request_date) VALUES(:by,:d,:pu,:a,:rd)")
            ->execute([':by'=>$_SESSION['admin_id'],':d'=>$dept,':pu'=>$purpose,':a'=>$amount,':rd'=>date('Y-m-d')]);
            $success = 'Request submitted.';
        }
    } elseif (in_array($action,['approve','reject'])) {
        $rid = (int)$_POST['req_id'];
        $status = $action==='approve' ? 'approved' : 'rejected';
        // budget_requests has no approved_at column — updated_at (auto-set by
        // the table's own ON UPDATE trigger) is used as the approval timestamp.
        $db->prepare("UPDATE budget_requests SET status=:s,approved_by=:by WHERE id=:id")->execute([':s'=>$status,':by'=>$_SESSION['admin_id'],':id'=>$rid]);
        $success = 'Request '.$status.'.';
    }
}

$requests = $db->query("SELECT br.*, au.full_name as requester_name FROM budget_requests br LEFT JOIN admin_users au ON br.requested_by=au.id ORDER BY br.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$active_menu = 'fin_requests';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Budget Requests - Pestify Finance</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#27ae60;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}.btn-sm{padding:5px 11px;font-size:12px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#c6f6d5;color:#276749;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fafbfc}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-pending{background:#fff3cd;color:#856404}.b-approved{background:#c6f6d5;color:#276749}
.b-rejected{background:#fed7d7;color:#c53030}.b-released{background:#bee3f8;color:#2b6cb0}
.prio-low{color:#718096}.prio-medium{color:#d69e2e}.prio-high{color:#e67e22}.prio-urgent{color:#e53e3e}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:22px}.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
.form-group{margin-bottom:14px}.form-label{display:block;font-size:12px;font-weight:600;color:#2d3748;margin-bottom:5px}
.form-control{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">
<div class="page-header">
    <h1><i class="fas fa-hand-holding-usd"></i> Budget Requests</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')"><i class="fas fa-plus"></i> New Request</button>
</div>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?=$success?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?=$error?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-list"></i> All Requests</h2><span style="font-size:12px;color:var(--muted)"><?=count($requests)?> requests</span></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>#</th><th>Title</th><th>Requested By</th><th>Dept</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (empty($requests)): ?><tr><td colspan="7" style="text-align:center;padding:50px;color:var(--muted)">No requests yet</td></tr><?php endif; ?>
        <?php foreach ($requests as $r):
            // purpose holds "title\ndescription" (see the add handler above) —
            // split back apart for display since there's no separate title column.
            $purposeParts = explode("\n", (string)($r['purpose'] ?? ''), 2);
            $reqTitle = $purposeParts[0] ?? '';
            $reqDesc  = $purposeParts[1] ?? '';
        ?>
        <tr>
            <td style="color:var(--muted);font-size:12px">#<?=$r['id']?></td>
            <td><div style="font-weight:600"><?=htmlspecialchars($reqTitle)?></div><div style="font-size:11px;color:var(--muted)"><?=htmlspecialchars(substr($reqDesc,0,60))?></div></td>
            <td style="font-size:12px"><?=htmlspecialchars($r['requester_name']??'—')?></td>
            <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($r['department']??'—')?></td>
            <td style="font-weight:700;color:#27ae60">₱<?=number_format($r['amount'],2)?></td>
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
                <?php else: ?><span style="font-size:12px;color:var(--muted)"><?=$r['updated_at']?date('M d',strtotime($r['updated_at'])):'—'?></span><?php endif; ?>
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
    <div class="modal-head"><h3><i class="fas fa-plus" style="color:var(--primary);margin-right:8px"></i>New Request</h3>
    <button style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required></div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Amount (₱) *</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
            <div class="form-group"><label class="form-label">Department</label><input type="text" name="department" class="form-control" placeholder="e.g. Operations"></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit</button>
    </div>
    </form>
</div>
</div>
<script>document.getElementById('addModal').addEventListener('click',e=>{if(e.target===document.getElementById('addModal'))e.target.classList.remove('open')});</script>
</body></html>