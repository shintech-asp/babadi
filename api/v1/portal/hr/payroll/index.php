<?php
// GET /api/v1/portal/hr/payroll/
// List payroll records for this provider's employees.
// Access: owner, hr, finance   |   Tier: Pro required
//
// Web's payroll.php gates the whole page on ($can_hr || $can_finance) since
// Finance needs to see the list to know what to approve/mark-paid — this
// endpoint originally only allowed owner/hr, which meant a finance-only
// mobile account could call approve.php/mark-paid.php (both already
// owner/finance-gated) but never actually see a list to act on. Widened to
// match.
//
// Query params:
//   employee_id  (int, optional)
//   month        (1-12, optional) — filters by MONTH(pay_period_start)
//   year         (int, optional)  — filters by YEAR(pay_period_start)
//   page         (default 1)
//   limit        (default 20, max 100)

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'hr', 'finance');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$employee_id = inp('employee_id') !== null && inp('employee_id') !== '' ? (int)inp('employee_id') : null;
$month       = inp('month');
$year        = inp('year');

// TABLE: payroll — confirmed from provider-portal/payroll.php
$where  = ['pr.provider_id = :provider_id'];
$params = [':provider_id' => $pid];

if ($employee_id !== null) {
    $where[]               = 'pr.employee_id = :employee_id';
    $params[':employee_id'] = $employee_id;
}

if ($month !== null && $month !== '') {
    $where[]         = 'MONTH(pr.pay_period_start) = :month';
    $params[':month'] = (int)$month;
}

if ($year !== null && $year !== '') {
    $where[]        = 'YEAR(pr.pay_period_start) = :year';
    $params[':year'] = (int)$year;
}

$whereStr = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM payroll pr WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT
        pr.id,
        pr.employee_id,
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
    LEFT JOIN employees e ON e.id = pr.employee_id
    WHERE $whereStr
    ORDER BY pr.pay_period_start DESC, pr.employee_id ASC
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
        'id'                  => (int)$r['id'],
        'employee_id'         => (int)$r['employee_id'],
        'first_name'          => $r['first_name'] ?? null,
        'last_name'           => $r['last_name'] ?? null,
        'department'          => $r['department'] ?? null,
        'position'            => $r['position'] ?? null,
        'pay_period_name'     => $r['pay_period_name'] ?? null,
        'pay_period_start'    => $r['pay_period_start'] ?? null,
        'pay_period_end'      => $r['pay_period_end'] ?? null,
        'basic_salary'        => $r['basic_salary'] !== null ? (float)$r['basic_salary'] : null,
        'gross_salary'        => $r['gross_salary'] !== null ? (float)$r['gross_salary'] : null,
        'sss_employee'        => $r['sss_employee'] !== null ? (float)$r['sss_employee'] : null,
        'philhealth_employee' => $r['philhealth_employee'] !== null ? (float)$r['philhealth_employee'] : null,
        'pagibig_employee'    => $r['pagibig_employee'] !== null ? (float)$r['pagibig_employee'] : null,
        'withholding_tax'     => $r['withholding_tax'] !== null ? (float)$r['withholding_tax'] : null,
        'sss_employer'        => $r['sss_employer'] !== null ? (float)$r['sss_employer'] : null,
        'philhealth_employer' => $r['philhealth_employer'] !== null ? (float)$r['philhealth_employer'] : null,
        'pagibig_employer'    => $r['pagibig_employer'] !== null ? (float)$r['pagibig_employer'] : null,
        'deductions'          => $r['deductions'] !== null ? (float)$r['deductions'] : null,
        'net_salary'          => $r['net_salary'] !== null ? (float)$r['net_salary'] : null,
        'status'              => $r['status'],   // pending | paid
        'payment_date'        => $r['payment_date'] ?? null,
        'created_at'          => $r['created_at'],
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
