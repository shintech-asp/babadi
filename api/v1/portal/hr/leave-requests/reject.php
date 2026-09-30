<?php
// POST api/v1/portal/hr/leave-requests/reject
// Access: owner, hr | Tier: free — see approve.php's comment; web enforces
// no tier check on this action either.
// Body: request_id
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];

$rid = (int) req_inp('request_id', 'request_id');
$pdo = db();

$stmt = $pdo->prepare("SELECT status FROM leave_requests WHERE id = :id AND provider_id = :p");
$stmt->execute([':id' => $rid, ':p' => $pid]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) fail('Leave request not found.', 404);
if ($req['status'] !== 'pending') fail('This request is no longer pending.', 422);

$pdo->prepare("UPDATE leave_requests SET status='rejected', approved_by=:by, updated_at=NOW() WHERE id=:id AND provider_id=:p")
    ->execute([':by' => $staff['id'] ?? 0, ':id' => $rid, ':p' => $pid]);

ok(['data' => ['id' => $rid, 'status' => 'rejected', 'message' => 'Leave request rejected.']]);
