<?php
// POST /api/v1/admin/subscription-plans/activate.php
// Manually activate (or extend) a provider subscription.
// Access: super_admin only.
// Body: { provider_id, plan_id, billing_cycle ('monthly'|'yearly'), months (int, default 1) }
// billing_cycle='yearly' always adds 12 months regardless of `months`.

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
require_admin_role('super_admin');

$providerId   = (int)req_inp('provider_id', 'provider_id');
$planId       = (int)req_inp('plan_id', 'plan_id');
$billingCycle = req_inp('billing_cycle', 'billing_cycle');
$months       = (int)inp('months', 1);

if ($providerId <= 0) fail('provider_id must be a positive integer');
if ($planId     <= 0) fail('plan_id must be a positive integer');
if (!in_array($billingCycle, ['monthly', 'yearly'], true)) {
    fail("billing_cycle must be 'monthly' or 'yearly'");
}
if ($months < 1) $months = 1;

// Determine interval: yearly always means 12 months; monthly uses $months.
$intervalMonths = ($billingCycle === 'yearly') ? 12 : $months;

// Verify provider exists.
$pCheck = db()->prepare("SELECT id FROM providers WHERE id = :id LIMIT 1");
$pCheck->execute([':id' => $providerId]);
if (!$pCheck->fetch()) fail('Provider not found', 404);

// Verify plan exists.
$spCheck = db()->prepare("SELECT id FROM subscription_plans WHERE id = :id LIMIT 1");
$spCheck->execute([':id' => $planId]);
if (!$spCheck->fetch()) fail('Subscription plan not found', 404);

// Check for an existing active/grace/pending row for this provider.
$existing = db()->prepare(
    "SELECT id FROM provider_subscriptions
     WHERE provider_id = :pid
     ORDER BY id DESC LIMIT 1"
);
$existing->execute([':pid' => $providerId]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row) {
    // Update the most recent row.
    $upd = db()->prepare(
        "UPDATE provider_subscriptions
         SET plan_id       = :plan_id,
             billing_cycle = :billing_cycle,
             status        = 'active',
             started_at    = NOW(),
             expires_at    = NOW() + INTERVAL :months MONTH,
             grace_ends_at = NULL
         WHERE id = :id"
    );
    $upd->execute([
        ':plan_id'       => $planId,
        ':billing_cycle' => $billingCycle,
        ':months'        => $intervalMonths,
        ':id'            => (int)$row['id'],
    ]);
    $subId = (int)$row['id'];
} else {
    // Insert a new row.
    $ins = db()->prepare(
        "INSERT INTO provider_subscriptions
             (provider_id, plan_id, billing_cycle, status, started_at, expires_at, grace_ends_at)
         VALUES
             (:provider_id, :plan_id, :billing_cycle, 'active',
              NOW(), NOW() + INTERVAL :months MONTH, NULL)"
    );
    $ins->execute([
        ':provider_id'   => $providerId,
        ':plan_id'       => $planId,
        ':billing_cycle' => $billingCycle,
        ':months'        => $intervalMonths,
    ]);
    $subId = (int)db()->lastInsertId();
}

// Re-fetch the final row to return accurate timestamps.
$fetch = db()->prepare(
    "SELECT id, provider_id, plan_id, billing_cycle, status, started_at, expires_at
     FROM provider_subscriptions WHERE id = :id LIMIT 1"
);
$fetch->execute([':id' => $subId]);
$sub = $fetch->fetch(PDO::FETCH_ASSOC);

ok([
    'data' => [
        'id'            => (int)$sub['id'],
        'provider_id'   => (int)$sub['provider_id'],
        'plan_id'       => (int)$sub['plan_id'],
        'billing_cycle' => $sub['billing_cycle'],
        'status'        => $sub['status'],
        'started_at'    => $sub['started_at'],
        'expires_at'    => $sub['expires_at'],
    ],
]);
