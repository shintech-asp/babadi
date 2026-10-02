<?php
// api/v1/portal/subscriptions/index.php
// GET — any portal actor (owner, hr/finance/crm staff, or a plain employee)
// — returns current subscription, all plans, and tier label. Read access is
// intentionally NOT owner-only: portal-sidebar.php shows the tier badge and
// Pro-lock icons to every role on the web, not just the owner, so the
// mobile shell needs the same visibility to render its own gating
// correctly. Only store.php (actually purchasing/renewing) stays owner-only
// — matching the web sidebar's "Upgrade to Pro"/"Subscription" link, which
// is the one piece gated to `$is_owner`.
require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__, 4) . '/provider-portal/includes/portal-tier.php';

allow('GET');

$actor       = require_portal_actor();
$provider_id = (int)$actor['provider_id'];

$pdo = db();

// Tier label via the same getProviderTier() the web uses — this endpoint
// used to re-implement the same query with two real divergences: it never
// excluded a still-`pending` renewal row (so requesting an early renewal
// made a genuinely active subscription report as 'free' the moment the
// pending row's created_at became the newest), and it picked the "current"
// row by created_at instead of expires_at, which can disagree once a
// renewal exists. Delegating removes both by construction.
$tierInfo = getProviderTier($pdo, $provider_id);
$tier_label   = $tierInfo['tier'] === 'paid' ? 'pro' : $tierInfo['tier'];
$tier_expires = $tierInfo['expires_at'];
$tier_grace   = $tierInfo['grace_ends_at'];

// ── Current subscription (for display) — same active/grace + plan_id +
// expires_at filter as getProviderTier(), so what's shown here always
// matches the tier just computed above rather than possibly surfacing an
// unrelated newer-but-pending row.
$sub_stmt = $pdo->prepare(
    "SELECT ps.*, sp.name AS plan_name, sp.monthly_price, sp.yearly_price
     FROM provider_subscriptions ps
     JOIN subscription_plans sp ON ps.plan_id = sp.id
     WHERE ps.provider_id = :pid AND ps.plan_id IS NOT NULL
       AND ps.status IN ('active', 'grace')
       AND ps.expires_at IS NOT NULL
     ORDER BY ps.expires_at DESC, ps.id DESC
     LIMIT 1"
);
$sub_stmt->execute([':pid' => $provider_id]);
$current_sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);

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

// Trial-eligible = never had any subscription row before (trial or paid)
// — same rule as the web's subscriptions.php. One free trial, ever.
$everHadSub_stmt = $pdo->prepare('SELECT COUNT(*) FROM provider_subscriptions WHERE provider_id = ?');
$everHadSub_stmt->execute([$provider_id]);
$trial_eligible = ((int)$everHadSub_stmt->fetchColumn()) === 0;

ok([
    'tier'            => $tier_label,
    'tier_expires'    => $tier_expires,
    'tier_grace'      => $tier_grace,
    'subscription'    => $subscription,
    'plans'           => $plans,
    'trial_eligible'  => $trial_eligible,
]);
