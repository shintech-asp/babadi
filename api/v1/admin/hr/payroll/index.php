<?php
// GET /api/v1/admin/hr/payroll/
// List payroll records across all providers with optional filters.
// Access: super_admin, admin, hr

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin', 'hr');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// Optional filters
$provider_id = inp('provider_id') !== null ? (int)inp('provider_id') : null;
$month       = inp('month');   // 1-12
$year        = inp('year');    // e.g. 2025

$where  = ['1=1'];
$params = [];

if ($provider_id !== null) {
    $where[]                = 'pr.provider_id = :provider_id';
    $params[':provider_id'] = $provider_id;
}

if ($month !== null && $month !== '') {
    $where[]       = 'MONTH(pr.pay_period_start) = :month';
    $params[':month'] = (int)$month;
}

if ($year !== null && $year !== '') {
    $where[]      = 'YEAR(pr.pay_period_start) = :year';
    $params[':year'] = (int)$year;
}

$whereStr = implode(' AND ', $where);

// TABLE: payroll — confirmed from provider-portal/payroll.php
$countSql  = "SELECT COUNT(*) FROM payroll pr WHERE $whereStr";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT
        pr.id,
        pr.provider_id,
        p.company_name           AS provider_name,
        pr.employee_id,
        e.employee_id,
        e.first_name,
        e.last_name,
        e.department,
        e.position,
        pr.pay_period_name,
        pr.pay_period_start,
        pr.pay_period_end,
        pr.basic_salary,
        pr.gross_salary,
        pr.sss_employee,
        pr.philhealth_employee,
        pr.pagibig_employee,
        pr.withholding_tax,
        pr.sss_employer,
        pr.philhealth_employer,
        pr.pagibig_employer,
        pr.deductions,
        pr.net_salary,
        pr.status,
        pr.payment_date,
        pr.created_at
    FROM payroll pr
    LEFT JOIN employees e ON e.id  = pr.employee_id
    LEFT JOIN providers  p ON p.id = pr.provider_id
    WHERE $whereStr
    ORDER BY pr.pay_period_start DESC, pr.provider_id ASC
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
        'id'                   => (int)$r['id'],
        'provider_id'          => (int)$r['provider_id'],
        'provider_name'        => $r['provider_name'],
        'employee_id'          => (int)$r['employee_id'],
        'employee_code'        => $r['employee_code'] ?? null,
        'first_name'           => $r['first_name'] ?? null,
        'last_name'            => $r['last_name'] ?? null,
        'department'           => $r['department'] ?? null,
        'position'             => $r['position'] ?? null,
        'pay_period_name'      => $r['pay_period_name'] ?? null,
        'pay_period_start'     => $r['pay_period_start'] ?? null,
        'pay_period_end'       => $r['pay_period_end'] ?? null,
        'basic_salary'         => $r['basic_salary'] !== null ? (float)$r['basic_salary'] : null,
        'gross_salary'         => $r['gross_salary'] !== null ? (float)$r['gross_salary'] : null,
        'sss_employee'         => $r['sss_employee'] !== null ? (float)$r['sss_employee'] : null,
        'philhealth_employee'  => $r['philhealth_employee'] !== null ? (float)$r['philhealth_employee'] : null,
        'pagibig_employee'     => $r['pagibig_employee'] !== null ? (float)$r['pagibig_employee'] : null,
        'withholding_tax'      => $r['withholding_tax'] !== null ? (float)$r['withholding_tax'] : null,
        'sss_employer'         => $r['sss_employer'] !== null ? (float)$r['sss_employer'] : null,
        'philhealth_employer'  => $r['philhealth_employer'] !== null ? (float)$r['philhealth_employer'] : null,
        'pagibig_employer'     => $r['pagibig_employer'] !== null ? (float)$r['pagibig_employer'] : null,
        'deductions'           => $r['deductions'] !== null ? (float)$r['deductions'] : null,
        'net_salary'           => $r['net_salary'] !== null ? (float)$r['net_salary'] : null,
        'status'               => $r['status'],   // pending | paid
        'payment_date'         => $r['payment_date'] ?? null,
        'created_at'           => $r['created_at'],
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
