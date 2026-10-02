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
        p.portfolio_images,
        p.portfolio_videos,
        p.status,
        p.service_radius,
        p.description,
        u.phone,
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
    LEFT JOIN services sl ON sl.provider_id = p.id AND sl.status = 'active'
    LEFT JOIN availed_services av ON av.provider_id = p.id
    WHERE p.id = :id AND p.status = 'active'
    GROUP BY p.id
");
$stmt->execute([':id' => $id]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    fail('Provider not found', 404);
}

$provider['portfolio_images'] = json_decode($provider['portfolio_images'] ?? '[]', true) ?: [];
$provider['portfolio_videos'] = json_decode($provider['portfolio_videos'] ?? '[]', true) ?: [];
$provider['avg_rating'] = round((float)$provider['avg_rating'], 2);
$provider['review_count'] = (int)$provider['review_count'];
$provider['service_count'] = (int)$provider['service_count'];
$provider['completed_count'] = (int)$provider['completed_count'];

// service_listings was merged into services (see CLAUDE.md's "Recent Work
// Log") — service_name AS title keeps this response shape identical to what
// the Flutter app already parses (listing['title']).
$lstmt = $pdo->prepare("
    SELECT id, service_name AS title, price, pricing_type, images, is_emergency_available, is_eco_friendly
    FROM services
    WHERE provider_id = :pid AND status = 'active'
    ORDER BY created_at DESC
");
$lstmt->execute([':pid' => $id]);
$listings = $lstmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($listings as &$listing) {
    $listing['id'] = (int)$listing['id'];
    $listing['images'] = json_decode($listing['images'], true) ?? [];
    $listing['price'] = (float)$listing['price'];
    $listing['is_emergency_available'] = (bool)$listing['is_emergency_available'];
    $listing['is_eco_friendly'] = (bool)$listing['is_eco_friendly'];
}
unset($listing);

$rstmt = $pdo->prepare("
    SELECT
        sr.rating,
        sr.feedback,
        sr.feedback_image,
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
