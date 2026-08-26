<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$p  = current_provider();
$id = (int) inp('id');

if ($id <= 0) {
    fail('Request ID required');
}

$db  = db();
$sql = "SELECT av.*,
               u.first_name  AS seeker_first,
               u.last_name   AS seeker_last,
               u.phone       AS seeker_phone,
               u.email       AS seeker_email,
               u.address     AS seeker_address,
               sl.title,
               sl.price,
               sl.pricing_type,
               sl.images     AS listing_images
        FROM   availed_services av
        JOIN   users u  ON u.id = COALESCE(av.seeker_user_id, av.user_id)
        LEFT JOIN service_listings sl ON sl.id = av.listing_id
        WHERE  av.id = :id
          AND  av.provider_id = :pid";

$stmt = $db->prepare($sql);
$stmt->execute([':id' => $id, ':pid' => $p['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail('Request not found', 404);
}

if (!empty($row['listing_images'])) {
    $decoded = json_decode($row['listing_images'], true);
    $row['listing_images'] = is_array($decoded) ? $decoded : [];
} else {
    $row['listing_images'] = [];
}

ok(['data' => $row]);
