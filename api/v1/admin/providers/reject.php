<?php
// api/v1/admin/providers/reject.php
// POST — reject a provider (sets status=rejected, verification_status=rejected).
// Access: super_admin, admin
// Input: id (required), reason (optional rejection notes)

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
$admin = require_admin_role('super_admin', 'admin');

$id     = (int)req_inp('id');
$reason = trim((string)inp('reason', ''));

// Confirm provider exists
$check = db()->prepare("SELECT id FROM providers WHERE id = :id LIMIT 1");
$check->execute([':id' => $id]);
if (!$check->fetch()) fail('Provider not found', 404);

$stmt = db()->prepare(
    "UPDATE providers
     SET status              = 'rejected',
         verification_status = 'rejected',
         verification_notes  = :notes,
         verification_date   = NOW(),
         verification_reviewed_by      = :reviewed_by,
         verification_reviewed_by_name = :reviewed_by_name,
         updated_at          = NOW()
     WHERE id = :id"
);
$stmt->execute([
    ':id'               => $id,
    ':notes'            => $reason,
    ':reviewed_by'      => (int)$admin['id'],
    ':reviewed_by_name' => trim($admin['full_name'] ?? $admin['username'] ?? ''),
]);

if ($stmt->rowCount() === 0) fail('Provider not found or no change made', 404);

ok(['message' => 'Provider rejected']);
