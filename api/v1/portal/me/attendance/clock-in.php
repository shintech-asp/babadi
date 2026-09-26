<?php
// POST api/v1/portal/me/attendance/clock-in
// Self clock-in for the authenticated employee (or portal_staff with a
// linked employee record). No Pro-tier gate — self-service clock in/out is
// a basic feature on every tier (mirrors provider-portal/timekeeping.php).
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/timekeeping_helper.php';

allow('POST');

$actor = require_portal_actor();
if (!$actor['employee_id']) {
    fail('No linked employee record for this account.', 403);
}

$result = selfClockIn(db(), $actor['provider_id'], $actor['employee_id']);
if (!$result['ok']) {
    fail($result['error'], 422);
}

ok(['data' => [
    'message'  => 'Time-in recorded',
    'time_in'  => $result['time_in'],
    'late_min' => $result['late_min'],
    'status'   => $result['status'],
]]);
