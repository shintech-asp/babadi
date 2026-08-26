<?php
// GET /api/v1/admin/finance/requests/
// List finance (budget) requests from providers and admin staff.
// Access: super_admin, admin, finance
//
// Budget requests originate from two places:
//   1. provider-portal/budget-requests.php — provider-scoped rows (provider_id IS NOT NULL).
//      requested_by references provider_staff.id
//   2. admin/finance/requests.php — platform-level rows (provider_id IS NULL).
//      requested_by references admin_users.id
//
// TABLE: budget_requests — confirmed from both portal pages above

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin', 'finance');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// Optional filters
$provider_id = inp('provider_id') !== null ? (int)inp('provider_id') : null;
$status      = inp('status');   // pending | approved | rejected | partially_approved

$where  = ['1=1'];
$params = [];

if ($provider_id !== null) {
    $where[]                = 'br.provider_id = :provider_id';
    $params[':provider_id'] = $provider_id;
}

if ($status !== null && $status !== '') {
    $where[]         = 'br.status = :status';
    $params[':status'] = $status;
}

$whereStr = implode(' AND ', $where);

// Count
$countSql  = "SELECT COUNT(*) FROM budget_requests br WHERE $whereStr";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Records — requester may be a provider_staff row or an admin_users row.
// We LEFT JOIN both and COALESCE the name fields so either source resolves.
$sql = "
    SELECT
        br.id,
        br.provider_id,
        p.company_name                                              AS provider_name,
        br.department,
        br.purpose,
        br.amount,
        br.approved_amount,
        br.status,
        br.request_date,
        br.needed_date,
        br.requested_by,
        COALESCE(ps.full_name, au.full_name)                        AS requester_name,
        COALESCE(ps.role, au.role)                                  AS requester_role,
        br.approved_by,
        br.remarks,
        br.created_at,
        br.updated_at
    FROM budget_requests br
    LEFT JOIN providers    p  ON p.id  = br.provider_id
    LEFT JOIN provider_staff ps ON ps.id = br.requested_by AND br.provider_id IS NOT NULL
    LEFT JOIN admin_users  au ON au.id = br.requested_by  AND br.provider_id IS NULL
    WHERE $whereStr
    ORDER BY br.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = db()->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$records = array_map(function (array $r): array {
    return [
        'id'              => (int)$r['id'],
        'provider_id'     => $r['provider_id'] !== null ? (int)$r['provider_id'] : null,
        'provider_name'   => $r['provider_name'],
        'department'      => $r['department'] ?? null,
        'purpose'         => $r['purpose'] ?? null,
        'amount'          => (float)$r['amount'],
        'approved_amount' => $r['approved_amount'] !== null ? (float)$r['approved_amount'] : null,
        'status'          => $r['status'],                 // pending | approved | rejected | partially_approved
        'request_date'    => $r['request_date'] ?? null,
        'needed_date'     => $r['needed_date'] ?? null,
        'requested_by'    => $r['requested_by'] !== null ? (int)$r['requested_by'] : null,
        'requester_name'  => $r['requester_name'] ?? null,
        'requester_role'  => $r['requester_role'] ?? null,
        'approved_by'     => $r['approved_by'] !== null ? (int)$r['approved_by'] : null,
        'remarks'         => $r['remarks'] ?? null,
        'created_at'      => $r['created_at'],
        'updated_at'      => $r['updated_at'] ?? null,
    ];
}, $rows);

ok([
    'data' => $records,
    'meta' => [
        'total' => $total,
        'page'  => $page,
        'pages' => (int)ceil($total / $limit),
    ],
]);
