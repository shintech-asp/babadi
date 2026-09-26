<?php
// api/v1/admin/bookings/show.php
// GET ?id=<booking_id> — full booking detail with seeker, provider, payment transactions
// Access: super_admin, admin

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin');

$id = (int) inp('id');
if ($id <= 0) {
    fail('id is required', 400);
}

$pdo = db();

// ── Core booking row ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT
         av.*,
         -- Seeker
         COALESCE(av.seeker_user_id, av.user_id)  AS seeker_user_id,
         u_s.first_name   AS seeker_first_name,
         u_s.last_name    AS seeker_last_name,
         u_s.email        AS seeker_email,
         u_s.phone        AS seeker_phone,
         u_s.address      AS seeker_address,
         -- Provider
         p.company_name   AS provider_company_name,
         p.logo_url       AS provider_logo_url,
         u_p.phone        AS provider_phone,
         p.address        AS provider_address,
         u_p.first_name   AS provider_first_name,
         u_p.last_name    AS provider_last_name,
         u_p.email        AS provider_email,
         -- Listing
         sl.service_name  AS listing_title,
         sl.price         AS listing_price,
         sl.pricing_type  AS listing_pricing_type,
         sl.images        AS listing_images
     FROM availed_services av
     JOIN users u_s     ON u_s.id = COALESCE(av.seeker_user_id, av.user_id)
     JOIN providers p   ON p.id   = av.provider_id
     JOIN users u_p     ON u_p.id = p.user_id
     LEFT JOIN services sl ON sl.id = av.service_id
     WHERE av.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found', 404);
}

// Decode JSON listing images array
if (!empty($booking['listing_images'])) {
    $decoded = json_decode($booking['listing_images'], true);
    $booking['listing_images'] = is_array($decoded) ? $decoded : [];
} else {
    $booking['listing_images'] = [];
}

// ── Payment transactions ───────────────────────────────────────────────────────
// Columns: id, availed_service_id, seeker_id, provider_id, amount, payment_type,
//          payment_method, transaction_id, status, receipt_number, receipt_issued_at,
//          created_at, updated_at
$ptStmt = $pdo->prepare(
    "SELECT id, availed_service_id, seeker_id, provider_id, amount, payment_type,
            payment_method, transaction_id, status,
            receipt_number, receipt_issued_at, created_at, updated_at
     FROM payment_transactions
     WHERE availed_service_id = :booking_id
     ORDER BY created_at ASC"
);
$ptStmt->execute([':booking_id' => $id]);
$transactions = $ptStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($transactions as &$tx) {
    $tx['id']     = (int)   $tx['id'];
    $tx['amount'] = (float) $tx['amount'];
}
unset($tx);

// ── Review (if any) ───────────────────────────────────────────────────────────
$revStmt = $pdo->prepare(
    "SELECT id, rating, feedback, feedback_image, created_at
     FROM service_reviews
     WHERE avail_id = :booking_id
     LIMIT 1"
);
$revStmt->execute([':booking_id' => $id]);
$review = $revStmt->fetch(PDO::FETCH_ASSOC) ?: null;

ok([
    'data' => $booking,
    'transactions' => $transactions,
    'review' => $review,
]);
