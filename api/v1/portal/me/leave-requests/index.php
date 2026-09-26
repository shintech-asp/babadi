<?php
// GET api/v1/portal/me/leave-requests
// The authenticated employee's own leave requests + current-year balances.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'provider-portal/includes/leave_balance_helper.php';

allow('GET');

$actor = require_portal_actor();
if (!$actor['employee_id']) {
    fail('No linked employee record for this account.', 403);
}

$pdo = db();

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = :e AND provider_id = :p");
$countStmt->execute([':e' => $actor['employee_id'], ':p' => $actor['provider_id']]);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM leave_requests WHERE employee_id = :e AND provider_id = :p
     ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':e', $actor['employee_id'], PDO::PARAM_INT);
$stmt->bindValue(':p', $actor['provider_id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$balances = [];
foreach (['annual','sick','personal','maternity','paternity'] as $lt) {
    $balances[$lt] = getLeaveBalance($pdo, $actor['provider_id'], $actor['employee_id'], $lt, date('Y'));
}

ok([
    'data' => $rows,
    'balances' => $balances,
    'meta' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
    ],
]);
