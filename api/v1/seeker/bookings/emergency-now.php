<?php
// POST api/v1/seeker/bookings/emergency-now.php
// Seeker requests immediate ("Emergency Now") service on an already-accepted booking.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user = require_seeker();
$uid  = (int)$user['id'];
$id   = (int)req_inp('avail_id', 'Booking ID');

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT a.id, a.provider_id, a.status, a.payment_status, a.emergency_now_requested,
            p.user_id AS provider_user_id
     FROM availed_services a
     JOIN providers p ON p.id = a.provider_id
     WHERE a.id = :id AND (a.seeker_user_id = :uid OR a.user_id = :uid2)
     LIMIT 1'
);
$stmt->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

if (!in_array($booking['status'], ['accepted', 'preparing'], true)) {
    fail('Emergency Now is only available for accepted or preparing bookings.', 422);
}

if (!in_array($booking['payment_status'], ['paid', 'partial'], true)) {
    fail('Payment must be completed before requesting Emergency Now.', 422);
}

if (!empty($booking['emergency_now_requested'])) {
    fail('Emergency Now has already been requested for this booking.', 422);
}

$newStatus = $booking['status'] === 'accepted' ? 'preparing' : $booking['status'];

$upd = $pdo->prepare(
    'UPDATE availed_services
     SET emergency_now_requested = 1,
         emergency_now_requested_at = NOW(),
         preferred_date = CURDATE(),
         preferred_time = CURTIME(),
         status = :status,
         is_read = 0,
         updated_at = NOW()
     WHERE id = :id
       AND status = :expected_status
       AND COALESCE(emergency_now_requested, 0) = 0'
);
$upd->execute([
    ':status'          => $newStatus,
    ':id'              => $id,
    ':expected_status' => $booking['status'],
]);

if ($upd->rowCount() === 0) {
    fail('This booking has changed status — please refresh and try again.', 409);
}

try {
    if (!empty($booking['provider_user_id'])) {
        $pdo->prepare(
            "INSERT INTO notifications
                (user_id, type, title, message, related_id, related_type, is_read, created_at)
             VALUES (:uid, 'request', 'Emergency Service Now', :message, :related_id, 'availed_service', 0, NOW())"
        )->execute([
            ':uid'        => (int)$booking['provider_user_id'],
            ':message'    => "Booking #$id — the seeker requested Emergency Service Now.",
            ':related_id' => $id,
        ]);
    }
} catch (Exception $e) { /* non-fatal */ }

ok(['data' => ['id' => $id, 'status' => $newStatus, 'emergency_now_requested' => true]]);
