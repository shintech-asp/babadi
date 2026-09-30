<?php
// POST api/v1/provider/requests/emergency-accept.php
// Mobile counterpart of provider/service-requests.php's "Accept Emergency"
// action (accept_emergency_now POST handler) — mirrors it exactly, including
// the same accepted->preparing bypass (bare UPDATE + appendAvailedStatusHistory(),
// not prepareAvailedBooking()) since that's the pre-existing web behavior
// being matched here, not something to redesign in this pass.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';
require_once 'includes/availed_booking_helper.php';

allow('POST');

$p   = current_provider();
$pdo = db();

$avail_id = (int) req_inp('avail_id', 'Booking ID');

$fetch = $pdo->prepare(
    "SELECT id, seeker_user_id, service_name, status,
            COALESCE(emergency_now_requested,0) AS emergency_now_requested,
            emergency_now_accepted_at
     FROM availed_services
     WHERE id = :id AND provider_id = :pid
     LIMIT 1"
);
$fetch->execute([':id' => $avail_id, ':pid' => $p['id']]);
$row = $fetch->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail('Emergency request not found.', 404);
}
if ((int)$row['emergency_now_requested'] !== 1) {
    fail('This booking has no pending emergency request.', 422);
}
if (!empty($row['emergency_now_accepted_at'])) {
    ok(['data' => ['message' => 'Emergency request was already accepted.', 'status' => normalizeWorkflowStatus((string)$row['status'])]]);
}

$up = $pdo->prepare(
    "UPDATE availed_services
     SET emergency_now_accepted_at = NOW(),
         status = CASE WHEN status = 'accepted' THEN 'preparing' ELSE status END,
         is_read = 1,
         updated_at = NOW()
     WHERE id = :id AND provider_id = :pid"
);
$up->execute([':id' => $avail_id, ':pid' => $p['id']]);

if ($up->rowCount() === 0) {
    fail('Unable to accept emergency request.', 422);
}

$oldStatus = normalizeWorkflowStatus((string)($row['status'] ?? ''));
$newStatus = $oldStatus === 'accepted' ? 'preparing' : $oldStatus;

appendAvailedStatusHistory(
    $pdo,
    $avail_id,
    $oldStatus,
    $newStatus,
    (int)($p['user_id'] ?? 0),
    'provider',
    'Emergency Service Now request accepted by provider.'
);

if (!empty($row['seeker_user_id'])) {
    try {
        $pdo->prepare(
            "INSERT INTO seeker_notifications
                (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
             VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
        )->execute([
            ':suid'  => (int)$row['seeker_user_id'],
            ':avid'  => $avail_id,
            ':pid'   => $p['id'],
            ':sname' => $row['service_name'] ?? '',
            ':msg'   => 'Your provider accepted your Emergency Service Now request and prioritized your booking.',
        ]);
    } catch (Exception $e) {}
}

ok(['data' => ['message' => 'Emergency request accepted. The seeker has been notified.', 'status' => $newStatus]]);
