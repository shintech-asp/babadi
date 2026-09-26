<?php
// POST /api/v1/portal/hr/attendance/clock-in
// HR staff (or owner) clocks an employee in for today.
// Access: owner, hr   |   Tier: Pro required
//
// Body (JSON or form-data):
//   employee_id  (int, required)
//   notes        (string, optional)

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$employee_id = (int)req_inp('employee_id', 'employee_id');
$notes       = trim((string)inp('notes', ''));

// Verify the employee belongs to this provider
$empStmt = db()->prepare(
    "SELECT id, first_name, last_name, employee_id FROM employees
     WHERE id = :eid AND provider_id = :pid AND status = 'active' LIMIT 1"
);
$empStmt->execute([':eid' => $employee_id, ':pid' => $pid]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) fail('Employee not found or inactive', 404);

$today = date('Y-m-d');
$now   = date('H:i:s');

// Check for an existing record today
$existsStmt = db()->prepare(
    "SELECT id, time_in, time_out FROM attendance
     WHERE provider_id = :pid AND employee_id = :eid AND date = :today LIMIT 1"
);
$existsStmt->execute([':pid' => $pid, ':eid' => $employee_id, ':today' => $today]);
$existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

if ($existing && $existing['time_in']) {
    fail('Employee is already clocked in today', 409);
}

try {
    if ($existing) {
        // Record exists without a time_in (e.g. manually pre-created absent row) — update it
        $upd = db()->prepare(
            "UPDATE attendance
             SET time_in = :ti, time_in_mode = 'manual', status = 'present', notes = :notes
             WHERE id = :id"
        );
        $upd->execute([':ti' => $now, ':notes' => $notes, ':id' => (int)$existing['id']]);
        $record_id = (int)$existing['id'];
    } else {
        $ins = db()->prepare(
            "INSERT INTO attendance
                (provider_id, employee_id, date, time_in, time_in_mode, status, notes)
             VALUES (:pid, :eid, :date, :ti, 'manual', 'present', :notes)"
        );
        $ins->execute([
            ':pid'   => $pid,
            ':eid'   => $employee_id,
            ':date'  => $today,
            ':ti'    => $now,
            ':notes' => $notes,
        ]);
        $record_id = (int)db()->lastInsertId();
    }
} catch (Exception $e) {
    fail('Failed to record clock-in: ' . $e->getMessage(), 500);
}

ok([
    'message'     => 'Clock-in recorded successfully',
    'attendance'  => [
        'id'          => $record_id,
        'employee_id' => $employee_id,
        'first_name'  => $employee['first_name'],
        'last_name'   => $employee['last_name'],
        'date'        => $today,
        'time_in'     => $now,
        'status'      => 'present',
    ],
]);
