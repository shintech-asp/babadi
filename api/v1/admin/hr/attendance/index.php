<?php
// GET /api/v1/admin/hr/attendance/
// List attendance records across all providers with optional filters.
// Access: super_admin, admin, hr

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin', 'hr');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// Optional filters
$provider_id = inp('provider_id') !== null ? (int)inp('provider_id') : null;
$employee_id = inp('employee_id') !== null ? (int)inp('employee_id') : null;
$date_from   = inp('date_from');   // YYYY-MM-DD
$date_to     = inp('date_to');     // YYYY-MM-DD

$where  = ['1=1'];
$params = [];

if ($provider_id !== null) {
    $where[]                = 'a.provider_id = :provider_id';
    $params[':provider_id'] = $provider_id;
}

if ($employee_id !== null) {
    $where[]                = 'a.employee_id = :employee_id';
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
$countSql  = "SELECT COUNT(*) FROM attendance a WHERE $whereStr";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT
        a.id,
        a.provider_id,
        p.company_name        AS provider_name,
        a.employee_id,
        e.employee_id         AS employee_code,
        e.first_name,
        e.last_name,
        e.department,
        e.position,
        a.date,
        a.time_in,
        a.time_out,
        a.status,
        a.notes,
        a.time_in_mode,
        a.created_at
    FROM attendance a
    LEFT JOIN employees e  ON e.id  = a.employee_id
    LEFT JOIN providers  p ON p.id  = a.provider_id
    WHERE $whereStr
    ORDER BY a.date DESC, a.provider_id ASC, a.employee_id ASC
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
        'provider_id'   => (int)$r['provider_id'],
        'provider_name' => $r['provider_name'],
        'employee_id'   => (int)$r['employee_id'],
        'employee_code' => $r['employee_code'] ?? null,
        'first_name'    => $r['first_name'] ?? null,
        'last_name'     => $r['last_name'] ?? null,
        'department'    => $r['department'] ?? null,
        'position'      => $r['position'] ?? null,
        'date'          => $r['date'],
        'time_in'       => $r['time_in'] ?? null,
        'time_out'      => $r['time_out'] ?? null,
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
