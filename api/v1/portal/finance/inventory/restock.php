<?php
// POST api/v1/portal/finance/inventory/restock
// Adds quantity to an existing inventory item and logs the purchase as an
// expense at the item's current unit price — the only way to increase
// quantity_available via the API (plain update.php edits never touch it).
// Mirrors provider-portal/inventory.php's 'restock' action.
// Access: owner, finance | Tier: Pro required.
//
// Body: id, add_quantity (positive int)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/inventory_expense_helper.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$id = (int) req_inp('id', 'id');
$addQty = (int) req_inp('add_quantity', 'add_quantity');

if ($addQty <= 0) fail('add_quantity must be greater than 0.');

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = :id AND provider_id = :pid LIMIT 1");
$stmt->execute([':id' => $id, ':pid' => $pid]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) fail('Inventory item not found.', 404);

$pdo->prepare("UPDATE inventory_items SET quantity_available = quantity_available + :q, updated_at = NOW() WHERE id = :id AND provider_id = :pid")
    ->execute([':q' => $addQty, ':id' => $id, ':pid' => $pid]);

recordInventoryPurchaseExpense($pdo, $pid, $item['item_name'], $item['reference_no'], $item['supplier_name'], $addQty, (float)$item['unit_price'], $staff['full_name'] ?? 'Owner');

ok(['data' => [
    'id' => $id,
    'added' => $addQty,
    'new_quantity' => (int)$item['quantity_available'] + $addQty,
    'message' => "Restocked \"{$item['item_name']}\" (+{$addQty}). Logged as an expense.",
]]);
