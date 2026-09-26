<?php
// POST api/v1/portal/hr/payroll/approve
// Finance approves a pending payroll run (pending -> processed) — mirrors
// provider-portal/payroll.php's 'approve' action.
// Access: owner, finance | Tier: Pro required.
//
// Body: payroll_id
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$rid = (int) req_inp('payroll_id', 'payroll_id');
$pdo = db();

$stmt = $pdo->prepare("SELECT status FROM payroll WHERE id = :id AND provider_id = :p");
$stmt->execute([':id' => $rid, ':p' => $pid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) fail('Payroll record not found.', 404);
if ($row['status'] !== 'pending') fail('Only a pending payroll record can be approved.', 422);

$pdo->prepare("UPDATE payroll SET status='processed' WHERE id=:id AND provider_id=:p AND status='pending'")
    ->execute([':id' => $rid, ':p' => $pid]);

ok(['data' => ['id' => $rid, 'status' => 'processed', 'message' => 'Payroll approved and ready to pay.']]);
