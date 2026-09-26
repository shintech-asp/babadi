<?php
// POST api/v1/portal/hr/payroll/generate
// Bulk-generates payroll for every active employee for one pay period,
// computed from real attendance/timekeeping/leave data (see
// includes/payroll_helper.php — shared with provider-portal/payroll.php's
// 'generate' action so both stay in sync).
// Access: owner, hr | Tier: Pro required.
//
// Body: month (YYYY-MM), half (1|2, default 1)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/payroll_helper.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$month = inp('month') ?: date('Y-m');
$half  = (inp('half') ?: '1') === '2' ? '2' : '1';

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    fail('month must be in YYYY-MM format.');
}

$result = generatePayrollForPeriod(db(), $pid, $month, $half);

ok(['data' => [
    'message'      => "Generated payroll for {$result['generated']} employee(s) — {$result['period_name']}.",
    'generated'    => $result['generated'],
    'skipped'      => $result['skipped'],
    'period_name'  => $result['period_name'],
    'period_start' => $result['period_start'],
    'period_end'   => $result['period_end'],
]], 201);
