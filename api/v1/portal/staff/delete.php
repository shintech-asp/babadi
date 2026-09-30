<?php
// api/v1/portal/staff/delete.php
// POST — soft-delete (deactivate) a staff member (owner only).

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner');
$pid   = (int)$staff['provider_id'];
$pdo   = db();

// ── Input ─────────────────────────────────────────────────────────────────────

$target_id = (int)req_inp('id', 'id');

// Prevent deleting own account
if ($target_id === (int)$staff['id']) {
    fail('You cannot delete your own account', 403);
}

// ── Verify target exists and belongs to this provider ─────────────────────────

$chk = $pdo->prepare(
    "SELECT id, status FROM provider_staff WHERE id = ? AND provider_id = ? LIMIT 1"
);
$chk->execute([$target_id, $pid]);
$target = $chk->fetch(PDO::FETCH_ASSOC);

if (!$target) {
    fail('Staff member not found', 404);
}

if ($target['status'] === 'inactive') {
    fail('Staff member is already inactive', 409);
}

// ── Soft delete ───────────────────────────────────────────────────────────────

$upd = $pdo->prepare(
    "UPDATE provider_staff SET status = 'inactive' WHERE id = ? AND provider_id = ?"
);
$upd->execute([$target_id, $pid]);

ok(['data' => ['message' => 'Staff member deactivated successfully', 'id' => $target_id]]);
