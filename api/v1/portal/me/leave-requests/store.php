<?php
// POST api/v1/portal/me/leave-requests/store
// The authenticated employee submits a leave request against their own
// balance — mirrors provider-portal/my-leave-requests.php.
//
// Body: leave_type (annual|sick|personal|maternity|paternity), start_date,
//       end_date (Y-m-d), reason (optional)
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'provider-portal/includes/leave_balance_helper.php';

allow('POST');

$actor = require_portal_actor();
if (!$actor['employee_id']) {
    fail('No linked employee record for this account.', 403);
}

$type   = req_inp('leave_type', 'leave_type');
$start  = req_inp('start_date', 'start_date');
$end    = req_inp('end_date', 'end_date');
$reason = trim((string) inp('reason', ''));

$validTypes = ['annual', 'sick', 'personal', 'maternity', 'paternity'];
if (!in_array($type, $validTypes, true)) {
    fail('leave_type must be one of: ' . implode(', ', $validTypes));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    fail('start_date and end_date must be Y-m-d.');
}
if (strtotime($end) < strtotime($start)) {
    fail('end_date cannot be before start_date.');
}

$pdo = db();
$days = countLeaveCalendarDays($start, $end);
$bal  = getLeaveBalance($pdo, $actor['provider_id'], $actor['employee_id'], $type, (int)date('Y', strtotime($start)));

if ($days > $bal['remaining']) {
    fail("You only have {$bal['remaining']} $type leave day(s) left this year, but this request is for $days day(s).", 422);
}

try {
    $pdo->prepare(
        "INSERT INTO leave_requests (provider_id, employee_id, leave_type, start_date, end_date, reason, status)
         VALUES (:p, :e, :t, :s, :en, :r, 'pending')"
    )->execute([
        ':p' => $actor['provider_id'], ':e' => $actor['employee_id'], ':t' => $type,
        ':s' => $start, ':en' => $end, ':r' => $reason,
    ]);
} catch (Exception $e) {
    fail('Could not submit request. Please try again.', 500);
}

ok(['data' => [
    'id' => (int)$pdo->lastInsertId(),
    'message' => 'Leave request submitted.',
    'days' => $days,
]], 201);
