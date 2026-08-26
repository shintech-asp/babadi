<?php
// POST /api/v1/admin/subscription-plans/expire.php
// Manually expire all active/grace subscriptions for a provider.
// Access: super_admin only.
// Body: { provider_id }

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
require_admin_role('super_admin');

$providerId = (int)req_inp('provider_id', 'provider_id');
if ($providerId <= 0) fail('provider_id must be a positive integer');

// Verify provider exists.
$pCheck = db()->prepare("SELECT id FROM providers WHERE id = :id LIMIT 1");
$pCheck->execute([':id' => $providerId]);
if (!$pCheck->fetch()) fail('Provider not found', 404);

$stmt = db()->prepare(
    "UPDATE provider_subscriptions
     SET status = 'expired'
     WHERE provider_id = :pid
       AND status IN ('active', 'grace')"
);
$stmt->execute([':pid' => $providerId]);
$affected = $stmt->rowCount();

ok([
    'data' => [
        'provider_id'      => $providerId,
        'rows_expired'     => $affected,
    ],
]);
