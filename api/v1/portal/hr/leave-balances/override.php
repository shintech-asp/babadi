<?php
// POST api/v1/portal/hr/leave-balances/override
// Sets a per-employee leave-day override for the current year, overriding
// the company-wide default from Settings > HR for that one employee.
// Mirrors provider-portal/settings.php's "Per-Employee Leave Override" card.
// Access: owner, hr | Tier: free — settings.php has no tier check at all
// (grep confirms zero $tier_is_paid references in that file), so this
// endpoint's Pro gate was stricter than web for the identical action.
//
// Body: employee_id, and one or more of:
//   annual_override_days, sick_override_days, personal_override_days,
//   maternity_override_days, paternity_override_days
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'provider-portal/includes/leave_balance_helper.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];

$eid = (int) req_inp('employee_id', 'employee_id');

$pdo = db();
$empStmt = $pdo->prepare("SELECT id FROM employees WHERE id = :id AND provider_id = :p LIMIT 1");
$empStmt->execute([':id' => $eid, ':p' => $pid]);
if (!$empStmt->fetch()) fail('Employee not found.', 404);

$leaveTypes = ['annual', 'sick', 'personal', 'maternity', 'paternity'];
$applied = [];
foreach ($leaveTypes as $lt) {
    $val = inp($lt . '_override_days');
    if ($val !== null && $val !== '') {
        setLeaveBalanceOverride($pdo, $eid, $lt, (int)date('Y'), (float)$val);
        $applied[$lt] = (float)$val;
    }
}

if (empty($applied)) {
    fail('No override values provided (e.g. annual_override_days).');
}

ok(['data' => ['employee_id' => $eid, 'year' => (int)date('Y'), 'applied' => $applied, 'message' => 'Leave override saved for this employee.']]);
