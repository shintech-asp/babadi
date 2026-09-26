<?php
// POST /api/v1/portal/hr/attendance/clock-out
// Closes an open clock-in record for today.
// Access: owner, hr   |   Tier: Pro required
//
// Body (JSON or form-data):
//   employee_id  (int, required)

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$employee_id = (int)req_inp('employee_id', 'employee_id');

// Verify the employee belongs to this provider
$empStmt = db()->prepare(
    "SELECT id, first_name, last_name FROM employees
     WHERE id = :eid AND provider_id = :pid LIMIT 1"
);
$empStmt->execute([':eid' => $employee_id, ':pid' => $pid]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) fail('Employee not found', 404);

$today = date('Y-m-d');
$now   = date('H:i:s');

// Find an open attendance record (clocked in, not yet clocked out) for today
$openStmt = db()->prepare(
    "SELECT id, time_in FROM attendance
     WHERE provider_id = :pid AND employee_id = :eid AND date = :today
       AND time_in IS NOT NULL AND time_out IS NULL
     LIMIT 1"
);
$openStmt->execute([':pid' => $pid, ':eid' => $employee_id, ':today' => $today]);
$record = $openStmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    fail('No open clock-in found for this employee today', 404);
}

// Calculate total hours
$time_in_ts   = strtotime($today . ' ' . $record['time_in']);
$time_out_ts  = strtotime($today . ' ' . $now);
$total_hours  = round(($time_out_ts - $time_in_ts) / 3600, 2);

try {
    $upd = db()->prepare(
        "UPDATE attendance
         SET time_out = :tout, total_hours = :hours
         WHERE id = :id"
    );
    $upd->execute([
        ':tout'  => $now,
        ':hours' => $total_hours,
        ':id'    => (int)$record['id'],
    ]);
} catch (Exception $e) {
    fail('Failed to record clock-out: ' . $e->getMessage(), 500);
}

ok([
    'message'    => 'Clock-out recorded successfully',
    'attendance' => [
        'id'          => (int)$record['id'],
        'employee_id' => $employee_id,
        'first_name'  => $employee['first_name'],
        'last_name'   => $employee['last_name'],
        'date'        => $today,
        'time_in'     => $record['time_in'],
        'time_out'    => $now,
        'total_hours' => $total_hours,
    ],
]);
