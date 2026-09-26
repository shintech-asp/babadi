<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';

allow('GET');

$p  = current_provider();
$id = (int) inp('id');

if ($id <= 0) {
    fail('Request ID required');
}

$db  = db();
$sql = "SELECT av.*,
               u.first_name  AS seeker_first,
               u.last_name   AS seeker_last,
               u.phone       AS seeker_phone,
               u.email       AS seeker_email,
               u.address     AS seeker_address,
               sl.service_name AS title,
               sl.price,
               sl.pricing_type,
               sl.images     AS listing_images,
               COALESCE(sl.requires_inspection, 0) AS requires_inspection
        FROM   availed_services av
        JOIN   users u  ON u.id = COALESCE(av.seeker_user_id, av.user_id)
        LEFT JOIN services sl ON sl.id = av.service_id
        WHERE  av.id = :id
          AND  av.provider_id = :pid";

$stmt = $db->prepare($sql);
$stmt->execute([':id' => $id, ':pid' => $p['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail('Request not found', 404);
}

if (!empty($row['listing_images'])) {
    $decoded = json_decode($row['listing_images'], true);
    $row['listing_images'] = is_array($decoded) ? $decoded : [];
} else {
    $row['listing_images'] = [];
}

// Field staff (for the staff/companion pickers) and live inventory (for the
// equipment/consumables picker) — feeds the mobile "Prepare Booking"/"Edit
// Equipment & Staff" screen, which POSTs to prepare.php. available_now
// excludes THIS booking's own current usage (getCheckedOutQuantity()'s
// $excludeAvailedId) so the ceiling shown is correct whether this is a
// fresh Prepare (nothing held yet, exclusion is a no-op) or an edit of an
// already-'preparing' booking (this booking's own holdings shouldn't count
// against itself) — same logic web's provider/service-requests.php JS
// applies client-side, computed server-side here instead since mobile has
// no equivalent "adjust locally" step.
$staffStmt = $db->prepare(
    "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name
     FROM employees WHERE provider_id = :pid AND status = 'active' AND staff_type = 'field'
     ORDER BY first_name"
);
$staffStmt->execute([':pid' => $p['id']]);
$row['field_staff'] = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

$invStmt = $db->prepare(
    "SELECT id, item_name, item_type, quantity_available, unit
     FROM inventory_items WHERE provider_id = :pid AND is_archived = 0 ORDER BY item_type, item_name"
);
$invStmt->execute([':pid' => $p['id']]);
$inventory = $invStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($inventory as &$invRow) {
    $invRow['available_now'] = $invRow['item_type'] === 'equipment'
        ? max(0, (int)$invRow['quantity_available'] - getCheckedOutQuantity($db, (int)$invRow['id'], $id))
        : (int)$invRow['quantity_available'];
    $invRow['id'] = (int)$invRow['id'];
    $invRow['quantity_available'] = (int)$invRow['quantity_available'];
}
unset($invRow);
$row['inventory'] = $inventory;

$row['assigned_equipment']   = json_decode((string)($row['assigned_equipment'] ?? ''), true) ?: [];
$row['assigned_consumables'] = json_decode((string)($row['assigned_consumables'] ?? ''), true) ?: [];

ok(['data' => $row]);
