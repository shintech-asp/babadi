<?php
// GET api/v1/portal/me/bookings
// Field technician self-service: bookings assigned to them — mirrors
// provider-portal/my-services.php, including its two assignment paths:
// confirmed (availed_services.assigned_employee_id, set by
// provider/service-requests.php's "Prepare Booking" flow) and tentative
// (the booking's service has this technician as its default handler via
// services.assigned_staff_id, surfaced before Prepare Booking runs). See
// that file's own top-of-file comment for the full rationale. Read-only.
//
// Query params: status (optional), page, limit
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');

$actor = require_portal_actor();
if (!$actor['employee_id']) {
    fail('No linked employee record for this account.', 403);
}
if (($actor['staff_type'] ?? 'office') !== 'field') {
    fail('This account is not a field technician.', 403);
}

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// 'pending' excluded even from the tentative match — nothing should be
// visible to a technician until the provider has at least accepted it.
$where = "av.provider_id = :pid AND av.status != 'pending' AND (av.assigned_employee_id = :eid OR s.assigned_staff_id = :eid)";
$params = [':pid' => $actor['provider_id'], ':eid' => $actor['employee_id']];

$status = inp('status');
if ($status !== null && $status !== '') {
    $where .= " AND av.status = :st";
    $params[':st'] = $status;
}

$pdo = db();

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM availed_services av LEFT JOIN services s ON s.id = av.service_id WHERE $where"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT av.id, av.service_name, av.full_name, av.contact_number, av.preferred_date, av.preferred_time,
            av.inspection_date, av.working_date, av.address, av.status, av.operations_notes,
            av.assigned_employee_id
     FROM availed_services av
     LEFT JOIN services s ON s.id = av.service_id
     WHERE $where
     ORDER BY av.preferred_date DESC, av.preferred_time DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
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
