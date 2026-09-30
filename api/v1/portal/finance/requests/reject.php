<?php
// POST /api/v1/portal/finance/requests/reject
// Rejects a pending budget request outright (no approved amount) — mirrors
// provider-portal/budget-requests.php's 'reject' action. Owner-only, same
// reasoning as approve.php.
// Access: owner only | Tier: Pro required.
//
// Body: request_id, remarks (optional)

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$rid     = (int) req_inp('request_id', 'request_id');
$remarks = trim((string) inp('remarks', ''));

$pdo = db();
$stmt = $pdo->prepare("SELECT status FROM budget_requests WHERE id = :id AND provider_id = :p");
$stmt->execute([':id' => $rid, ':p' => $pid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) fail('Budget request not found.', 404);
if ($row['status'] !== 'pending') fail('This request is no longer pending.', 422);

$pdo->prepare(
    "UPDATE budget_requests SET status = 'rejected', approved_by = :by, remarks = :r, updated_at = NOW()
     WHERE id = :id AND provider_id = :p"
)->execute([':by' => $staff['id'], ':r' => $remarks, ':id' => $rid, ':p' => $pid]);

ok(['data' => ['id' => $rid, 'status' => 'rejected', 'message' => 'Budget request rejected.']]);
