<?php
// POST api/v1/portal/finance/inventory/update
// Edits an existing inventory item's details. Same required-field rules as
// store.php. Deliberately does NOT accept quantity_available — restocking
// (which spends money and must be logged as an expense) is its own
// restock.php endpoint, so a plain correction here can never be mistaken
// for a purchase. See includes/inventory_expense_helper.php.
// Access: owner, finance | Tier: Pro required.
//
// Body: id, item_name, reference_no, item_type, unit_price,
//       unit (optional), reorder_threshold (optional), supplier_name (optional), notes (optional)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$id = (int) req_inp('id', 'id');

$pdo = db();
$chk = $pdo->prepare("SELECT id FROM inventory_items WHERE id = :id AND provider_id = :pid LIMIT 1");
$chk->execute([':id' => $id, ':pid' => $pid]);
if (!$chk->fetch()) fail('Inventory item not found.', 404);

$item_name  = trim((string) req_inp('item_name', 'item_name'));
$reference_no = trim((string) req_inp('reference_no', 'reference_no'));
$item_type  = req_inp('item_type', 'item_type');
$unit_price = inp('unit_price');
$unit       = trim((string) inp('unit', ''));
$reorder    = (int) inp('reorder_threshold', 5);
$supplier   = trim((string) inp('supplier_name', ''));
$notes      = trim((string) inp('notes', ''));

if ($item_name === '') fail('item_name is required.');
if ($reference_no === '') fail('Reference No. is required (SKU, purchase order, or supplier reference — used to trace this item back to where it came from).');
if (!in_array($item_type, ['equipment', 'consumable'], true)) fail('item_type must be equipment or consumable.');
if ($unit_price === null || $unit_price === '' || (float)$unit_price <= 0) fail('Unit price is required and must be greater than 0.');

$pdo->prepare(
    "UPDATE inventory_items
     SET item_name = :name, reference_no = :ref, item_type = :type,
         reorder_threshold = :reorder, unit = :unit, unit_price = :price, supplier_name = :supplier, notes = :notes
     WHERE id = :id AND provider_id = :pid"
)->execute([
    ':name' => $item_name, ':ref' => $reference_no, ':type' => $item_type,
    ':reorder' => $reorder, ':unit' => $unit ?: null, ':price' => (float)$unit_price,
    ':supplier' => $supplier ?: null, ':notes' => $notes ?: null, ':id' => $id, ':pid' => $pid,
]);

ok(['data' => ['id' => $id, 'message' => 'Inventory item updated.']]);
