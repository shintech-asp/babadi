<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user = require_seeker();

$avail_id = req_inp('avail_id', 'Booking ID');
$rating   = req_inp('rating', 'Rating');
$feedback = inp('feedback');

$rating = filter_var($rating, FILTER_VALIDATE_INT);
if ($rating === false || $rating < 1 || $rating > 5) {
    fail('Rating must be an integer between 1 and 5.');
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT id, provider_id, service_name, status, seeker_user_id
     FROM availed_services
     WHERE id = ? AND seeker_user_id = ?'
);
$stmt->execute([$avail_id, $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

if ($booking['status'] !== 'completed') {
    fail('Feedback can only be submitted for completed bookings.', 403);
}

$stmt = $pdo->prepare(
    'INSERT INTO service_reviews
        (avail_id, seeker_user_id, provider_id, service_name, rating, feedback, created_at)
     VALUES
        (:avail_id, :seeker_user_id, :provider_id, :service_name, :rating, :feedback, NOW())
     ON DUPLICATE KEY UPDATE
        rating     = VALUES(rating),
        feedback   = VALUES(feedback),
        created_at = NOW()'
);
$stmt->execute([
    ':avail_id'      => $booking['id'],
    ':seeker_user_id'=> $user['id'],
    ':provider_id'   => $booking['provider_id'],
    ':service_name'  => $booking['service_name'],
    ':rating'        => $rating,
    ':feedback'      => $feedback,
]);

ok(['message' => 'Feedback submitted successfully'], 201);
