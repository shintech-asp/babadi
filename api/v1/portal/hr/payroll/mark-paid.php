<?php
// POST api/v1/portal/hr/payroll/mark-paid
// Finance marks an approved payroll record as paid — mirrors
// provider-portal/payroll.php's 'mark_paid' action.
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
if ($row['status'] !== 'processed') fail('Only an approved (processed) payroll record can be marked paid.', 422);

$pdo->prepare("UPDATE payroll SET status='paid', payment_date=NOW() WHERE id=:id AND provider_id=:p AND status='processed'")
    ->execute([':id' => $rid, ':p' => $pid]);

ok(['data' => ['id' => $rid, 'status' => 'paid', 'message' => 'Payroll marked as paid.']]);
