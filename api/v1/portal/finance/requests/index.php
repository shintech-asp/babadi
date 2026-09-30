<?php
// GET /api/v1/portal/finance/requests/
// List finance (budget) requests for the authenticated staff's provider.
// Access: finance role (owner, finance) + Pro tier
//
// TABLE: budget_requests
//   Provider-scoped columns: id, provider_id, department, purpose, amount,
//   approved_amount, status, request_date, needed_date, requested_by
//   (FK → provider_staff.id), approved_by, remarks, created_at, updated_at
//
// Query params: status, page, limit
// status: pending | approved | rejected | partially_approved

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'finance');
portal_require_pro((int)$staff['provider_id']);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$provider_id = (int)$staff['provider_id'];
$status      = inp('status');   // optional filter

$where  = ['br.provider_id = :provider_id'];
$params = [':provider_id' => $provider_id];

$allowed_statuses = ['pending', 'approved', 'rejected', 'partially_approved'];
if ($status !== null && $status !== '') {
    if (!in_array($status, $allowed_statuses, true)) {
        fail('Invalid status. Allowed: ' . implode(', ', $allowed_statuses), 422);
    }
    $where[]          = 'br.status = :status';
    $params[':status'] = $status;
}

$whereStr = implode(' AND ', $where);

// Total count
$countStmt = db()->prepare("SELECT COUNT(*) FROM budget_requests br WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Records — requester is always a provider_staff row for portal requests
$sql = "
    SELECT
        br.id,
        br.department,
        br.purpose,
        br.amount,
        br.approved_amount,
        br.status,
        br.request_date,
        br.needed_date,
        br.requested_by,
        ps.full_name                               AS requester_name,
        ps.role                                    AS requester_role,
        br.approved_by,
        br.remarks,
        br.created_at,
        br.updated_at
    FROM budget_requests br
    LEFT JOIN provider_staff ps ON ps.id = br.requested_by
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
        'department'      => $r['department'] ?? null,
        'purpose'         => $r['purpose'] ?? null,
        'amount'          => (float)$r['amount'],
        'approved_amount' => $r['approved_amount'] !== null ? (float)$r['approved_amount'] : null,
        'status'          => $r['status'],
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
        'total'  => $total,
        'page'   => $page,
        'pages'  => (int)ceil($total / $limit),
        'limit'  => $limit,
    ],
]);
