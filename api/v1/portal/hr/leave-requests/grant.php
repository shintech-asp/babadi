<?php
// POST api/v1/portal/hr/leave-requests/grant
// HR directly grants paid leave for an employee — auto-approved so it
// counts toward payroll's attendance computation right away. Mirrors
// provider-portal/leave-requests.php's 'add' action.
// Access: owner, hr | Tier: Pro required.
//
// Body: employee_id, leave_type, start_date, end_date, reason (optional)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'provider-portal/includes/leave_balance_helper.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$eid    = (int) req_inp('employee_id', 'employee_id');
$type   = req_inp('leave_type', 'leave_type');
$start  = req_inp('start_date', 'start_date');
$end    = req_inp('end_date', 'end_date');
$reason = trim((string) inp('reason', ''));

$validTypes = ['annual', 'sick', 'personal', 'maternity', 'paternity'];
if (!in_array($type, $validTypes, true)) {
    fail('leave_type must be one of: ' . implode(', ', $validTypes));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || strtotime($end) < strtotime($start)) {
    fail('Please provide a valid start_date and end_date.');
}

$pdo = db();

$empStmt = $pdo->prepare("SELECT id FROM employees WHERE id = :id AND provider_id = :p LIMIT 1");
$empStmt->execute([':id' => $eid, ':p' => $pid]);
if (!$empStmt->fetch()) fail('Employee not found.', 404);

$days = countLeaveCalendarDays($start, $end);
$bal  = getLeaveBalance($pdo, $pid, $eid, $type, (int)date('Y', strtotime($start)));

if ($days > $bal['remaining']) {
    fail("Cannot grant: this employee only has {$bal['remaining']} $type leave day(s) left, but this range is $days day(s). Adjust the dates, pick a different leave type, or set a per-employee override.", 422);
}

$pdo->prepare("INSERT INTO leave_requests (provider_id,employee_id,leave_type,start_date,end_date,reason,status,approved_by) VALUES (:p,:e,:t,:s,:en,:r,'approved',:by)")
    ->execute([':p'=>$pid, ':e'=>$eid, ':t'=>$type, ':s'=>$start, ':en'=>$end, ':r'=>$reason, ':by'=>$staff['id'] ?? 0]);
deductLeaveBalance($pdo, $pid, $eid, $type, (int)date('Y', strtotime($start)), $days);

ok(['data' => ['id' => (int)$pdo->lastInsertId(), 'message' => 'Paid leave granted for the employee.', 'days' => $days]], 201);
