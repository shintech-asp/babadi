<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';

allow('POST');

$p   = current_provider();
$pdo = db();

$id     = (int) req_inp('id', 'Booking ID');
$status = req_inp('status', 'Status');
$notes  = (string)(inp('notes') ?? '');

// DB-canonical values only. 'on_going' must come via scan-qr.php (QR handshake).
// 'preparing' is deliberately excluded — it must come via prepare.php, which
// assigns staff/equipment BEFORE the transition (this endpoint has no idea
// about inventory; letting it flip straight to 'preparing' was exactly what
// let equipment silently never get checked out on mobile bookings before).
$allowed = [
    BK_ACCEPTED,
    BK_STARTING,
    BK_WAITING_REMAINING,
    BK_WAITING_SEEKER_CONFIRM,
    BK_WAITING_PROVIDER_CONFIRM,
    BK_COMPLETED,
    BK_CANCELLED,
];

if ($status === BK_PREPARING) {
    fail('Use /provider/requests/prepare.php to assign staff and equipment — that also advances the booking to Preparing.', 422);
}
if (!in_array($status, $allowed, true)) {
    fail('Invalid status value.');
}

$stmt = $pdo->prepare('SELECT id FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1');
$stmt->execute([':id' => $id, ':pid' => $p['id']]);
if (!$stmt->fetch()) {
    fail('Booking not found.', 404);
}

$ok = transitionBookingStatus($pdo, $id, $status, (int)$p['user_id'], 'provider', $notes);

if (!$ok) {
    fail('Status transition not allowed from current state.', 422);
}

ok(['data' => ['message' => 'Status updated', 'status' => $status]]);
