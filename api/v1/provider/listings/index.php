<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$p = current_provider();

$status = inp('status');
if ($status !== null && !in_array($status, ['active', 'inactive'], true)) {
    fail('status must be active or inactive');
}

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$where = 'WHERE sl.provider_id = :pid';
$params = [':pid' => $p['id']];

if ($status !== null) {
    $where .= ' AND sl.status = :status';
    $params[':status'] = $status;
}

// service_listings was merged into services (see CLAUDE.md's "Recent Work
// Log" for the full centralization writeup) — service_name AS title keeps
// this endpoint's JSON response shape identical to what the Flutter app
// already parses (listing['title']), even though the underlying column
// storing that name is no longer literally called "title".
// service_categories is LEFT JOINed (not JOINed) deliberately — an INNER
// join would silently hide a provider's own service from their own listing
// management screen if its category_id were ever NULL or pointed at a
// deleted category, which is exactly the kind of "can't even find it to
// fix it" bug this app has been bitten by before (see CLAUDE.md's "Recent
// Work Log"). No rows in this DB currently trigger it, but there's no
// reason this join needs to be able to hide a row at all.
$countSql = "SELECT COUNT(DISTINCT sl.id)
             FROM services sl
             LEFT JOIN service_categories sc ON sl.category_id = sc.id
             LEFT JOIN service_reviews r ON r.provider_id = sl.provider_id
             $where";

$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT sl.*, sl.service_name AS title, sc.name AS category_name,
               ROUND(AVG(r.rating), 1) AS avg_rating,
               COUNT(r.id) AS review_count
        FROM services sl
        LEFT JOIN service_categories sc ON sl.category_id = sc.id
        LEFT JOIN service_reviews r ON r.provider_id = sl.provider_id
        $where
        GROUP BY sl.id
        ORDER BY sl.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['images'] = isset($row['images']) ? json_decode($row['images'], true) ?? [] : [];
}
unset($row);

ok([
    'data' => $rows,
    'meta' => [
        'page'  => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
    ],
]);
