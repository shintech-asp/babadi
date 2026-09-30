<?php
// Detail — free-tier viewable, same reasoning as index.php.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$staff = require_portal_role('owner', 'crm');

$pid = (int)$staff['provider_id'];
$id  = (int)inp('id');

if ($id <= 0) {
    fail('Booking id is required');
}

$sql = "SELECT av.*,
               u.first_name    AS seeker_first,
               u.last_name     AS seeker_last,
               u.email         AS seeker_email,
               u.phone         AS seeker_phone,
               u.address       AS seeker_address,
               sl.service_name AS listing_title,
               sl.price        AS listing_price,
               sl.pricing_type AS listing_pricing_type,
               sl.images       AS listing_images,
               sc.name         AS category_name
        FROM availed_services av
        JOIN users u ON u.id = COALESCE(av.seeker_user_id, av.user_id)
        LEFT JOIN services sl ON sl.id = av.service_id
        LEFT JOIN service_categories sc ON sc.id = sl.category_id
        WHERE av.id = :id AND av.provider_id = :pid
        LIMIT 1";

$stmt = db()->prepare($sql);
$stmt->execute([':id' => $id, ':pid' => $pid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail('Booking not found', 404);
}

// Decode listing images
if (!empty($row['listing_images'])) {
    $decoded = json_decode($row['listing_images'], true);
    $row['listing_images'] = is_array($decoded) ? $decoded : [];
} else {
    $row['listing_images'] = [];
}

// Build structured response
$data = [
    'id'             => (int)$row['id'],
    'status'         => $row['status'],
    'preferred_date' => $row['preferred_date'],
    'preferred_time' => $row['preferred_time'],
    'address'        => $row['address'],
    'notes'          => $row['notes'],
    'created_at'     => $row['created_at'],
    'updated_at'     => $row['updated_at'],

    'seeker' => [
        'name'    => trim($row['seeker_first'] . ' ' . $row['seeker_last']),
        'email'   => $row['seeker_email'],
        'phone'   => $row['seeker_phone'],
        'address' => $row['seeker_address'],
    ],

    'listing' => [
        'title'        => $row['listing_title'],
        'price'        => $row['listing_price'],
        'pricing_type' => $row['listing_pricing_type'],
        'category'     => $row['category_name'],
        'images'       => $row['listing_images'],
    ],

    'payment' => [
        'method'          => $row['payment_method'],
        'status'          => $row['payment_status'] ?? null,
        'total_amount'    => $row['total_amount'] ?? null,
        'amount_paid'     => $row['paid_amount'] ?? null,
        'remaining_amount'=> $row['remaining_amount'] ?? null,
    ],

    // The seeker's control number is deliberately never returned here — the
    // whole point of the dual-verification handshake is that the seeker
    // reads it out loud to the technician in person; CRM staff being able to
    // look it up on-screen would defeat that (same reasoning already applied
    // to the seeker-side "provider" code on the web dashboard — see
    // CLAUDE.md's "Dual Control Number Verification" and
    // "seeker/payment-success-result.php leaked..." log entries). Only the
    // provider's own code, which staff are meant to share, is included.
    'control_numbers' => [
        'provider' => $row['provider_control_number'],
    ],

    'verification' => [
        'provider_verified_at' => $row['provider_verified_at'],
        'seeker_verified_at'   => $row['seeker_verified_at'],
        'dual_verified_at'     => $row['dual_verified_at'],
    ],

    // qr_token is the seeker's on-arrival proof — the seeker shows it to the
    // technician on-site, who scans/enters it. It must never be readable by
    // CRM staff on a screen (same reasoning as the control number above):
    // that would let staff advance a booking to on_going from the office
    // with no technician anywhere near the site. Previously returned
    // whenever status === 'starting'.
];

ok(['data' => $data]);
