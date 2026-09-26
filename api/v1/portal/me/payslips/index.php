<?php
// GET api/v1/portal/me/payslips
// The authenticated employee's own payslip history — read-only, mirrors
// provider-portal/my-payslips.php.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$actor = require_portal_actor();
if (!$actor['employee_id']) {
    fail('No linked employee record for this account.', 403);
}

$pdo = db();

$empStmt = $pdo->prepare("SELECT id, first_name, last_name, employee_id AS employee_code, position, department, basic_salary FROM employees WHERE id = :id AND provider_id = :p LIMIT 1");
$empStmt->execute([':id' => $actor['employee_id'], ':p' => $actor['provider_id']]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) fail('Employee record not found.', 404);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE employee_id = :e AND provider_id = :p");
$countStmt->execute([':e' => $actor['employee_id'], ':p' => $actor['provider_id']]);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM payroll WHERE employee_id = :e AND provider_id = :p
     ORDER BY pay_period_start DESC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':e', $actor['employee_id'], PDO::PARAM_INT);
$stmt->bindValue(':p', $actor['provider_id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok([
    'employee' => $employee,
    'data' => $rows,
    'meta' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
    ],
]);
