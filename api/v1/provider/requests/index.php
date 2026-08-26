<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$p = current_provider();

$status = inp('status');

$pg = paginate();

$whereClauses = ['av.provider_id = :pid'];
$params = [':pid' => $p['id']];

if ($status !== null && $status !== '') {
    $whereClauses[] = 'av.status = :status';
    $params[':status'] = $status;
}

$where = implode(' AND ', $whereClauses);

$countSql = "SELECT COUNT(*) FROM availed_services av WHERE {$where}";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT av.*,
               u.first_name AS seeker_first,
               u.last_name  AS seeker_last,
               u.phone      AS seeker_phone,
               u.email      AS seeker_email,
               sl.title     AS listing_title,
               sl.price
        FROM availed_services av
        JOIN users u ON u.id = COALESCE(av.seeker_user_id, av.user_id)
        LEFT JOIN service_listings sl ON sl.id = av.listing_id
        WHERE {$where}
        ORDER BY av.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = db()->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit',  $pg['limit'],  PDO::PARAM_INT);
$stmt->bindValue(':offset', $pg['offset'], PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok([
    'data' => $rows,
    'meta' => [
        'page'       => $pg['page'],
        'limit'      => $pg['limit'],
        'total'      => $total,
        'total_pages'=> (int) ceil($total / $pg['limit']),
    ],
]);
