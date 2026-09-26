<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$search       = inp('search');
$category_id  = inp('category_id');
$location     = inp('location');
$is_emergency = inp('is_emergency');
$min_price    = inp('min_price');
$max_price    = inp('max_price');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$where  = ["sl.status = 'active'"];
$params = [];

if ($search !== null && $search !== '') {
    $where[]            = "(sl.service_name LIKE :search OR sl.description LIKE :search)";
    $params[':search']  = '%' . $search . '%';
}

if ($category_id !== null && $category_id !== '') {
    $where[]               = "sl.category_id = :category_id";
    $params[':category_id'] = (int) $category_id;
}

if ($location !== null && $location !== '') {
    $where[]             = "(p.city LIKE :location OR p.address LIKE :location)";
    $params[':location'] = '%' . $location . '%';
}

if ($is_emergency !== null && $is_emergency !== '') {
    $where[]                  = "sl.is_emergency_available = :is_emergency";
    $params[':is_emergency']  = (int)(bool) $is_emergency;
}

if ($min_price !== null && $min_price !== '') {
    $where[]             = "sl.price >= :min_price";
    $params[':min_price'] = (float) $min_price;
}

if ($max_price !== null && $max_price !== '') {
    $where[]             = "sl.price <= :max_price";
    $params[':max_price'] = (float) $max_price;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$pdo = db();

// service_listings was merged into services (see CLAUDE.md's "Recent Work
// Log") — sl.service_name AS title keeps this endpoint's JSON response shape
// identical to what the Flutter app already parses (listing['title']).
$countSQL = "
    SELECT COUNT(DISTINCT sl.id) AS total
    FROM services sl
    JOIN providers p ON sl.provider_id = p.id
    LEFT JOIN service_categories sc ON sl.category_id = sc.id
    LEFT JOIN service_reviews r ON r.provider_id = p.id
    $whereSQL
";

$countStmt = $pdo->prepare($countSQL);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();

// service_categories is LEFT JOINed (not JOINed) deliberately: an INNER
// join here would silently hide any service whose category_id is NULL or
// points at a deleted category — exactly the "collected but silently
// drops the row" bug class this app has been bitten by before (see
// CLAUDE.md's "Recent Work Log"). No rows in this DB currently trigger it,
// but a provider's service should never be able to vanish from browse just
// because its category reference went stale.
$dataSQL = "
    SELECT sl.*, sl.service_name AS title, p.company_name, p.logo_url, sc.name AS category_name,
           ROUND(AVG(r.rating), 1) AS avg_rating, COUNT(r.id) AS review_count
    FROM services sl
    JOIN providers p ON sl.provider_id = p.id
    LEFT JOIN service_categories sc ON sl.category_id = sc.id
    LEFT JOIN service_reviews r ON r.provider_id = p.id
    $whereSQL
    GROUP BY sl.id
    ORDER BY sl.views_count DESC, sl.created_at DESC
    LIMIT :limit OFFSET :offset
";

$dataStmt = $pdo->prepare($dataSQL);
foreach ($params as $key => $value) {
    $dataStmt->bindValue($key, $value);
}
$dataStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();

$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['images'] = isset($row['images']) ? json_decode($row['images'], true) ?? [] : [];
}
unset($row);

ok([
    'data' => $rows,
    'meta' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
    ],
]);
