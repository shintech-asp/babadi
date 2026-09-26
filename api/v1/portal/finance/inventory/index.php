<?php
// GET api/v1/portal/finance/inventory
// Lists the provider's equipment + consumable inventory with live-computed
// availability for equipment (checkout/return model — see
// includes/booking_workflow_helper.php's getCheckedOutQuantity(), and
// CLAUDE.md's "Recent Work Log" for the full design). Mirrors
// provider-portal/inventory.php.
// Access: owner, finance | Tier: Pro required.
//
// Query params: item_type (equipment|consumable, optional), low_stock (1, optional), page, limit
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';

allow('GET');
$staff = require_portal_role('owner', 'finance');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$where = "provider_id = :pid AND is_archived = 0";
$params = [':pid' => $pid];

$item_type = inp('item_type');
if (in_array($item_type, ['equipment', 'consumable'], true)) {
    $where .= " AND item_type = :type";
    $params[':type'] = $item_type;
}

$pdo = db();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM inventory_items WHERE $where ORDER BY item_name ASC LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lowStockOnly = inp('low_stock') === '1';
$out = [];
foreach ($rows as $it) {
    $isEquipment = $it['item_type'] === 'equipment';
    $checkedOut = $isEquipment ? getCheckedOutQuantity($pdo, (int)$it['id']) : 0;
    $availableNow = $isEquipment ? max(0, (int)$it['quantity_available'] - $checkedOut) : (int)$it['quantity_available'];
    $isLowStock = $availableNow <= (int)$it['reorder_threshold'];

    if ($lowStockOnly && !$isLowStock) continue;

    $out[] = [
        'id'                  => (int)$it['id'],
        'item_name'           => $it['item_name'],
        'reference_no'        => $it['reference_no'],
        'item_type'           => $it['item_type'],
        'quantity_available'  => (int)$it['quantity_available'],
        'checked_out'         => $checkedOut,
        'available_now'       => $availableNow,
        'reorder_threshold'   => (int)$it['reorder_threshold'],
        'is_low_stock'        => $isLowStock,
        'unit'                => $it['unit'],
        'unit_price'          => (float)$it['unit_price'],
        'total_value'         => round((float)$it['unit_price'] * (int)$it['quantity_available'], 2),
        'supplier_name'       => $it['supplier_name'],
        'notes'               => $it['notes'],
        'created_at'          => $it['created_at'],
    ];
}

ok([
    'data' => $out,
    'meta' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
    ],
]);
