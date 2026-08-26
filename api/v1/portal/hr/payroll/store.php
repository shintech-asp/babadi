<?php
// POST /api/v1/portal/hr/payroll/store
// Insert a payroll record for one of this provider's employees.
// Access: owner, hr   |   Tier: Pro required
//
// Body (JSON or form-data):
//   employee_id   (int, required)
//   period_start  (YYYY-MM-DD, required)
//   period_end    (YYYY-MM-DD, required)
//   basic_pay     (float, required)  — gross pay for this period
//   deductions    (float, required)  — total deductions for this period
//   net_pay       (float, required)
//   notes         (string, optional)

require_once dirname(__DIR__, 3) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$employee_id  = (int)req_inp('employee_id', 'employee_id');
$period_start = req_inp('period_start', 'period_start');
$period_end   = req_inp('period_end', 'period_end');
$basic_pay    = inp('basic_pay');
$deductions   = inp('deductions');
$net_pay      = inp('net_pay');
$notes        = trim((string)inp('notes', ''));

// Validate required numeric fields
if ($basic_pay === null || $basic_pay === '') fail('basic_pay is required');
if ($deductions === null || $deductions === '') fail('deductions is required');
if ($net_pay === null || $net_pay === '') fail('net_pay is required');

$basic_pay  = (float)$basic_pay;
$deductions = (float)$deductions;
$net_pay    = (float)$net_pay;

// Validate date ordering
if ($period_start > $period_end) {
    fail('period_start must be on or before period_end');
}

// Verify the employee belongs to this provider
$empStmt = db()->prepare(
    "SELECT id, first_name, last_name, employee_id, department, position
     FROM employees WHERE id = :eid AND provider_id = :pid LIMIT 1"
);
$empStmt->execute([':eid' => $employee_id, ':pid' => $pid]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) fail('Employee not found', 404);

// Build a period name if not supplied — mirrors the web portal pattern
$period_name = trim((string)inp('period_name', ''));
if ($period_name === '') {
    $period_name = date('F Y', strtotime($period_start)) . ' (' .
                   date('M d', strtotime($period_start)) . ' – ' .
                   date('M d', strtotime($period_end)) . ')';
}

try {
    // TABLE: payroll — confirmed from provider-portal/payroll.php
    // Stores gross_salary in gross_salary column; basic_salary mirrors gross here
    // since the caller already supplies the period's basic_pay (gross).
    $ins = db()->prepare(
        "INSERT INTO payroll
            (provider_id, employee_id, pay_period_name, pay_period_start, pay_period_end,
             basic_salary, gross_salary, deductions, net_salary, status)
         VALUES
            (:pid, :eid, :pn, :ps, :pe,
             :basic, :gross, :ded, :net, 'pending')"
    );
    $ins->execute([
        ':pid'   => $pid,
        ':eid'   => $employee_id,
        ':pn'    => $period_name,
        ':ps'    => $period_start,
        ':pe'    => $period_end,
        ':basic' => $basic_pay,
        ':gross' => $basic_pay,   // gross = basic_pay in this simplified path
        ':ded'   => $deductions,
        ':net'   => $net_pay,
    ]);
    $record_id = (int)db()->lastInsertId();
} catch (Exception $e) {
    fail('Failed to insert payroll record: ' . $e->getMessage(), 500);
}

ok([
    'message' => 'Payroll record created',
    'payroll' => [
        'id'              => $record_id,
        'employee_id'     => $employee_id,
        'employee_code'   => $employee['employee_id'],
        'first_name'      => $employee['first_name'],
        'last_name'       => $employee['last_name'],
        'pay_period_name' => $period_name,
        'pay_period_start'=> $period_start,
        'pay_period_end'  => $period_end,
        'basic_salary'    => $basic_pay,
        'gross_salary'    => $basic_pay,
        'deductions'      => $deductions,
        'net_salary'      => $net_pay,
        'status'          => 'pending',
    ],
], 201);
