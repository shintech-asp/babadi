<?php
// POST api/v1/portal/finance/inventory/store
// Adds an inventory item (equipment or consumable) — mirrors
// provider-portal/inventory.php's 'add' action. Reference No. and a
// positive unit price are required (traceability + valuation).
// Access: owner, finance | Tier: Pro required.
//
// Body: item_name, reference_no, item_type (equipment|consumable),
//       quantity_available, unit_price, unit (optional), reorder_threshold (optional),
//       supplier_name (optional), notes (optional)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/inventory_expense_helper.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$item_name  = trim((string) req_inp('item_name', 'item_name'));
$reference_no = trim((string) req_inp('reference_no', 'reference_no'));
$item_type  = req_inp('item_type', 'item_type');
$unit_price = inp('unit_price');
$quantity   = (int) inp('quantity_available', 0);
$unit       = trim((string) inp('unit', ''));
$reorder    = (int) inp('reorder_threshold', 5);
$supplier   = trim((string) inp('supplier_name', ''));
$notes      = trim((string) inp('notes', ''));

if ($item_name === '') fail('item_name is required.');
if ($reference_no === '') fail('Reference No. is required (SKU, purchase order, or supplier reference — used to trace this item back to where it came from).');
if (!in_array($item_type, ['equipment', 'consumable'], true)) fail('item_type must be equipment or consumable.');
if ($unit_price === null || $unit_price === '' || (float)$unit_price <= 0) fail('Unit price is required and must be greater than 0.');
if ($quantity < 0) fail('quantity_available cannot be negative.');

$pdo = db();
$stmt = $pdo->prepare(
    "INSERT INTO inventory_items
        (provider_id, item_name, reference_no, item_type, quantity_available, reorder_threshold, unit, unit_price, supplier_name, notes, created_at)
     VALUES
        (:pid, :name, :ref, :type, :qty, :reorder, :unit, :price, :supplier, :notes, NOW())"
);
$stmt->execute([
    ':pid' => $pid, ':name' => $item_name, ':ref' => $reference_no, ':type' => $item_type,
    ':qty' => $quantity, ':reorder' => $reorder, ':unit' => $unit ?: null,
    ':price' => (float)$unit_price, ':supplier' => $supplier ?: null, ':notes' => $notes ?: null,
]);
$newId = (int)$pdo->lastInsertId();

recordInventoryPurchaseExpense($pdo, $pid, $item_name, $reference_no, $supplier ?: null, $quantity, (float)$unit_price, $staff['full_name'] ?? 'Owner');

ok(['data' => ['id' => $newId, 'message' => 'Inventory item added.' . ($quantity > 0 ? ' Logged as an expense.' : '')]], 201);
