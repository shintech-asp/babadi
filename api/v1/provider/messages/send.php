<?php
// POST booking_id, message — sends a message scoped to one booking, from
// the provider's side. Routes through sendBookingMessage(), the same
// choke point the seeker and web surfaces use, so the closed-when-
// completed/cancelled rule can't drift between callers. Mirrors
// api/v1/seeker/messages/send.php exactly.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/transaction_chat_helper.php';

allow('POST');

$p = current_provider();
$db = db();

$bookingId = (int)req_inp('booking_id', 'Booking ID');
$message = (string)inp('message', '');

$booking = getBookingChatContext($db, $bookingId);
if (!$booking || (int)$booking['provider_id'] !== (int)$p['id']) {
    fail('Booking not found', 404);
}

$result = sendBookingMessage($db, $bookingId, (int)$p['user_id'], (int)$booking['seeker_uid'], $message);
if (!$result['ok']) {
    fail($result['error']);
}

ok(['data' => ['id' => $result['id'], 'created_at' => date('Y-m-d H:i:s')]], 201);
