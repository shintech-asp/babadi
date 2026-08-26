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
$allowed = [
    BK_ACCEPTED,
    BK_PREPARING,
    BK_STARTING,
    BK_WAITING_REMAINING,
    BK_WAITING_SEEKER_CONFIRM,
    BK_WAITING_PROVIDER_CONFIRM,
    BK_COMPLETED,
    BK_CANCELLED,
];

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

ok(['message' => 'Status updated', 'status' => $status]);
