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

$countSql = "SELECT COUNT(DISTINCT sl.id)
             FROM service_listings sl
             JOIN service_categories sc ON sl.category_id = sc.id
             LEFT JOIN service_reviews r ON r.provider_id = sl.provider_id
             $where";

$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT sl.*, sc.name AS category_name,
               ROUND(AVG(r.rating), 1) AS avg_rating,
               COUNT(r.id) AS review_count
        FROM service_listings sl
        JOIN service_categories sc ON sl.category_id = sc.id
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
