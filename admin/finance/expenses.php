<?php
// admin/finance/expenses.php
$require_dept = 'finance';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add') {
    $desc   = trim($_POST['description']??'');
    $amount = (float)($_POST['amount']??0);
    $date   = $_POST['date'] ?? date('Y-m-d');
    $cat    = $_POST['category'] ?? 'other';
    $vendor = trim($_POST['vendor']??'');
    $method = $_POST['payment_method'] ?? 'cash';
    $notes  = trim($_POST['notes']??'');
    $ref    = 'EXP-' . strtoupper(substr(uniqid(),0,8));
    if (!$desc || $amount <= 0) { $error = 'Description and amount are required.'; }
    else {
        // expense_records' real columns are expense_date/receipt_number/paid_to
        // (not date/reference_no/vendor), and it has no notes, recorded_by or
        // status column at all — notes are folded into description instead of
        // being silently dropped; recorded_by/status are left out (nothing reads
        // them, and every row here already represents a finalized expense).
        // expense_type is a separate NOT NULL column with no default (distinct
        // from the nullable category column the page's filter dropdown uses) —
        // populated with the same category value so both stay in sync.
        $descWithNotes = $notes !== '' ? ($desc . ' — ' . $notes) : $desc;
        $db->prepare("INSERT INTO expense_records (receipt_number,category,expense_type,description,amount,expense_date,paid_to,payment_method) VALUES(:r,:c,:et,:d,:a,:dt,:v,:m)")
        ->execute([':r'=>$ref,':c'=>$cat,':et'=>$cat,':d'=>$descWithNotes,':a'=>$amount,':dt'=>$date,':v'=>$vendor,':m'=>$method]);
        $success = "Expense recorded. Ref: $ref";
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    $db->prepare("DELETE FROM expense_records WHERE id=:id")->execute([':id'=>(int)$_POST['rec_id']]);
    $success = 'Record deleted.';
}

$month   = $_GET['month'] ?? date('Y-m');
$cat_f   = $_GET['cat'] ?? '';
$sql     = "SELECT *, receipt_number AS reference_no, expense_date AS date, paid_to AS vendor FROM expense_records WHERE DATE_FORMAT(expense_date,'%Y-%m')=:m";
$params  = [':m'=>$month];
if ($cat_f) { $sql .= " AND category=:c"; $params[':c']=$cat_f; }
$sql .= " ORDER BY expense_date DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_month = array_sum(array_column($records,'amount'));

$active_menu = 'expenses';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Expenses - Pestify Finance</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#e74c3c;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}.btn-sm{padding:5px 11px;font-size:12px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#c6f6d5;color:#276749;border-left:4px solid #27ae60}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.total-bar{background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;border-radius:14px;padding:22px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center}
.total-bar h2{font-size:32px;font-weight:800}.total-bar p{font-size:13px;opacity:.85}
.filters{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.filters input,.filters select{padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:15px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}tbody tr:hover{background:#fff8f8}
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
    <h1><i class="fas fa-arrow-circle-down"></i> Expenses</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')"><i class="fas fa-plus"></i> Add Expense</button>
</div>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?=$success?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?=$error?></div><?php endif; ?>
<div class="total-bar">
    <div><p>Total Expenses for <?= date('F Y', strtotime($month.'-01')) ?></p><h2>₱<?= number_format($total_month,2) ?></h2></div>
    <i class="fas fa-receipt" style="font-size:48px;opacity:.3"></i>
</div>
<form method="GET" class="filters">
    <input type="month" name="month" value="<?=htmlspecialchars($month)?>">
    <select name="cat">
        <option value="">All Categories</option>
        <?php foreach(['supplies','equipment','utilities','salaries','rent','transport','marketing','maintenance','other'] as $c): ?>
        <option value="<?=$c?>" <?=$cat_f===$c?'selected':''?>><?=ucfirst($c)?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    <a href="expenses.php" class="btn" style="background:#f0f0f0;color:#555">Reset</a>
</form>
<div class="card">
    <div class="card-header"><h2><i class="fas fa-list"></i> Expense Records</h2><span style="font-size:12px;color:var(--muted)"><?=count($records)?> records</span></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Ref No.</th><th>Description</th><th>Category</th><th>Vendor</th><th>Payment</th><th>Amount</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (empty($records)): ?><tr><td colspan="8" style="text-align:center;padding:50px;color:var(--muted)">No expense records for this period</td></tr><?php endif; ?>
        <?php foreach ($records as $r): ?>
        <tr>
            <td><code style="font-size:11px;background:#fff5f5;color:#c53030;padding:2px 7px;border-radius:5px"><?=htmlspecialchars($r['reference_no']??'—')?></code></td>
            <td style="font-weight:500"><?=htmlspecialchars($r['description'])?></td>
            <td><span style="background:#fff5f5;color:#c53030;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700"><?=ucfirst($r['category']??'—')?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($r['vendor']??'—')?></td>
            <td style="font-size:12px;color:var(--muted)"><?=ucfirst(str_replace('_',' ',$r['payment_method']??''))?></td>
            <td style="font-weight:700;color:#e74c3c;font-size:15px">-₱<?=number_format($r['amount'],2)?></td>
            <td style="font-size:12px;color:var(--muted)"><?=date('M d, Y',strtotime($r['date']))?></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="rec_id" value="<?=$r['id']?>">
                    <button class="btn btn-sm" style="background:#fed7d7;color:#c53030" type="submit"><i class="fas fa-trash"></i></button>
                </form>
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
    <div class="modal-head"><h3><i class="fas fa-minus" style="color:var(--primary);margin-right:8px"></i>Add Expense</h3>
    <button style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Description *</label><input type="text" name="description" class="form-control" required></div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Amount (₱) *</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
            <div class="form-group"><label class="form-label">Date *</label><input type="date" name="date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
        </div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <?php foreach(['supplies','equipment','utilities','salaries','rent','transport','marketing','maintenance','other'] as $c): ?>
                    <option value="<?=$c?>"><?=ucfirst($c)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Payment Method</label>
                <select name="payment_method" class="form-control">
                    <option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank_transfer">Bank Transfer</option><option value="check">Check</option>
                </select>
            </div>
        </div>
        <div class="form-group"><label class="form-label">Vendor</label><input type="text" name="vendor" class="form-control" placeholder="Supplier/vendor name"></div>
        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
    </div>
    </form>
</div>
</div>
<script>document.getElementById('addModal').addEventListener('click',e=>{if(e.target===document.getElementById('addModal'))e.target.classList.remove('open')});</script>
</body></html>