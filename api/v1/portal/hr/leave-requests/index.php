<?php
// GET api/v1/portal/hr/leave-requests
// List leave requests for HR/owner to review — mirrors
// provider-portal/leave-requests.php. Access: owner, hr | Tier: free-viewable
// (web's page itself has no tier gate at all — it renders the list under a
// "Free Tier — View Only" banner and only disables the approve/reject/grant
// actions; those three endpoints keep their own portal_require_pro() call).
//
// Query params: status (pending|approved|rejected, optional), page, limit
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$where = "lr.provider_id = :pid";
$params = [':pid' => $pid];

$status = inp('status');
if ($status !== null && $status !== '') {
    $where .= " AND lr.status = :st";
    $params[':st'] = $status;
}

$pdo = db();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests lr WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS employee_name, e.position, e.department
     FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
     WHERE $where
     ORDER BY lr.created_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// PDO emulated prepares return every column as a string (lr.* is never cast
// anywhere below), so the Flutter client's `(row['id'] as num).toInt()` was
// throwing a TypeError on "1" before the HTTP call for approve/reject even
// happened — every tap on Approve/Reject silently failed. Casting the
// numeric ids here matches the convention already used by every other
// portal list endpoint (payroll/index.php, staff/index.php, etc).
foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['employee_id'] = (int)$row['employee_id'];
}
unset($row);

$counts = [];
foreach (['pending', 'approved', 'rejected'] as $s) {
    $c = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE provider_id = :p AND status = :s");
    $c->execute([':p' => $pid, ':s' => $s]);
    $counts[$s] = (int)$c->fetchColumn();
}

ok([
    'data' => $rows,
    'counts' => $counts,
    'meta' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
    ],
]);
