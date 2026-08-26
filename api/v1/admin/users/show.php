<?php
// api/v1/admin/users/show.php
// GET  /api/v1/admin/users/show.php?id=:id
// Returns full user detail + booking count + provider info (if provider).
// Access: super_admin, admin

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin');

$id = (int)inp('id');
if ($id <= 0) fail('id is required', 400);

// ── User row ──────────────────────────────────────────────────────────────────
$stmt = db()->prepare(
    "SELECT
         id, first_name, last_name, email, user_type,
         status, phone, address, city, state, zip_code,
         profile_image, email_verified, created_at, updated_at
     FROM users
     WHERE id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) fail('User not found', 404);

// ── Booking count ─────────────────────────────────────────────────────────────
if ($user['user_type'] === 'provider') {
    $bcStmt = db()->prepare(
        "SELECT COUNT(*)
         FROM availed_services av
         JOIN providers p ON p.id = av.provider_id
         WHERE p.user_id = :id"
    );
} else {
    $bcStmt = db()->prepare(
        "SELECT COUNT(*) FROM availed_services WHERE user_id = :id"
    );
}
$bcStmt->execute([':id' => $id]);
$booking_count = (int)$bcStmt->fetchColumn();

// ── Provider info (only for provider accounts) ────────────────────────────────
$provider_info = null;
if ($user['user_type'] === 'provider') {
    $pStmt = db()->prepare(
        "SELECT
             id, company_name, description, logo_url,
             service_radius, office_lat, office_lng,
             status AS provider_status, created_at AS provider_since
         FROM providers
         WHERE user_id = :uid
         LIMIT 1"
    );
    $pStmt->execute([':uid' => $id]);
    $provider_info = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($provider_info) {
        $provider_info['id']            = (int)$provider_info['id'];
        $provider_info['service_radius'] = (float)($provider_info['service_radius'] ?? 0);
        $provider_info['office_lat']    = $provider_info['office_lat'] !== null
            ? (float)$provider_info['office_lat'] : null;
        $provider_info['office_lng']    = $provider_info['office_lng'] !== null
            ? (float)$provider_info['office_lng'] : null;
    }
}

ok([
    'data' => [
        'id'             => (int)$user['id'],
        'first_name'     => $user['first_name'],
        'last_name'      => $user['last_name'],
        'email'          => $user['email'],
        'user_type'      => $user['user_type'],
        'status'         => $user['status'],
        'phone'          => $user['phone'],
        'address'        => $user['address'],
        'city'           => $user['city'],
        'state'          => $user['state'],
        'zip_code'       => $user['zip_code'],
        'profile_image'  => $user['profile_image'],
        'email_verified' => (bool)$user['email_verified'],
        'created_at'     => $user['created_at'],
        'updated_at'     => $user['updated_at'],
        'booking_count'  => $booking_count,
        'provider_info'  => $provider_info,
    ],
]);
