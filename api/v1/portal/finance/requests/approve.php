<?php
// POST /api/v1/portal/finance/requests/approve
// Approve (or reject, if approved_amount is 0) a pending budget request —
// mirrors provider-portal/budget-requests.php's 'approve' action exactly.
// Only the owner sees the Approve/Reject controls on the web page (the
// "Action" column is wrapped in `if ($portal_role === 'owner')`), so this
// endpoint is owner-only too, though the web's own POST handler itself
// never actually re-checked that at the backend layer — tightened here to
// match the real intended access rather than the accidentally-permissive
// backend.
// Access: owner only | Tier: Pro required.
//
// Body: request_id, approved_amount (>0 -> approved, 0 -> rejected), remarks (optional)

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$rid           = (int) req_inp('request_id', 'request_id');
$approved_amt  = (float) inp('approved_amount', 0);
$remarks       = trim((string) inp('remarks', ''));

$pdo = db();
$stmt = $pdo->prepare("SELECT status FROM budget_requests WHERE id = :id AND provider_id = :p");
$stmt->execute([':id' => $rid, ':p' => $pid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) fail('Budget request not found.', 404);
if ($row['status'] !== 'pending') fail('This request is no longer pending.', 422);

$status = $approved_amt > 0 ? 'approved' : 'rejected';

$pdo->prepare(
    "UPDATE budget_requests SET status = :s, approved_amount = :aa, approved_by = :by, remarks = :r, updated_at = NOW()
     WHERE id = :id AND provider_id = :p"
)->execute([
    ':s' => $status, ':aa' => $approved_amt, ':by' => $staff['id'], ':r' => $remarks, ':id' => $rid, ':p' => $pid,
]);

ok(['data' => ['id' => $rid, 'status' => $status, 'approved_amount' => $approved_amt, 'message' => "Budget request " . ucfirst($status) . "."]]);
