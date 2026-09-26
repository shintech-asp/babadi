<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/feedback_media_helper.php';

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
    'SELECT id, provider_id, service_name, status, seeker_user_id, seeker_satisfaction_confirmed_at
     FROM availed_services
     WHERE id = ? AND (seeker_user_id = ? OR user_id = ?)'
);
$stmt->execute([$avail_id, $user['id'], $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

if ($booking['status'] !== 'completed') {
    fail('Feedback can only be submitted for completed bookings.', 403);
}

// Already reviewed FOR THIS COMPLETION CYCLE? Reject instead of silently
// overwriting via ON DUPLICATE KEY. A review left over from an earlier
// completion cycle of this same booking id (re-verified/re-completed) is
// stale and may be overwritten rather than blocking a genuine new review.
$existingStmt = $pdo->prepare(
    'SELECT id, created_at FROM service_reviews WHERE avail_id = ? AND seeker_user_id = ?'
);
$existingStmt->execute([$booking['id'], $user['id']]);
$existingReview = $existingStmt->fetch(PDO::FETCH_ASSOC);

$completedAt   = $booking['seeker_satisfaction_confirmed_at'] ?? null;
$isStaleReview = $existingReview
    && $completedAt
    && strtotime($existingReview['created_at']) < strtotime($completedAt);

if ($existingReview && !$isStaleReview) {
    fail('You have already reviewed this booking.', 409);
}

$imagePath = uploadFeedbackImage('image', (int)$user['id'], (int)$booking['id']);
if ($imagePath === false) {
    fail('Invalid image. Use JPG, PNG, or WEBP under 8MB.', 422);
}

if ($isStaleReview) {
    $stmt = $pdo->prepare(
        'UPDATE service_reviews
            SET service_name = :service_name, rating = :rating, feedback = :feedback,
                feedback_image = :feedback_image, created_at = NOW()
          WHERE id = :review_id'
    );
    $stmt->execute([
        ':service_name'   => $booking['service_name'],
        ':rating'         => $rating,
        ':feedback'       => $feedback,
        ':feedback_image' => $imagePath,
        ':review_id'      => $existingReview['id'],
    ]);
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO service_reviews
            (avail_id, seeker_user_id, provider_id, service_name, rating, feedback, feedback_image, created_at)
         VALUES
            (:avail_id, :seeker_user_id, :provider_id, :service_name, :rating, :feedback, :feedback_image, NOW())'
    );
    $stmt->execute([
        ':avail_id'       => $booking['id'],
        ':seeker_user_id' => $user['id'],
        ':provider_id'    => $booking['provider_id'],
        ':service_name'   => $booking['service_name'],
        ':rating'         => $rating,
        ':feedback'       => $feedback,
        ':feedback_image' => $imagePath,
    ]);
}

ok(['data' => ['message' => 'Feedback submitted successfully']], 201);
