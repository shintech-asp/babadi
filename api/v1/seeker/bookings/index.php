<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$user = require_seeker();

$status = inp('status');
['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$where = '(av.seeker_user_id = :uid OR av.user_id = :uid2)';
$params = [':uid' => $user['id'], ':uid2' => $user['id']];

if ($status === 'active') {
    // 'active' is a UI group label — map it to all in-progress DB statuses.
    $where .= " AND av.status IN ('pending','accepted','preparing','starting','on_going',"
             . "'waiting_for_remaining_payment','waiting_for_seeker_confirmation',"
             . "'waiting_for_provider_confirmation')";
} elseif ($status !== null && $status !== '') {
    $where .= ' AND av.status = :status';
    $params[':status'] = $status;
}

$pdo = db();

$countSql = "SELECT COUNT(*) FROM availed_services av WHERE {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT av.*, p.company_name, p.logo_url,
               u_p.first_name AS provider_first, u_p.last_name AS provider_last
        FROM availed_services av
        JOIN providers p ON av.provider_id = p.id
        JOIN users u_p ON u_p.id = p.user_id
        WHERE {$where}
        ORDER BY av.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_pages = $limit > 0 ? (int) ceil($total / $limit) : 1;

ok([
    'data' => $rows,
    'meta' => [
        'page'        => $page,
        'limit'       => $limit,
        'total'       => $total,
        'total_pages' => $total_pages,
    ],
]);
