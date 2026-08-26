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
if (!$tier_is_paid) { echo _tierLockedPage('Expenses'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $type = trim($_POST['expense_type']??'');
        $amount = (float)($_POST['amount']??0);
        $date = $_POST['expense_date']??date('Y-m-d');
        $desc = trim($_POST['description']??'');
        $to = trim($_POST['paid_to']??'');
        $method = $_POST['payment_method']??'cash';
        $receipt = trim($_POST['receipt_number']??'');
        $cat = trim($_POST['category']??'');
        try {
            $db->prepare("INSERT INTO expense_records (provider_id,expense_type,amount,expense_date,description,paid_to,payment_method,receipt_number,category) VALUES (:p,:t,:a,:d,:desc,:to,:m,:r,:c)")
               ->execute([':p'=>$pid,':t'=>$type,':a'=>$amount,':d'=>$date,':desc'=>$desc,':to'=>$to,':m'=>$method,':r'=>$receipt,':c'=>$cat]);
            $success = "Expense recorded: ₱".number_format($amount,2)." for $type";
        } catch(Exception $ex){ $error = $ex->getMessage(); }
    } elseif ($_POST['action'] === 'delete') {
        $rid = (int)($_POST['record_id']??0);
        $db->prepare("DELETE FROM expense_records WHERE id=:id AND provider_id=:p")->execute([':id'=>$rid,':p'=>$pid]);
        $success = "Expense deleted.";
    }
}

$month_filter = $_GET['month'] ?? date('Y-m');
$cat_filter = $_GET['cat']??'';
$where = "provider_id=:p AND DATE_FORMAT(expense_date,'%Y-%m')=:m"; $params=[':p'=>$pid,':m'=>$month_filter];
if ($cat_filter) { $where .= " AND category=:c"; $params[':c']=$cat_filter; }

$records = safeAll($db,"SELECT * FROM expense_records WHERE $where ORDER BY expense_date DESC",$params);
$categories = safeAll($db,"SELECT DISTINCT category FROM expense_records WHERE provider_id=:p AND category IS NOT NULL AND category!='' ORDER BY category",[':p'=>$pid]);

$total = array_sum(array_column($records,'amount'));

// Group by category
$by_cat = [];
foreach ($records as $r) {
    $c = $r['category']??'Uncategorized';
    $by_cat[$c] = ($by_cat[$c]??0) + $r['amount'];
}
arsort($by_cat);

$active_menu='expenses';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Expenses - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#e74c3c;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.layout{display:grid;grid-template-columns:220px 1fr;gap:18px}
.cat-list{background:#fff;border-radius:12px;border:1px solid var(--border);padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.07);height:fit-content}
.cat-list h4{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:12px}
.cat-item{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.cat-item:last-child{border-bottom:none}
.cat-name{font-weight:600;color:var(--dark)}
.cat-amount{color:#e74c3c;font-weight:700;font-size:12px}
.total-box{background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;border-radius:10px;padding:14px;margin-bottom:12px;text-align:center}
.total-box p{font-size:11px;opacity:.8;margin-bottom:4px}
.total-box h3{font-size:22px;font-weight:800}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filters input,.filters select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#e2e8f0;color:#4a5568}
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

<div class="page-header">
    <div>
        <h1><i class="fas fa-arrow-circle-down"></i> Expenses</h1>
        <p><?= date('F Y', strtotime($month_filter.'-01')) ?> — <?= count($records) ?> record(s)</p>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('active')" class="btn btn-primary"><i class="fas fa-plus"></i> Add Expense</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<form method="GET" class="filters">
    <input type="month" name="month" value="<?= $month_filter ?>" onchange="this.form.submit()">
    <select name="cat" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach($categories as $c): ?>
        <option value="<?= htmlspecialchars($c['category']) ?>" <?= $cat_filter===$c['category']?'selected':'' ?>><?= htmlspecialchars($c['category']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if($cat_filter): ?><a href="expenses.php?month=<?= $month_filter ?>" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
</form>

<div class="layout">
    <div>
        <div class="total-box"><p>Total Expenses</p><h3>₱<?= number_format($total,0) ?></h3></div>
        <div class="cat-list">
            <h4>By Category</h4>
            <?php if(empty($by_cat)): ?><p style="color:var(--muted);font-size:12px">No data</p><?php endif; ?>
            <?php foreach($by_cat as $cat=>$amt): ?>
            <div class="cat-item">
                <span class="cat-name"><?= htmlspecialchars($cat) ?></span>
                <span class="cat-amount">₱<?= number_format($amt,0) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><i class="fas fa-list"></i> Expense Records</h2></div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Type</th><th>Category</th><th>Amount</th><th>Paid To</th><th>Method</th><th>Receipt</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php if(empty($records)): ?>
            <tr><td colspan="8"><div class="empty-state"><i class="fas fa-receipt" style="font-size:32px;opacity:.2;display:block;margin-bottom:10px"></i>No expense records this month.</div></td></tr>
            <?php endif; ?>
            <?php foreach($records as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['expense_type']) ?></strong><?php if($r['description']): ?><br><small style="color:var(--muted)"><?= htmlspecialchars(substr($r['description'],0,45)) ?></small><?php endif; ?></td>
                <td><span class="badge"><?= htmlspecialchars($r['category']??'—') ?></span></td>
                <td style="color:#e74c3c;font-weight:700">₱<?= number_format($r['amount'],2) ?></td>
                <td><?= htmlspecialchars($r['paid_to']??'—') ?></td>
                <td style="font-size:12px"><?= ucfirst(str_replace('_',' ',$r['payment_method'])) ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['receipt_number']??'—') ?></td>
                <td style="font-size:12px"><?= date('M d, Y', strtotime($r['expense_date'])) ?></td>
                <td>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
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
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3><i class="fas fa-arrow-down" style="color:var(--primary)"></i> Add Expense</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label>Expense Type *</label><input type="text" name="expense_type" placeholder="e.g. Supplies, Utilities, Salary" required></div>
    <div class="form-group"><label>Category</label><input type="text" name="category" placeholder="e.g. Operations, Admin, Marketing" list="cat-list">
        <datalist id="cat-list"><?php foreach($categories as $c): ?><option value="<?= htmlspecialchars($c['category']) ?>"><?php endforeach; ?></datalist>
    </div>
    <div class="form-group"><label>Amount (₱) *</label><input type="number" name="amount" min="0" step="0.01" required></div>
    <div class="form-group"><label>Date *</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="form-group"><label>Paid To</label><input type="text" name="paid_to"></div>
    <div class="form-group"><label>Payment Method</label>
        <select name="payment_method">
            <option value="cash">Cash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="check">Check</option>
            <option value="credit_card">Credit Card</option>
        </select>
    </div>
    <div class="form-group"><label>Receipt Number</label><input type="text" name="receipt_number"></div>
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