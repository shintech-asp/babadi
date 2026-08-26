<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$pg     = paginate();
$search = inp('search');
$city   = inp('city');

$where  = ["p.status = 'active'"];
$params = [];

if ($search !== null && $search !== '') {
    $where[]          = "p.company_name LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

if ($city !== null && $city !== '') {
    $where[]        = "p.city = :city";
    $params[':city'] = $city;
}

$whereSQL = implode(' AND ', $where);

$sql = "
    SELECT p.*,
           ROUND(AVG(r.rating), 1) AS avg_rating,
           COUNT(DISTINCT r.id) AS review_count,
           COUNT(DISTINCT sl.id) AS service_count,
           SUM(CASE WHEN av.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
    FROM providers p
    LEFT JOIN service_reviews r ON r.provider_id = p.id
    LEFT JOIN service_listings sl ON sl.provider_id = p.id AND sl.status = 'active'
    LEFT JOIN availed_services av ON av.provider_id = p.id
    WHERE {$whereSQL}
    GROUP BY p.id
    ORDER BY avg_rating DESC, completed_count DESC
    LIMIT :limit OFFSET :offset
";

$countSQL = "
    SELECT COUNT(DISTINCT p.id) AS total
    FROM providers p
    WHERE {$whereSQL}
";

$pdo = db();

$countStmt = $pdo->prepare($countSQL);
foreach ($params as $key => $val) {
    $countStmt->bindValue($key, $val);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit',  $pg['limit'],  PDO::PARAM_INT);
$stmt->bindValue(':offset', $pg['offset'], PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok([
    'data' => $rows,
    'meta' => [
        'page'        => $pg['page'],
        'limit'       => $pg['limit'],
        'total'       => $total,
        'total_pages' => $pg['limit'] > 0 ? (int) ceil($total / $pg['limit']) : 1,
    ],
]);
