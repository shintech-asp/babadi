<?php
// GET /api/v1/portal/hr/attendance/
// List attendance records for this provider's employees.
// Access: owner, hr   |   Tier: Pro required
//
// Query params:
//   employee_id  (int, optional) – filter by employee
//   date_from    (YYYY-MM-DD, optional)
//   date_to      (YYYY-MM-DD, optional)
//   page         (default 1)
//   limit        (default 20, max 100)

require_once dirname(__DIR__, 3) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$employee_id = inp('employee_id') !== null && inp('employee_id') !== '' ? (int)inp('employee_id') : null;
$date_from   = inp('date_from');
$date_to     = inp('date_to');

$where  = ['a.provider_id = :provider_id'];
$params = [':provider_id' => $pid];

if ($employee_id !== null) {
    $where[]               = 'a.employee_id = :employee_id';
    $params[':employee_id'] = $employee_id;
}

if ($date_from !== null && $date_from !== '') {
    $where[]              = 'a.date >= :date_from';
    $params[':date_from'] = $date_from;
}

if ($date_to !== null && $date_to !== '') {
    $where[]            = 'a.date <= :date_to';
    $params[':date_to'] = $date_to;
}

$whereStr = implode(' AND ', $where);

// TABLE: attendance — confirmed from provider-portal/attendance.php
$countStmt = db()->prepare("SELECT COUNT(*) FROM attendance a WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT
        a.id,
        a.employee_id,
        e.employee_id AS employee_code,
        e.first_name,
        e.last_name,
        e.department,
        e.position,
        a.date,
        a.time_in,
        a.time_out,
        a.total_hours,
        a.status,
        a.notes,
        a.time_in_mode,
        a.created_at
    FROM attendance a
    LEFT JOIN employees e ON e.id = a.employee_id
    WHERE $whereStr
    ORDER BY a.date DESC, a.employee_id ASC
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
        'id'            => (int)$r['id'],
        'employee_id'   => (int)$r['employee_id'],
        'employee_code' => $r['employee_code'] ?? null,
        'first_name'    => $r['first_name'] ?? null,
        'last_name'     => $r['last_name'] ?? null,
        'department'    => $r['department'] ?? null,
        'position'      => $r['position'] ?? null,
        'date'          => $r['date'],
        'time_in'       => $r['time_in'] ?? null,
        'time_out'      => $r['time_out'] ?? null,
        'total_hours'   => $r['total_hours'] !== null ? (float)$r['total_hours'] : null,
        'status'        => $r['status'],   // present | late | absent | half_day
        'notes'         => $r['notes'] ?? null,
        'time_in_mode'  => $r['time_in_mode'] ?? null,  // biometric | manual
        'created_at'    => $r['created_at'] ?? null,
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
