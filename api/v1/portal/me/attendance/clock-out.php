<?php
// POST api/v1/portal/me/attendance/clock-out
// Self clock-out for the authenticated employee (or linked staff account).
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/timekeeping_helper.php';

allow('POST');

$actor = require_portal_actor();
if (!$actor['employee_id']) {
    fail('No linked employee record for this account.', 403);
}

$result = selfClockOut(db(), $actor['provider_id'], $actor['employee_id']);
if (!$result['ok']) {
    fail($result['error'], 422);
}

ok(['data' => [
    'message'        => 'Time-out recorded',
    'time_in'        => $result['time_in'],
    'time_out'       => $result['time_out'],
    'hours_worked'   => $result['hours_worked'],
    'overtime_min'   => $result['overtime_min'],
    'undertime_min'  => $result['undertime_min'],
    'status'         => $result['status'],
]]);
