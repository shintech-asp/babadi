<?php
// GET api/v1/portal/hr/employees
// List employees for HR/owner to review/manage — mirrors
// provider-portal/employees.php's list query exactly (including the
// is_manager/staff_role LEFT JOIN against provider_staff), plus the
// position/employment-type/department catalog the Add/Edit form needs.
//
// Query params: search, department, status (all optional)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once dirname(__DIR__, 5) . '/includes/employee_catalog.php';

allow('GET');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];

$pdo = db();

$where  = 'e.provider_id = :pid';
$params = [':pid' => $pid];

$search = trim((string) inp('search', ''));
if ($search !== '') {
    $where .= ' AND (e.first_name LIKE :s OR e.last_name LIKE :s OR e.employee_id LIKE :s OR e.email LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}

$dept = trim((string) inp('department', ''));
if ($dept !== '') {
    $where .= ' AND e.department = :d';
    $params[':d'] = $dept;
}

$status = trim((string) inp('status', ''));
if ($status !== '') {
    if (!in_array($status, ['active', 'inactive', 'on_leave'], true)) {
        fail('status must be active, inactive, or on_leave');
    }
    $where .= ' AND e.status = :st';
    $params[':st'] = $status;
}

$stmt = $pdo->prepare(
    "SELECT e.id, e.employee_id, e.first_name, e.middle_name, e.last_name, e.email, e.phone,
            e.position, e.department, e.staff_type, e.employment_type, e.hire_date,
            e.basic_salary, e.pay_frequency, e.sss_no, e.philhealth_no, e.pagibig_no, e.tin_no,
            e.status, e.created_at,
            IF(ps.id IS NOT NULL AND ps.status = 'active', 1, 0) AS is_manager,
            ps.role AS staff_role, ps.department AS staff_dept
     FROM employees e
     LEFT JOIN provider_staff ps ON ps.email = e.email AND ps.provider_id = e.provider_id AND ps.role != 'owner'
     WHERE $where
     ORDER BY e.first_name, e.last_name"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['basic_salary'] = $row['basic_salary'] !== null ? (float)$row['basic_salary'] : null;
    $row['is_manager'] = (bool)$row['is_manager'];
}
unset($row);

ok([
    'data' => $rows,
    'catalog' => [
        'positions'        => pestifyEmployeePositionOptions(),
        'employment_types' => pestifyEmploymentTypeOptions(),
        'departments'      => pestifyEmployeeDepartmentCatalog(),
    ],
]);
