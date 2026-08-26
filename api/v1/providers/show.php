<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$id = (int)inp('id');
if ($id <= 0) {
    fail('Provider ID required');
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.user_id,
        p.company_name,
        p.logo_url,
        p.status,
        p.service_radius,
        p.description,
        p.phone,
        p.address,
        p.city,
        p.state,
        u.email,
        u.profile_image,
        COALESCE(AVG(sr.rating), 0) AS avg_rating,
        COUNT(DISTINCT sr.id) AS review_count,
        COUNT(DISTINCT sl.id) AS service_count,
        COUNT(DISTINCT CASE WHEN av.status = 'completed' THEN av.id END) AS completed_count
    FROM providers p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN service_reviews sr ON sr.provider_id = p.id
    LEFT JOIN service_listings sl ON sl.provider_id = p.id AND sl.status = 'active'
    LEFT JOIN availed_services av ON av.provider_id = p.id
    WHERE p.id = :id AND p.status = 'active'
    GROUP BY p.id
");
$stmt->execute([':id' => $id]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    fail('Provider not found', 404);
}

$provider['avg_rating'] = round((float)$provider['avg_rating'], 2);
$provider['review_count'] = (int)$provider['review_count'];
$provider['service_count'] = (int)$provider['service_count'];
$provider['completed_count'] = (int)$provider['completed_count'];

$lstmt = $pdo->prepare("
    SELECT id, title, price, pricing_type, images, is_emergency_available
    FROM service_listings
    WHERE provider_id = :pid AND status = 'active'
    ORDER BY created_at DESC
");
$lstmt->execute([':pid' => $id]);
$listings = $lstmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($listings as &$listing) {
    $listing['images'] = json_decode($listing['images'], true) ?? [];
    $listing['price'] = (float)$listing['price'];
    $listing['is_emergency_available'] = (bool)$listing['is_emergency_available'];
}
unset($listing);

$rstmt = $pdo->prepare("
    SELECT
        sr.rating,
        sr.feedback,
        sr.created_at,
        u.first_name,
        u.last_name
    FROM service_reviews sr
    JOIN users u ON u.id = sr.seeker_user_id
    WHERE sr.provider_id = :pid
    ORDER BY sr.created_at DESC
    LIMIT 10
");
$rstmt->execute([':pid' => $id]);
$reviews = $rstmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($reviews as &$review) {
    $review['rating'] = (int)$review['rating'];
}
unset($review);

ok([
    'data'     => $provider,
    'listings' => $listings,
    'reviews'  => $reviews,
]);
