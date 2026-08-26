<?php
// api/v1/portal/subscriptions/index.php
// GET — owner only — returns current subscription, all plans, and tier label.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');

$staff       = require_portal_role('owner');
$provider_id = (int)$staff['provider_id'];

$pdo = db();

// ── Current subscription ──────────────────────────────────────────────────────
$sub_stmt = $pdo->prepare(
    "SELECT ps.*, sp.name AS plan_name, sp.monthly_price, sp.yearly_price
     FROM provider_subscriptions ps
     JOIN subscription_plans sp ON ps.plan_id = sp.id
     WHERE ps.provider_id = :pid
     ORDER BY ps.created_at DESC
     LIMIT 1"
);
$sub_stmt->execute([':pid' => $provider_id]);
$current_sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);

// ── Tier label (mirrors getProviderTier logic from portal-tier.php) ───────────
$tier_label  = 'free';
$tier_expires = null;
$tier_grace   = null;

if ($current_sub) {
    $status = $current_sub['status'];
    $now    = date('Y-m-d H:i:s');

    if (in_array($status, ['active', 'grace']) && $current_sub['expires_at']) {
        if ($current_sub['expires_at'] > $now) {
            $tier_label  = 'pro';
            $tier_expires = $current_sub['expires_at'];
            $tier_grace   = $current_sub['grace_ends_at'];
        } else {
            // Check grace
            $grace_ends = $current_sub['grace_ends_at']
                ?: date('Y-m-d H:i:s', strtotime($current_sub['expires_at']) + 3 * 86400);
            if ($grace_ends > $now) {
                $tier_label  = 'grace';
                $tier_expires = $current_sub['expires_at'];
                $tier_grace   = $grace_ends;
                // Lazy-update status if needed
                if ($current_sub['status'] !== 'grace') {
                    try {
                        $pdo->prepare(
                            "UPDATE provider_subscriptions SET status='grace', grace_ends_at=:g, updated_at=NOW() WHERE id=:id"
                        )->execute([':g' => $grace_ends, ':id' => (int)$current_sub['id']]);
                    } catch (Exception $e) {}
                }
            } else {
                // Fully expired
                if ($current_sub['status'] !== 'expired') {
                    try {
                        $pdo->prepare(
                            "UPDATE provider_subscriptions SET status='expired', updated_at=NOW() WHERE id=:id"
                        )->execute([':id' => (int)$current_sub['id']]);
                    } catch (Exception $e) {}
                }
            }
        }
    }
}

// ── Available plans ───────────────────────────────────────────────────────────
$plans_stmt = $pdo->prepare(
    "SELECT id, name, monthly_price, yearly_price FROM subscription_plans WHERE is_active = 1 ORDER BY id ASC"
);
$plans_stmt->execute();
$plans_rows = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

$plans = array_map(function (array $p): array {
    return [
        'id'            => (int)$p['id'],
        'name'          => $p['name'],
        'monthly_price' => (float)$p['monthly_price'],
        'yearly_price'  => (float)$p['yearly_price'],
        'savings'       => round((float)$p['monthly_price'] * 12 - (float)$p['yearly_price'], 2),
    ];
}, $plans_rows);

// ── Shape current subscription for output ─────────────────────────────────────
$subscription = null;
if ($current_sub) {
    $subscription = [
        'id'            => (int)$current_sub['id'],
        'plan_id'       => (int)$current_sub['plan_id'],
        'plan_name'     => $current_sub['plan_name'],
        'billing_cycle' => $current_sub['billing_cycle'],
        'status'        => $current_sub['status'],
        'starts_at'     => $current_sub['started_at'],
        'expires_at'    => $current_sub['expires_at'],
        'grace_ends_at' => $current_sub['grace_ends_at'],
        'amount'        => isset($current_sub['amount']) ? (float)$current_sub['amount'] : null,
        'created_at'    => $current_sub['created_at'],
    ];
}

ok([
    'tier'         => $tier_label,
    'tier_expires' => $tier_expires,
    'tier_grace'   => $tier_grace,
    'subscription' => $subscription,
    'plans'        => $plans,
]);
