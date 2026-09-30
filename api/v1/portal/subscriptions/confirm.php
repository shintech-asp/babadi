<?php
// api/v1/portal/subscriptions/confirm.php
// POST — owner only — verifies the provider's most recent pending
// subscription with PayMongo directly and activates it if paid.
//
// This exists because provider-portal/subscription-success.php (PayMongo's
// success_url) cannot work from the mobile app: it's gated by
// includes/portal-auth.php, a PHP-SESSION check (`$_SESSION['portal_staff_id']`),
// but the Flutter WebView only carries a JWT Authorization header — no
// PHPSESSID cookie — so PayMongo's redirect there always bounces to
// auth/login.php and the activation UPDATE never runs. The subscription
// stays 'pending' forever and the provider is charged with nothing
// activating. This endpoint does the identical verify-and-activate work as
// subscription-success.php, but authenticated via JWT instead of a session —
// same pattern as api/v1/seeker/bookings/confirm-payment.php relative to the
// web's payment-success-result.php.
//
// Safe to call repeatedly: PayMongo verification is re-checked each time,
// and the activation UPDATE is guarded by `status='pending'` so a second
// call after activation is a no-op that still reports the now-active state.

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$staff       = require_portal_role('owner');
$provider_id = (int)$staff['provider_id'];

$pdo = db();

// ── Find the most recent pending subscription with a plan ─────────────────────
$s = $pdo->prepare(
    "SELECT * FROM provider_subscriptions
     WHERE provider_id = :p AND plan_id IS NOT NULL AND status = 'pending'
     ORDER BY created_at DESC LIMIT 1"
);
$s->execute([':p' => $provider_id]);
$sub = $s->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    // Nothing pending — either already activated by a prior call, or there
    // was never a checkout started. Report the actual current tier either way.
    require_once dirname(__DIR__, 4) . '/provider-portal/includes/portal-tier.php';
    $tier = getProviderTier($pdo, $provider_id);
    ok(['data' => [
        'verified' => $tier['tier'] !== 'free',
        'tier'     => $tier['tier'] === 'paid' ? 'pro' : $tier['tier'],
        'message'  => $tier['tier'] !== 'free' ? 'Subscription already active.' : 'No pending checkout found.',
    ]]);
}

$cycle = in_array($sub['billing_cycle'] ?? '', ['monthly', 'yearly'], true) ? $sub['billing_cycle'] : 'monthly';

// ── Verify with PayMongo directly ──────────────────────────────────────────────
$verified = false;
$error    = null;

if (!defined('PAYMONGO_SECRET_KEY') || !PAYMONGO_SECRET_KEY) {
    $error = 'PayMongo is not configured on this server.';
} elseif (!$sub['paymongo_link_id']) {
    $error = 'Payment reference missing. Contact support.';
} else {
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . urlencode($sub['paymongo_link_id']));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200) {
        $result    = json_decode($res, true);
        $pm_status = $result['data']['attributes']['payment_intent']['attributes']['status']
                  ?? $result['data']['attributes']['status']
                  ?? null;
        $verified = in_array($pm_status, ['paid', 'succeeded', 'completed'], true);

        if (!$verified) {
            $payments = $result['data']['attributes']['payments'] ?? [];
            foreach ($payments as $pmnt) {
                if (($pmnt['attributes']['status'] ?? '') === 'paid') {
                    $verified = true;
                    break;
                }
            }
        }
    }
}

if (!$verified) {
    ok(['data' => [
        'verified' => false,
        'tier'     => 'free',
        'message'  => $error ?? 'We could not confirm your payment yet. If you completed the payment, it may take a moment — try again shortly.',
    ]]);
}

// ── Activate ────────────────────────────────────────────────────────────────────
$now      = date('Y-m-d H:i:s');
$baseTime = ($sub['expires_at'] && $sub['expires_at'] > $now) ? $sub['expires_at'] : $now;
$expiry   = $cycle === 'yearly'
    ? date('Y-m-d H:i:s', strtotime('+1 year',  strtotime($baseTime)))
    : date('Y-m-d H:i:s', strtotime('+1 month', strtotime($baseTime)));
$grace    = date('Y-m-d H:i:s', strtotime($expiry) + (3 * 86400));

$upd = $pdo->prepare(
    "UPDATE provider_subscriptions
     SET status='active', billing_cycle=:cycle, expires_at=:exp, grace_ends_at=:grace, updated_at=NOW()
     WHERE id=:id AND status='pending'"
);
$upd->execute([':cycle' => $cycle, ':exp' => $expiry, ':grace' => $grace, ':id' => (int)$sub['id']]);

ok(['data' => [
    'verified'   => true,
    'tier'       => 'pro',
    'expires_at' => $expiry,
    'message'    => "You're Pro! All portal features are now unlocked.",
]]);
