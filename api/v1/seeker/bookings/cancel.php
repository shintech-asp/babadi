<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user = require_seeker();
$id = (int)req_inp('id', 'Booking ID');

if (!$id) {
    fail('Booking ID is required', 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM availed_services WHERE id = :id AND (seeker_user_id = :uid OR user_id = :uid)');
$stmt->execute([':id' => $id, ':uid' => $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found', 404);
}

$status = $booking['status'];
$cancellable = ['pending', 'accepted', 'waiting_for_provider_confirmation'];

if (!in_array($status, $cancellable, true)) {
    fail('Cannot cancel a booking with status: ' . $status, 422);
}

$upd = $pdo->prepare('UPDATE availed_services SET status = :status WHERE id = :id');
$upd->execute([':status' => 'cancelled', ':id' => $id]);

ok(['message' => 'Booking cancelled successfully']);
