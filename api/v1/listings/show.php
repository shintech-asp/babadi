<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$id = (int)inp('id');
if ($id <= 0) {
    fail('Listing ID required');
}

$db = db();

// service_listings was merged into services (see CLAUDE.md's "Recent Work
// Log") — sl.service_name AS title keeps this endpoint's JSON response shape
// identical to what the Flutter app already parses (listing['title']).
// service_categories is LEFT JOINed (not JOINed) deliberately: an INNER
// join here would 404 a service's own detail page — worse than just being
// absent from browse, since it'd still show up in the list only to vanish
// on tap — the moment category_id is NULL or points at a deleted category.
$stmt = $db->prepare(
    "SELECT sl.*, sl.service_name AS title, p.company_name, p.logo_url, p.service_radius, p.description AS provider_description,
            p.city AS provider_city, p.address AS provider_address,
            u.first_name AS provider_first, u.last_name AS provider_last,
            sc.name AS category_name,
            ROUND(AVG(r.rating),1) AS avg_rating, COUNT(r.id) AS review_count
     FROM services sl
     JOIN providers p ON sl.provider_id = p.id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN service_categories sc ON sl.category_id = sc.id
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
$listing['videos'] = json_decode($listing['videos'] ?? '[]', true) ?: [];

$upd = $db->prepare("UPDATE services SET views_count = views_count + 1 WHERE id = :id");
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
