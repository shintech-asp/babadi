<?php
// POST api/v1/portal/hr/leave-requests/approve
// Approve a pending leave request — enforces remaining balance, mirrors
// provider-portal/leave-requests.php's 'approve' action.
// Access: owner, hr | Tier: free — grep confirms leave-requests.php's
// approve/reject/add POST handlers have zero $tier_is_paid checks (only the
// page's own "Free Tier — View Only" banner claims otherwise); this
// endpoint previously kept a Pro gate that made the identical action 403 on
// mobile while it succeeded on web for the same free-tier user.
//
// Body: request_id
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'provider-portal/includes/leave_balance_helper.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];

$rid = (int) req_inp('request_id', 'request_id');
$pdo = db();

$stmt = $pdo->prepare("SELECT employee_id, leave_type, start_date, end_date, status FROM leave_requests WHERE id = :id AND provider_id = :p");
$stmt->execute([':id' => $rid, ':p' => $pid]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) fail('Leave request not found.', 404);
if ($req['status'] !== 'pending') fail('This request is no longer pending.', 422);

$days = countLeaveCalendarDays($req['start_date'], $req['end_date']);
$bal  = getLeaveBalance($pdo, $pid, (int)$req['employee_id'], $req['leave_type'], (int)date('Y', strtotime($req['start_date'])));

if ($days > $bal['remaining']) {
    fail("Cannot approve: this employee only has {$bal['remaining']} {$req['leave_type']} leave day(s) left, but the request is for $days day(s).", 422);
}

$pdo->prepare("UPDATE leave_requests SET status='approved', approved_by=:by, updated_at=NOW() WHERE id=:id AND provider_id=:p")
    ->execute([':by' => $staff['id'] ?? 0, ':id' => $rid, ':p' => $pid]);
deductLeaveBalance($pdo, $pid, (int)$req['employee_id'], $req['leave_type'], (int)date('Y', strtotime($req['start_date'])), $days);

ok(['data' => ['id' => $rid, 'status' => 'approved', 'message' => 'Leave request approved.']]);
