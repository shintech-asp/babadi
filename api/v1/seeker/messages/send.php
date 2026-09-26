<?php
// POST booking_id, message — sends a message scoped to one booking.
// Routes through sendBookingMessage(), the single choke point that rejects
// the send if the booking's transaction is already closed
// (completed/cancelled) — same rule the web enforces, same function.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/transaction_chat_helper.php';

allow('POST');

$user = require_seeker();
$uid = (int)$user['id'];
$db = db();

$bookingId = (int)req_inp('booking_id', 'Booking ID');
$message = (string)inp('message', '');

$booking = getBookingChatContext($db, $bookingId);
if (!$booking || (int)$booking['seeker_uid'] !== $uid) {
    fail('Booking not found', 404);
}

$result = sendBookingMessage($db, $bookingId, $uid, (int)$booking['provider_user_id'], $message);
if (!$result['ok']) {
    fail($result['error']);
}

ok(['data' => ['id' => $result['id'], 'created_at' => date('Y-m-d H:i:s')]], 201);
