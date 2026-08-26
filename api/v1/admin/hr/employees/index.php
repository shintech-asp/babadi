<?php
// GET /api/v1/admin/hr/employees/
// List all employees across all providers.
// Access: super_admin, admin, hr

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin', 'hr');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// Optional filters
$provider_id = inp('provider_id') !== null ? (int)inp('provider_id') : null;
$status      = inp('status');   // active | inactive | terminated | on_leave

$where  = ['1=1'];
$params = [];

if ($provider_id !== null) {
    $where[]             = 'e.provider_id = :provider_id';
    $params[':provider_id'] = $provider_id;
}

if ($status !== null && $status !== '') {
    $where[]         = 'e.status = :status';
    $params[':status'] = $status;
}

$whereStr = implode(' AND ', $where);

// TABLE: employees — confirmed from provider-portal/payroll.php and attendance.php
$countSql = "SELECT COUNT(*) FROM employees e WHERE $whereStr";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT
        e.id,
        e.provider_id,
        p.company_name       AS provider_name,
        e.employee_id,
        e.first_name,
        e.last_name,
        e.email,
        e.phone,
        e.department,
        e.position,
        e.employment_type,
        e.basic_salary,
        e.pay_frequency,
        e.status,
        e.hire_date,
        e.created_at
    FROM employees e
    LEFT JOIN providers p ON p.id = e.provider_id
    WHERE $whereStr
    ORDER BY e.provider_id ASC, e.last_name ASC, e.first_name ASC
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

$employees = array_map(function (array $r): array {
    return [
        'id'            => (int)$r['id'],
        'provider_id'   => (int)$r['provider_id'],
        'provider_name' => $r['provider_name'],
        'employee_id'   => $r['employee_id'],
        'first_name'    => $r['first_name'],
        'last_name'     => $r['last_name'],
        'email'         => $r['email'],
        'phone'         => $r['phone'] ?? null,
        'department'    => $r['department'] ?? null,
        'position'      => $r['position'] ?? null,
        'employment_type' => $r['employment_type'] ?? null,
        'basic_salary'  => $r['basic_salary'] !== null ? (float)$r['basic_salary'] : null,
        'pay_frequency' => $r['pay_frequency'] ?? null,
        'status'        => $r['status'],
        'hire_date'     => $r['hire_date'] ?? null,
        'created_at'    => $r['created_at'],
    ];
}, $rows);

ok([
    'data' => $employees,
    'meta' => [
        'total' => $total,
        'page'  => $page,
        'pages' => (int)ceil($total / $limit),
    ],
]);
