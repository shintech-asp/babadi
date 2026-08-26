<?php
// api/v1/admin/providers/show.php
// GET — full provider detail: owner user, listings count, completed bookings count.
// Access: super_admin, admin

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin');

$id = (int)inp('id');
if ($id <= 0) fail('id is required', 400);

// Full provider row + owner user fields
$sql = "SELECT
            p.id,
            p.company_name,
            p.description,
            p.address,
            p.city,
            p.state,
            p.zip_code,
            p.logo_url,
            p.status                AS provider_status,
            p.verification_status,
            p.verification_notes,
            p.verification_date,
            p.verification_reviewed_by_name,
            p.office_lat,
            p.office_lng,
            p.created_at,
            p.updated_at,
            u.id                    AS owner_id,
            u.first_name,
            u.last_name,
            u.email                 AS owner_email,
            u.phone                 AS owner_phone,
            u.profile_image,
            u.status                AS owner_status,
            u.created_at            AS owner_created_at
        FROM providers p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = :id
        LIMIT 1";

$stmt = db()->prepare($sql);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) fail('Provider not found', 404);

// Canonical verification status
$verStatus = $row['verification_status'] ?: (
    $row['provider_status'] === 'active'   ? 'approved'  : (
    $row['provider_status'] === 'rejected' ? 'rejected'  : 'pending')
);

// Counts
$countStmt = db()->prepare(
    "SELECT
         (SELECT COUNT(*) FROM service_listings  WHERE provider_id = :id)                                    AS listings_count,
         (SELECT COUNT(*) FROM availed_services  WHERE provider_id = :id)                                    AS total_bookings,
         (SELECT COUNT(*) FROM availed_services  WHERE provider_id = :id AND status = 'completed')           AS completed_bookings"
);
$countStmt->execute([':id' => $id]);
$counts = $countStmt->fetch(PDO::FETCH_ASSOC);

ok([
    'data' => [
        'id'           => (int)$row['id'],
        'company_name' => $row['company_name'],
        'description'  => $row['description'],
        'address'      => $row['address'],
        'city'         => $row['city'],
        'state'        => $row['state'],
        'zip_code'     => $row['zip_code'],
        'logo'         => $row['logo_url'],
        'office_lat'   => $row['office_lat'] !== null ? (float)$row['office_lat'] : null,
        'office_lng'   => $row['office_lng'] !== null ? (float)$row['office_lng'] : null,
        'status'              => $row['provider_status'],
        'verification_status' => $verStatus,
        'verification_notes'  => $row['verification_notes'],
        'verification_date'   => $row['verification_date'],
        'verified_by'         => $row['verification_reviewed_by_name'],
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
        'owner' => [
            'id'            => (int)$row['owner_id'],
            'first_name'    => $row['first_name'],
            'last_name'     => $row['last_name'],
            'email'         => $row['owner_email'],
            'phone'         => $row['owner_phone'],
            'profile_image' => $row['profile_image'],
            'status'        => $row['owner_status'],
            'joined_at'     => $row['owner_created_at'],
        ],
        'stats' => [
            'listings_count'    => (int)$counts['listings_count'],
            'total_bookings'    => (int)$counts['total_bookings'],
            'completed_bookings'=> (int)$counts['completed_bookings'],
        ],
    ],
]);
