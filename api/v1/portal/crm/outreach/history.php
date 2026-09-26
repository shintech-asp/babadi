<?php
// GET api/v1/portal/crm/outreach/history
// Recent outreach send history — mirrors provider-portal/crm-outreach.php's
// history table.
// Access: owner, crm | Tier: Pro required.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'crm');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$pdo = db();
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM crm_outreach_log WHERE provider_id = :pid");
$countStmt->execute([':pid' => $pid]);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT l.*, u.first_name, u.last_name, u.email
     FROM crm_outreach_log l
     LEFT JOIN users u ON u.id = l.seeker_user_id
     WHERE l.provider_id = :pid
     ORDER BY l.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':pid', $pid, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok([
    'data' => $rows,
    'meta' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
    ],
]);
