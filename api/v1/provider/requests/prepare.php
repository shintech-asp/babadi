<?php
// POST api/v1/provider/requests/prepare.php
// Mobile counterpart of provider/service-requests.php's "Prepare Booking" /
// "Edit Equipment & Staff" actions — assigns a field technician, an
// optional companion (co-staff), and equipment/consumables to a booking,
// checking out inventory via the shared prepareAvailedBooking(). Works both
// as the one-shot 'accepted' -> 'preparing' trigger AND, called again later,
// as the edit path for a booking that's already 'preparing' — see
// includes/booking_workflow_helper.php for the shared logic (a thin wrapper
// here only re-validates identifiers, no business logic of its own, to
// avoid a third independently-drifting "prepare booking" implementation).
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';

allow('POST');

$p   = current_provider();
$pdo = db();

$avail_id     = (int) req_inp('id', 'Booking ID');
$staff_id     = (int) req_inp('staff_id', 'Field technician');
$companion_id = (int) inp('companion_id', 0);
$notes        = trim((string)(inp('notes') ?? ''));
$equipment    = json_decode((string)(inp('equipment') ?? '[]'), true);
$consumables  = json_decode((string)(inp('consumables') ?? '[]'), true);
$equipment    = is_array($equipment) ? $equipment : [];
$consumables  = is_array($consumables) ? $consumables : [];

// Re-validate staff/companion against this provider's own active field
// employees — same defensive pattern submit-inspection.php already uses,
// never trust ids from the request body.
$staffStmt = $pdo->prepare(
    "SELECT id FROM employees WHERE provider_id = :pid AND status = 'active' AND staff_type = 'field'"
);
$staffStmt->execute([':pid' => $p['id']]);
$validStaffIds = array_map('intval', array_column($staffStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

$companionIdOrNull = $companion_id > 0 ? $companion_id : null;

$result = prepareAvailedBooking(
    $pdo, (int)$p['id'], $avail_id, $staff_id, $validStaffIds,
    $equipment, $consumables, $notes,
    (int)$p['user_id'], 'provider', $companionIdOrNull, $validStaffIds
);

if ($result['type'] === 'error') {
    fail($result['message'], 422);
}

ok(['data' => ['message' => $result['message'], 'status' => 'preparing']]);
