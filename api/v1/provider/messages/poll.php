<?php
// GET ?booking=X&since=Y — messages newer than id Y, plus the booking's
// current status/chat_open, for the client's short-interval poll loop.
// Mirrors api/v1/seeker/messages/poll.php exactly.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/transaction_chat_helper.php';

allow('GET');

$p = current_provider();
$db = db();

$bookingId = (int)inp('booking');
$since = (int)inp('since', 0);
if ($bookingId <= 0) {
    fail('booking is required');
}

$booking = getBookingChatContext($db, $bookingId);
if (!$booking || (int)$booking['provider_id'] !== (int)$p['id']) {
    fail('Booking not found', 404);
}

$messages = getBookingMessagesSince($db, $bookingId, $since);
if (!empty($messages)) {
    markBookingMessagesRead($db, $bookingId, (int)$p['user_id']);
}

ok([
    'data' => $messages,
    'status' => $booking['status'],
    'chat_open' => chatIsOpenForBooking($booking),
]);
