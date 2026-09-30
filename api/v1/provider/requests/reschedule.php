<?php
// POST api/v1/provider/requests/reschedule.php
// Mobile counterpart of provider/service-requests.php's "Request Reschedule"
// action (submit_reschedule_request POST handler) — provider proposes a new
// date/time for an accepted/preparing booking; the seeker then accepts or
// rejects it (already built on mobile via seeker/bookings/reschedule-respond.php).
// Validation (working-hours/slot fit, booking conflicts) reuses the exact
// same shared helpers the web page now calls — see
// includes/booking_workflow_helper.php.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';
require_once 'includes/availed_booking_helper.php';

allow('POST');

$p   = current_provider();
$pdo = db();

$avail_id          = (int) req_inp('avail_id', 'Booking ID');
$proposed_date     = trim((string) req_inp('reschedule_date', 'Reschedule date'));
$proposed_time     = trim((string) req_inp('reschedule_time', 'Reschedule time'));
$reschedule_reason = trim((string) req_inp('reschedule_reason', 'Reason'));

$stmt = $pdo->prepare(
    "SELECT id, status, seeker_user_id, service_name, preferred_date, preferred_time
     FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1"
);
$stmt->execute([':id' => $avail_id, ':pid' => $p['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

$currentStatus = normalizeWorkflowStatus((string)($booking['status'] ?? ''));
if (!in_array($currentStatus, ['accepted', 'preparing'], true)) {
    fail('Rescheduling is only available for accepted or preparing bookings.', 422);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $proposed_date) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $proposed_time)) {
    fail('Please choose a valid reschedule date and time.', 422);
}

$proposedStamp = strtotime($proposed_date . ' ' . $proposed_time . ':00');
if ($proposedStamp === false || $proposedStamp <= time()) {
    fail('The proposed schedule must be in the future.', 422);
}
if ($proposed_date === (string)$booking['preferred_date'] && substr((string)$booking['preferred_time'], 0, 5) === $proposed_time) {
    fail('Choose a different schedule before sending a reschedule request.', 422);
}
if (!proposedTimeFitsProviderSchedule($pdo, (int)$p['id'], $proposed_date, $proposed_time)) {
    fail('The proposed time is outside your configured working hours or slot length.', 422);
}
if (providerHasBookingConflict($pdo, (int)$p['id'], $avail_id, $proposed_date, $proposed_time . ':00')) {
    fail('That schedule conflicts with another active booking.', 422);
}

$updateStmt = $pdo->prepare(
    "UPDATE availed_services
     SET reschedule_request_status = 'pending',
         reschedule_requested_at = NOW(),
         reschedule_proposed_date = :proposed_date,
         reschedule_proposed_time = :proposed_time,
         reschedule_reason = :reason,
         reschedule_responded_at = NULL,
         provider_verified_at = NULL,
         seeker_verified_at = NULL,
         dual_verified_at = NULL,
         updated_at = NOW()
     WHERE id = :id AND provider_id = :pid"
);
$updateStmt->execute([
    ':proposed_date' => $proposed_date,
    ':proposed_time' => $proposed_time . ':00',
    ':reason' => $reschedule_reason,
    ':id' => $avail_id,
    ':pid' => $p['id'],
]);

if (!empty($booking['seeker_user_id'])) {
    $currentSchedule = date('M j, Y', strtotime((string)$booking['preferred_date'])) . ' at ' . date('g:i A', strtotime((string)$booking['preferred_time']));
    $newSchedule = date('M j, Y', strtotime($proposed_date)) . ' at ' . date('g:i A', strtotime($proposed_time));
    $notifMessage =
        'Your provider proposed a reschedule for "' . ($booking['service_name'] ?? 'Service') . '". '
        . 'Current schedule: ' . $currentSchedule . '. '
        . 'Proposed schedule: ' . $newSchedule . '. '
        . 'Reason: ' . $reschedule_reason . '. '
        . 'Please review this in My Bookings.';

    notifySeekerForAvailedBooking(
        $pdo,
        (int)$booking['seeker_user_id'],
        $avail_id,
        (int)$p['id'],
        (string)($booking['service_name'] ?? ''),
        'message',
        $notifMessage
    );
}

appendAvailedStatusHistory(
    $pdo,
    $avail_id,
    $currentStatus,
    $currentStatus,
    (int)($p['user_id'] ?? 0),
    'provider',
    'Provider proposed a reschedule to ' . $proposed_date . ' ' . $proposed_time . '. Reason: ' . $reschedule_reason
);

ok(['data' => ['message' => 'Reschedule request sent to the seeker for confirmation.', 'status' => $currentStatus]]);
