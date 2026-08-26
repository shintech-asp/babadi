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

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeSum($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(float)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $type = trim($_POST['income_type']??'');
        $amount = (float)($_POST['amount']??0);
        $date = $_POST['income_date']??date('Y-m-d');
        $desc = trim($_POST['description']??'');
        $from = trim($_POST['received_from']??'');
        $method = $_POST['payment_method']??'cash';
        $ref = trim($_POST['reference_number']??'');
        try {
            $db->prepare("INSERT INTO income_records (provider_id,income_type,amount,income_date,description,received_from,payment_method,reference_number) VALUES (:p,:t,:a,:d,:desc,:from,:m,:ref)")
               ->execute([':p'=>$pid,':t'=>$type,':a'=>$amount,':d'=>$date,':desc'=>$desc,':from'=>$from,':m'=>$method,':ref'=>$ref]);
            $success = "Income record added: ₱".number_format($amount,2)." from ".$from;
        } catch(Exception $ex){ $error = $ex->getMessage(); }
    } elseif ($_POST['action'] === 'delete') {
        $rid = (int)($_POST['record_id']??0);
        $db->prepare("DELETE FROM income_records WHERE id=:id AND provider_id=:p")->execute([':id'=>$rid,':p'=>$pid]);
        $success = "Record deleted.";
    }
}

$month_filter = $_GET['month'] ?? date('Y-m');
$records = safeAll($db,"SELECT * FROM income_records WHERE provider_id=:p AND DATE_FORMAT(income_date,'%Y-%m')=:m ORDER BY income_date DESC",[':p'=>$pid,':m'=>$month_filter]);
$service_income = safeAll($db,"SELECT availed_id,service_name,full_name,total_amount,created_at FROM availed_services WHERE provider_id=:p AND status='completed' AND DATE_FORMAT(created_at,'%Y-%m')=:m ORDER BY created_at DESC",[':p'=>$pid,':m'=>$month_filter]);

$total_manual = array_sum(array_column($records,'amount'));
$total_service = array_sum(array_column($service_income,'total_amount'));
$grand_total = $total_manual + $total_service;

$active_menu='income';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Income / Sales - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#27ae60;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
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
.stat-card{background:#fff;padding:18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:44px;height:44px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff}
.si-green{background:linear-gradient(135deg,#27ae60,#16a085)}
.si-teal{background:linear-gradient(135deg,#1abc9c,#16a085)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.stat-info h3{font-size:18px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:10px;margin-bottom:20px}
.filters input{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
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
.badge-teal{background:#ccfbf1;color:#065f46}
.badge-gray{background:#e2e8f0;color:#4a5568}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.empty-state{text-align:center;padding:40px;color:var(--muted)}
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
    <div><strong>Free Tier — Booking Revenue View.</strong> Full income management (manual entries, expense tracking) requires Pro. <a href="subscriptions.php" style="color:#4f46e5;font-weight:700">Upgrade to Pro &rarr;</a></div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-arrow-circle-up"></i> Income / Sales</h1>
        <p><?= date('F Y', strtotime($month_filter.'-01')) ?></p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-plus"></i> Add Income</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-edit"></i></div><div class="stat-info"><h3>₱<?= number_format($total_manual,0) ?></h3><p>Manual Income</p></div></div>
    <div class="stat-card"><div class="stat-icon si-teal"><i class="fas fa-handshake"></i></div><div class="stat-info"><h3>₱<?= number_format($total_service,0) ?></h3><p>Service Revenue</p></div></div>
    <div class="stat-card"><div class="stat-icon si-blue"><i class="fas fa-coins"></i></div><div class="stat-info"><h3>₱<?= number_format($grand_total,0) ?></h3><p>Total Income</p></div></div>
</div>

<form method="GET" class="filters">
    <input type="month" name="month" value="<?= $month_filter ?>" onchange="this.form.submit()">
</form>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-pen"></i> Manual Income Records</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Type</th><th>Amount</th><th>From</th><th>Method</th><th>Reference</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php if(empty($records)): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-arrow-circle-up" style="font-size:32px;opacity:.2;display:block;margin-bottom:10px"></i>No manual income records this month.</div></td></tr>
        <?php endif; ?>
        <?php foreach($records as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars($r['income_type']) ?></strong><?php if($r['description']): ?><br><small style="color:var(--muted)"><?= htmlspecialchars(substr($r['description'],0,50)) ?></small><?php endif; ?></td>
            <td style="color:#27ae60;font-weight:700">₱<?= number_format($r['amount'],2) ?></td>
            <td><?= htmlspecialchars($r['received_from']??'—') ?></td>
            <td><span class="badge badge-gray"><?= ucfirst(str_replace('_',' ',$r['payment_method'])) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['reference_number']??'—') ?></td>
            <td style="font-size:12px"><?= date('M d, Y', strtotime($r['income_date'])) ?></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this record?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="record_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if(!empty($service_income)): ?>
<div class="card">
    <div class="card-header"><h2><i class="fas fa-handshake"></i> Service Revenue (Completed Bookings)</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Service</th><th>Client</th><th>Amount</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach($service_income as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['service_name']??'—') ?></td>
            <td><?= htmlspecialchars($s['full_name']) ?></td>
            <td style="color:#27ae60;font-weight:700">₱<?= number_format($s['total_amount'],2) ?></td>
            <td style="font-size:12px"><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-arrow-up" style="color:var(--primary)"></i> Add Income Record</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label>Income Type *</label><input type="text" name="income_type" placeholder="e.g. Service Fee, Sales, Consultation" required></div>
    <div class="form-group"><label>Amount (₱) *</label><input type="number" name="amount" min="0" step="0.01" required></div>
    <div class="form-group"><label>Date *</label><input type="date" name="income_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="form-group"><label>Received From</label><input type="text" name="received_from"></div>
    <div class="form-group"><label>Payment Method</label>
        <select name="payment_method">
            <option value="cash">Cash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="check">Check</option>
            <option value="credit_card">Credit Card</option>
        </select>
    </div>
    <div class="form-group"><label>Reference Number</label><input type="text" name="reference_number"></div>
    <div class="form-group"><label>Description</label><textarea name="description" rows="2" style="resize:vertical"></textarea></div>
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