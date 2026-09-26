<?php
// GET api/v1/portal/hr/leave-balances
// Per-employee leave balances for the current (or given) year — mirrors the
// balance map on provider-portal/leave-requests.php and settings.php's
// "Per-Employee Leave Override" card.
// Access: owner, hr | Tier: Pro required.
//
// Query params: year (optional, default current), employee_id (optional — a single employee)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'provider-portal/includes/leave_balance_helper.php';

allow('GET');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$year = (int) (inp('year') ?: date('Y'));
$employee_id = inp('employee_id') !== null && inp('employee_id') !== '' ? (int) inp('employee_id') : null;

$pdo = db();

$sql = "SELECT id, first_name, last_name, employee_id AS employee_code, department, position FROM employees WHERE provider_id = :p AND status = 'active'";
$params = [':p' => $pid];
if ($employee_id !== null) {
    $sql .= " AND id = :eid";
    $params[':eid'] = $employee_id;
}
$sql .= " ORDER BY first_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$leaveTypes = ['annual', 'sick', 'personal', 'maternity', 'paternity'];
$out = [];
foreach ($employees as $e) {
    $balances = [];
    foreach ($leaveTypes as $lt) {
        $balances[$lt] = getLeaveBalance($pdo, $pid, (int)$e['id'], $lt, $year);
    }
    $out[] = [
        'employee_id'   => (int)$e['id'],
        'employee_code' => $e['employee_code'],
        'first_name'    => $e['first_name'],
        'last_name'     => $e['last_name'],
        'department'    => $e['department'],
        'position'      => $e['position'],
        'balances'      => $balances,
    ];
}

ok(['data' => $out, 'year' => $year]);
