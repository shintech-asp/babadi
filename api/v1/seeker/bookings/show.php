<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$user = require_seeker();
$uid = (int)$user['id'];

$id = (int)inp('id');
if ($id <= 0) {
    fail('Booking ID required');
}

$db = db();

$stmt = $db->prepare(
    "SELECT av.*,
            p.company_name, p.logo_url, u_p.phone AS provider_phone,
            u_p.first_name AS provider_first, u_p.last_name AS provider_last,
            av.total_amount AS total_price, av.paid_amount AS amount_paid,
            av.remaining_amount AS remaining_balance,
            sl.title AS listing_title, sl.price AS listing_price,
            sl.pricing_type, sl.images AS listing_images
     FROM availed_services av
     JOIN providers p ON av.provider_id = p.id
     JOIN users u_p ON u_p.id = p.user_id
     LEFT JOIN service_listings sl ON sl.id = av.service_id
     WHERE av.id = :id AND (av.seeker_user_id = :uid OR av.user_id = :uid2)"
);
$stmt->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found', 404);
}

if (!empty($booking['listing_images'])) {
    $decoded = json_decode($booking['listing_images'], true);
    $booking['listing_images'] = is_array($decoded) ? $decoded : [];
} else {
    $booking['listing_images'] = [];
}

$review_stmt = $db->prepare(
    "SELECT id, rating, feedback FROM service_reviews WHERE avail_id = :id AND seeker_user_id = :uid"
);
$review_stmt->execute([':id' => $id, ':uid' => $uid]);
$review = $review_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

ok(['data' => $booking, 'review' => $review]);
