<?php
// GET ?booking=X — full message history + booking context (service, status,
// price, payment state) for one transaction-scoped thread. Marks the
// seeker's unread messages on this booking as read, same as opening the
// thread does on the web.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/transaction_chat_helper.php';

allow('GET');

$user = require_seeker();
$uid = (int)$user['id'];
$db = db();

$bookingId = (int)inp('booking');
if ($bookingId <= 0) {
    fail('booking is required');
}

$booking = getBookingChatContext($db, $bookingId);
if (!$booking || (int)$booking['seeker_uid'] !== $uid) {
    fail('Booking not found', 404);
}

markBookingMessagesRead($db, $bookingId, $uid);

$messages = getBookingMessages($db, $bookingId);

ok([
    'data' => $messages,
    'booking' => array_merge($booking, [
        'chat_open' => chatIsOpenForBooking($booking),
    ]),
]);
