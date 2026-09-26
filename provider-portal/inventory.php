<?php
// provider-portal/inventory.php — Finance-owned equipment/consumables register.
// Distinct from the per-booking equipment/consumables picker in
// provider/service-requests.php's "Prepare Booking" — this is the actual
// stock/asset register (what's owned, its price, quantity on hand), not tied
// to any specific booking or process. See provider/services.php for the
// separate "Equipment Used" selector that links a service to items here.
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/booking_workflow_helper.php'; // getCheckedOutQuantity()
require_once '../includes/inventory_expense_helper.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

if (!($portal_role === 'owner' || $portal_dept === 'finance' || $portal_dept === 'all')) {
    header('Location: dashboard.php'); exit;
}

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Inventory'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeRow($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){return null;}}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name  = trim($_POST['item_name'] ?? '');
        $ref   = trim($_POST['reference_no'] ?? '');
        $type  = ($_POST['item_type'] ?? 'equipment') === 'consumable' ? 'consumable' : 'equipment';
        $qty   = max(0, (int)($_POST['quantity_available'] ?? 0));
        $unit  = trim($_POST['unit'] ?? '');
        $price = (float)($_POST['unit_price'] ?? 0);
        $reorder = max(0, (int)($_POST['reorder_threshold'] ?? 5));
        $supplier = trim($_POST['supplier_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            $error = 'Item name is required.';
        } elseif ($ref === '') {
            $error = 'Reference No. is required (SKU, purchase order, or supplier reference — used to trace this item back to where it came from).';
        } elseif ($price <= 0) {
            $error = 'Unit price is required and must be greater than 0.';
        } else {
            try {
                $db->prepare(
                    "INSERT INTO inventory_items (provider_id, item_name, reference_no, item_type, quantity_available, unit, unit_price, reorder_threshold, supplier_name, notes)
                     VALUES (:p, :n, :ref, :t, :q, :u, :pr, :r, :s, :no)"
                )->execute([':p'=>$pid, ':n'=>$name, ':ref'=>$ref, ':t'=>$type, ':q'=>$qty, ':u'=>$unit, ':pr'=>$price, ':r'=>$reorder, ':s'=>$supplier, ':no'=>$notes]);
                recordInventoryPurchaseExpense($db, $pid, $name, $ref, $supplier, $qty, $price, $portal_full_name ?? 'Owner');
                $success = "Added \"$name\" to inventory." . ($qty > 0 ? ' Logged as an expense.' : '');
            } catch (Exception $e) { $error = 'Could not add item.'; }
        }
    } elseif ($_POST['action'] === 'restock') {
        $iid   = (int)($_POST['item_id'] ?? 0);
        $addQty = max(0, (int)($_POST['add_quantity'] ?? 0));

        $item = safeRow($db, "SELECT * FROM inventory_items WHERE id=:id AND provider_id=:p", [':id'=>$iid, ':p'=>$pid]);
        if (!$item) {
            $error = 'Item not found.';
        } elseif ($addQty <= 0) {
            $error = 'Enter a quantity greater than 0 to restock.';
        } else {
            $db->prepare("UPDATE inventory_items SET quantity_available = quantity_available + :q, updated_at=NOW() WHERE id=:id AND provider_id=:p")
               ->execute([':q'=>$addQty, ':id'=>$iid, ':p'=>$pid]);
            recordInventoryPurchaseExpense($db, $pid, $item['item_name'], $item['reference_no'], $item['supplier_name'], $addQty, (float)$item['unit_price'], $portal_full_name ?? 'Owner');
            $success = "Restocked \"{$item['item_name']}\" (+{$addQty}). Logged as an expense.";
        }
    } elseif ($_POST['action'] === 'edit') {
        // Deliberately does NOT accept quantity_available — restocking (which
        // spends money and must be logged as an expense) is its own 'restock'
        // action above, so a plain correction here (fixing a typo in the
        // name/price/reference/etc.) can never be mistaken for a purchase.
        $iid   = (int)($_POST['item_id'] ?? 0);
        $name  = trim($_POST['item_name'] ?? '');
        $ref   = trim($_POST['reference_no'] ?? '');
        $type  = ($_POST['item_type'] ?? 'equipment') === 'consumable' ? 'consumable' : 'equipment';
        $unit  = trim($_POST['unit'] ?? '');
        $price = (float)($_POST['unit_price'] ?? 0);
        $reorder = max(0, (int)($_POST['reorder_threshold'] ?? 5));
        $supplier = trim($_POST['supplier_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            $error = 'Item name is required.';
        } elseif ($ref === '') {
            $error = 'Reference No. is required.';
        } elseif ($price <= 0) {
            $error = 'Unit price is required and must be greater than 0.';
        } else {
            $db->prepare(
                "UPDATE inventory_items
                 SET item_name=:n, reference_no=:ref, item_type=:t, unit=:u, unit_price=:pr,
                     reorder_threshold=:r, supplier_name=:s, notes=:no, updated_at=NOW()
                 WHERE id=:id AND provider_id=:p"
            )->execute([':n'=>$name, ':ref'=>$ref, ':t'=>$type, ':u'=>$unit, ':pr'=>$price, ':r'=>$reorder, ':s'=>$supplier, ':no'=>$notes, ':id'=>$iid, ':p'=>$pid]);
            $success = "Updated \"$name\".";
        }
    } elseif ($_POST['action'] === 'archive') {
        $iid = (int)($_POST['item_id'] ?? 0);
        $db->prepare("UPDATE inventory_items SET is_archived=1, archived_at=NOW(), archived_by=:by WHERE id=:id AND provider_id=:p")
           ->execute([':by'=>$portal_full_name ?? 'Owner', ':id'=>$iid, ':p'=>$pid]);
        $success = 'Item archived.';
    }
}

$type_filter = $_GET['type'] ?? '';
$where = "provider_id=:p AND is_archived=0";
$params = [':p'=>$pid];
if (in_array($type_filter, ['equipment','consumable'], true)) { $where .= " AND item_type=:t"; $params[':t']=$type_filter; }

$items = safeAll($db, "SELECT * FROM inventory_items WHERE $where ORDER BY item_type, item_name", $params);

// Equipment isn't consumed — quantity_available is the total owned, and how
// many are free right now is computed live from what's currently checked
// out on active bookings (see getCheckedOutQuantity() in
// booking_workflow_helper.php). Consumables' quantity_available already IS
// the live remaining stock (permanently decremented on use), no computation needed.
foreach ($items as &$it) {
    if ($it['item_type'] === 'equipment') {
        $checkedOut = getCheckedOutQuantity($db, (int)$it['id']);
        $it['available_now'] = max(0, (int)$it['quantity_available'] - $checkedOut);
        $it['checked_out']   = $checkedOut;
    } else {
        $it['available_now'] = (int)$it['quantity_available'];
        $it['checked_out']   = 0;
    }
}
unset($it);

$total_items = count($items);
$total_value = array_sum(array_map(fn($i) => (float)$i['quantity_available'] * (float)$i['unit_price'], $items));
$low_stock   = count(array_filter($items, fn($i) => (int)$i['available_now'] <= (int)$i['reorder_threshold']));

$active_menu = 'inventory';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventory - <?= htmlspecialchars($portal_company) ?></title>
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
.si-purple{background:linear-gradient(135deg,#9b59b6,#8e44ad)}
.si-red{background:linear-gradient(135deg,#e74c3c,#c0392b)}
.stat-info h3{font-size:18px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-danger{background:#e74c3c;color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.filters{display:flex;gap:8px;margin-bottom:20px}
.filters a{padding:7px 14px;border:1px solid var(--border);border-radius:999px;font-size:12px;font-weight:600;color:var(--dark);text-decoration:none;background:#fff}
.filters a.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-green{background:#c6f6d5;color:#276749}
.badge-purple{background:#f0e6ff;color:#6b46c1}
.badge-red{background:#fed7d7;color:#c53030}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
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

<div class="page-header">
    <div>
        <h1><i class="fas fa-boxes-stacked"></i> Inventory</h1>
        <p>Equipment and consumables your business owns — quantity on hand and value. Not tied to any booking.</p>
    </div>
    <button onclick="openAddModal()" class="btn btn-primary"><i class="fas fa-plus"></i> Add Item</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-purple"><i class="fas fa-cubes"></i></div><div class="stat-info"><h3><?= $total_items ?></h3><p>Total Items</p></div></div>
    <div class="stat-card"><div class="stat-icon si-green"><i class="fas fa-sack-dollar"></i></div><div class="stat-info"><h3>₱<?= number_format($total_value,0) ?></h3><p>Total Inventory Value</p></div></div>
    <div class="stat-card"><div class="stat-icon si-red"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-info"><h3><?= $low_stock ?></h3><p>Low Stock Items</p></div></div>
</div>

<div class="filters">
    <a href="?type=" class="<?= $type_filter === '' ? 'active' : '' ?>">All</a>
    <a href="?type=equipment" class="<?= $type_filter === 'equipment' ? 'active' : '' ?>">Equipment</a>
    <a href="?type=consumable" class="<?= $type_filter === 'consumable' ? 'active' : '' ?>">Consumables</a>
</div>

<div class="card">
<div style="overflow-x:auto">
<table>
    <thead><tr><th>Item</th><th>Reference No.</th><th>Type</th><th>Quantity (Available / Owned)</th><th>Unit Price</th><th>Total Value</th><th>Supplier</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="9"><div class="empty-state"><i class="fas fa-boxes-stacked" style="font-size:36px;opacity:.2;display:block;margin-bottom:10px"></i><p>No inventory items yet.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach ($items as $it): $lowStock = (int)$it['available_now'] <= (int)$it['reorder_threshold']; ?>
    <tr>
        <td><strong><?= htmlspecialchars($it['item_name']) ?></strong><?php if (!empty($it['notes'])): ?><br><small style="color:var(--muted)"><?= htmlspecialchars($it['notes']) ?></small><?php endif; ?></td>
        <td><code style="font-size:11px;background:#f0e6ff;color:#6b46c1;padding:2px 6px;border-radius:5px"><?= htmlspecialchars($it['reference_no'] ?? '—') ?></code></td>
        <td><span class="badge <?= $it['item_type']==='equipment'?'badge-purple':'badge-green' ?>"><?= ucfirst($it['item_type']) ?></span></td>
        <td>
            <?php if ($it['item_type'] === 'equipment'): ?>
                <?= (int)$it['available_now'] ?> / <?= (int)$it['quantity_available'] ?> <?= htmlspecialchars($it['unit'] ?? '') ?>
                <?php if ($it['checked_out'] > 0): ?><br><small style="color:var(--muted)"><?= (int)$it['checked_out'] ?> checked out</small><?php endif; ?>
            <?php else: ?>
                <?= (int)$it['quantity_available'] ?> <?= htmlspecialchars($it['unit'] ?? '') ?>
            <?php endif; ?>
        </td>
        <td>₱<?= number_format((float)$it['unit_price'],2) ?></td>
        <td style="font-weight:700">₱<?= number_format((float)$it['quantity_available'] * (float)$it['unit_price'],2) ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($it['supplier_name'] ?: '—') ?></td>
        <td><?php if ($lowStock): ?><span class="badge badge-red">Low Stock</span><?php else: ?><span class="badge badge-green">OK</span><?php endif; ?></td>
        <td>
            <button type="button" class="btn btn-outline btn-sm" onclick='openRestockModal(<?= (int)$it['id'] ?>, <?= json_encode($it['item_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Restock (logs an expense)"><i class="fas fa-truck-loading"></i></button>
            <button type="button" class="btn btn-outline btn-sm" onclick='openEditModal(<?= json_encode($it, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit details"><i class="fas fa-edit"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Archive this item?')">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-box-archive"></i></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="itemModal">
<div class="modal">
    <h3 id="itemModalTitle"><i class="fas fa-plus-circle" style="color:var(--primary)"></i> Add Inventory Item</h3>
    <form method="POST" id="itemForm">
    <input type="hidden" name="action" id="itemFormAction" value="add">
    <input type="hidden" name="item_id" id="itemFormId" value="">
    <div class="form-group"><label>Item Name *</label><input type="text" name="item_name" id="f_item_name" required></div>
    <div class="form-group"><label>Reference No. *</label><input type="text" name="reference_no" id="f_reference_no" placeholder="SKU, purchase order #, or supplier reference" required></div>
    <div class="form-grid">
        <div class="form-group">
            <label>Type *</label>
            <select name="item_type" id="f_item_type">
                <option value="equipment">Equipment</option>
                <option value="consumable">Consumable</option>
            </select>
        </div>
        <div class="form-group">
            <label>Unit</label>
            <select id="f_unit_select" onchange="onUnitSelectChange(this)">
                <option value="pcs">pcs</option>
                <option value="sets">sets</option>
                <option value="pairs">pairs</option>
                <option value="units">units</option>
                <option value="boxes">boxes</option>
                <option value="packs">packs</option>
                <option value="rolls">rolls</option>
                <option value="bottles">bottles</option>
                <option value="sachets">sachets</option>
                <option value="liters">liters</option>
                <option value="gallons">gallons</option>
                <option value="kg">kg</option>
                <option value="grams">grams</option>
                <option value="__other__">Other…</option>
            </select>
            <input type="text" name="unit" id="f_unit" placeholder="Custom unit" style="display:none;margin-top:6px;">
        </div>
        <div class="form-group" id="qtyFieldWrap"><label>Starting Quantity</label><input type="number" name="quantity_available" id="f_qty" min="0" value="0">
            <small style="color:var(--muted);display:block;margin-top:3px;">Logged as an expense automatically.</small>
        </div>
        <div class="form-group"><label>Unit Price (₱) *</label><input type="number" name="unit_price" id="f_price" min="0.01" step="0.01" required></div>
        <div class="form-group"><label>Reorder Threshold</label><input type="number" name="reorder_threshold" id="f_reorder" min="0" value="5"></div>
        <div class="form-group"><label>Supplier</label><input type="text" name="supplier_name" id="f_supplier"></div>
    </div>
    <div class="form-group"><label>Notes</label><textarea name="notes" id="f_notes" rows="2" style="resize:vertical"></textarea></div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('itemModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
    </div>
    </form>
</div>
</div>

<!-- Restock Modal (adds quantity + logs an expense; separate from Edit) -->
<div class="modal-overlay" id="restockModal">
<div class="modal">
    <h3><i class="fas fa-truck-loading" style="color:var(--primary)"></i> Restock Item</h3>
    <form method="POST">
        <input type="hidden" name="action" value="restock">
        <input type="hidden" name="item_id" id="rs_item_id" value="">
        <div class="form-group"><label>Item</label><input type="text" id="rs_item_name" disabled></div>
        <div class="form-group"><label>Quantity to Add *</label><input type="number" name="add_quantity" id="rs_add_qty" min="1" value="1" required></div>
        <p style="font-size:12px;color:var(--muted);margin:-4px 0 14px;">This will be logged as an expense at the item's current unit price.</p>
        <div class="modal-footer">
            <button type="button" onclick="document.getElementById('restockModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Restock</button>
        </div>
    </form>
</div>
</div>

<script>
function onUnitSelectChange(sel) {
    const isOther = sel.value === '__other__';
    const other = document.getElementById('f_unit');
    other.style.display = isOther ? '' : 'none';
    if (!isOther) other.value = sel.value;
    else if (other.dataset.preset) { other.value = ''; delete other.dataset.preset; }
}
function setUnitField(unit) {
    const select = document.getElementById('f_unit_select');
    const other = document.getElementById('f_unit');
    const options = Array.from(select.options).map(o => o.value);
    if (unit && !options.includes(unit)) {
        select.value = '__other__';
        other.value = unit;
        other.style.display = '';
    } else {
        select.value = unit || 'pcs';
        other.value = select.value;
        other.style.display = 'none';
    }
}
function openAddModal() {
    document.getElementById('itemModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color:var(--primary)"></i> Add Inventory Item';
    document.getElementById('itemFormAction').value = 'add';
    document.getElementById('itemFormId').value = '';
    document.getElementById('itemForm').reset();
    setUnitField('pcs');
    document.getElementById('qtyFieldWrap').style.display = '';
    document.getElementById('itemModal').classList.add('active');
}
function openEditModal(item) {
    document.getElementById('itemModalTitle').innerHTML = '<i class="fas fa-edit" style="color:var(--primary)"></i> Edit Inventory Item';
    document.getElementById('itemFormAction').value = 'edit';
    document.getElementById('itemFormId').value = item.id;
    document.getElementById('f_item_name').value = item.item_name;
    document.getElementById('f_reference_no').value = item.reference_no || '';
    document.getElementById('f_item_type').value = item.item_type;
    setUnitField(item.unit || '');
    document.getElementById('f_price').value = item.unit_price;
    document.getElementById('f_reorder').value = item.reorder_threshold;
    document.getElementById('f_supplier').value = item.supplier_name || '';
    document.getElementById('f_notes').value = item.notes || '';
    // Quantity is not editable here — use Restock to add stock (logs an expense).
    document.getElementById('qtyFieldWrap').style.display = 'none';
    document.getElementById('itemModal').classList.add('active');
}
function openRestockModal(itemId, itemName) {
    document.getElementById('rs_item_id').value = itemId;
    document.getElementById('rs_item_name').value = itemName;
    document.getElementById('rs_add_qty').value = 1;
    document.getElementById('restockModal').classList.add('active');
}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));
</script>

</div></div></div>
</body></html>
