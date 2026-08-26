<?php
require_once dirname(__DIR__, 3) . '/_bootstrap.php';

allow('GET');

$staff = require_portal_role('owner', 'crm');
portal_require_pro($staff['provider_id']);

$pid    = (int)$staff['provider_id'];
$status = inp('status');
$dateFrom = inp('date_from');
$dateTo   = inp('date_to');

$pg = paginate();

$whereClauses = ['av.provider_id = :pid'];
$params = [':pid' => $pid];

if ($status !== null && $status !== '') {
    $whereClauses[] = 'av.status = :status';
    $params[':status'] = $status;
}

if ($dateFrom !== null && $dateFrom !== '') {
    $whereClauses[] = 'av.preferred_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== null && $dateTo !== '') {
    $whereClauses[] = 'av.preferred_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$where = 'WHERE ' . implode(' AND ', $whereClauses);

$countStmt = db()->prepare("SELECT COUNT(*) FROM availed_services av $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT av.id,
               av.status,
               av.preferred_date,
               av.preferred_time,
               av.payment_method,
               sl.title AS service_name,
               u.first_name AS seeker_first,
               u.last_name  AS seeker_last
        FROM availed_services av
        JOIN users u ON u.id = COALESCE(av.seeker_user_id, av.user_id)
        LEFT JOIN service_listings sl ON sl.id = av.service_id
        $where
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

foreach ($rows as &$row) {
    $row['id']     = (int)$row['id'];
    $row['seeker_name'] = trim($row['seeker_first'] . ' ' . $row['seeker_last']);
    unset($row['seeker_first'], $row['seeker_last']);
}
unset($row);

ok([
    'data' => $rows,
    'meta' => [
        'page'        => $pg['page'],
        'limit'       => $pg['limit'],
        'total'       => $total,
        'total_pages' => (int)ceil($total / $pg['limit']),
    ],
]);
