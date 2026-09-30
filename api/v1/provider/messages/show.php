<?php
// GET ?booking=X — full message history + booking context for one
// transaction-scoped thread, from the provider's side. Marks the
// provider's unread messages on this booking as read. Mirrors
// api/v1/seeker/messages/show.php exactly, ownership check flipped to
// provider_id.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/transaction_chat_helper.php';

allow('GET');

$p = current_provider();
$db = db();

$bookingId = (int)inp('booking');
if ($bookingId <= 0) {
    fail('booking is required');
}

$booking = getBookingChatContext($db, $bookingId);
if (!$booking || (int)$booking['provider_id'] !== (int)$p['id']) {
    fail('Booking not found', 404);
}

markBookingMessagesRead($db, $bookingId, (int)$p['user_id']);

$messages = getBookingMessages($db, $bookingId);

ok([
    'data' => $messages,
    'booking' => array_merge($booking, [
        'chat_open' => chatIsOpenForBooking($booking),
    ]),
]);
