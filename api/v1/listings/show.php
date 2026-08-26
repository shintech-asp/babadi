<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$id = (int)inp('id');
if ($id <= 0) {
    fail('Listing ID required');
}

$db = db();

$stmt = $db->prepare(
    "SELECT sl.*, p.company_name, p.logo_url, p.service_radius, p.description AS provider_description,
            p.city AS provider_city, p.address AS provider_address,
            u.first_name AS provider_first, u.last_name AS provider_last,
            sc.name AS category_name,
            ROUND(AVG(r.rating),1) AS avg_rating, COUNT(r.id) AS review_count
     FROM service_listings sl
     JOIN providers p ON sl.provider_id = p.id
     JOIN users u ON u.id = p.user_id
     JOIN service_categories sc ON sl.category_id = sc.id
     LEFT JOIN service_reviews r ON r.provider_id = p.id
     WHERE sl.id = :id AND sl.status = 'active'
     GROUP BY sl.id"
);
$stmt->execute([':id' => $id]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$listing) {
    fail('Listing not found', 404);
}

$listing['images'] = json_decode($listing['images'] ?? '[]', true) ?: [];

$upd = $db->prepare("UPDATE service_listings SET views_count = views_count + 1 WHERE id = :id");
$upd->execute([':id' => $id]);

$rev = $db->prepare(
    "SELECT sr.rating, sr.feedback, sr.created_at, u.first_name, u.last_name
     FROM service_reviews sr
     JOIN users u ON u.id = sr.seeker_user_id
     WHERE sr.provider_id = :pid
     ORDER BY sr.created_at DESC
     LIMIT 10"
);
$rev->execute([':pid' => $listing['provider_id']]);
$reviews = $rev->fetchAll(PDO::FETCH_ASSOC);

ok(['data' => $listing, 'reviews' => $reviews]);
