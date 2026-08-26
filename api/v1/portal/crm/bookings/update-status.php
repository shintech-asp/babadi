<?php
require_once dirname(__DIR__, 3) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';

allow('POST');

$staff = require_portal_role('owner', 'crm');
portal_require_pro($staff['provider_id']);

$pid = (int)$staff['provider_id'];
$pdo = db();

$id     = (int)req_inp('id', 'Booking ID');
$status = req_inp('status', 'Status');
$notes  = (string)(inp('notes') ?? '');

// Normalize 'ongoing' (UI alias) → 'on_going' (DB value)
if ($status === 'ongoing') {
    $status = 'on_going';
}

// on_going is NOT allowed here — that path requires the QR handshake via scan-qr.php
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
    fail('Invalid status value. on_going requires QR scan via scan-qr endpoint.');
}

// Verify booking ownership
$stmt = $pdo->prepare('SELECT id FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1');
$stmt->execute([':id' => $id, ':pid' => $pid]);
if (!$stmt->fetch()) {
    fail('Booking not found.', 404);
}

$ok = transitionBookingStatus($pdo, $id, $status, (int)$staff['id'], 'portal_crm', $notes);

if (!$ok) {
    fail('Status transition not allowed from current state.', 422);
}

ok(['message' => 'Status updated', 'status' => $status]);
