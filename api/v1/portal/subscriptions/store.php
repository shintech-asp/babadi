<?php
// api/v1/portal/subscriptions/store.php
// POST — owner only — create a PayMongo checkout session and a pending subscription row.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$staff       = require_portal_role('owner');
$provider_id = (int)$staff['provider_id'];

// ── Input validation ──────────────────────────────────────────────────────────
$plan_id       = (int)req_inp('plan_id', 'plan_id');
$billing_cycle = req_inp('billing_cycle', 'billing_cycle');

if (!in_array($billing_cycle, ['monthly', 'yearly'], true)) {
    fail('billing_cycle must be "monthly" or "yearly"');
}

if (!defined('PAYMONGO_SECRET_KEY') || !PAYMONGO_SECRET_KEY) {
    fail('PayMongo is not configured on this server.', 503);
}

$pdo = db();

// ── Fetch plan ────────────────────────────────────────────────────────────────
$plan_stmt = $pdo->prepare(
    "SELECT id, name, monthly_price, yearly_price FROM subscription_plans WHERE id = :id AND is_active = 1 LIMIT 1"
);
$plan_stmt->execute([':id' => $plan_id]);
$plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    fail('Plan not found or inactive.', 404);
}

$amount   = $billing_cycle === 'yearly' ? (float)$plan['yearly_price'] : (float)$plan['monthly_price'];
$label    = $plan['name'] . ($billing_cycle === 'yearly' ? ' (Yearly)' : ' (Monthly)');
$centavos = (int)round($amount * 100);

// ── Create PayMongo checkout session ─────────────────────────────────────────
$success_url = SITE_URL . '/provider-portal/subscription-success.php';
$cancel_url  = SITE_URL . '/provider-portal/subscriptions.php';

$payload = json_encode([
    'data' => [
        'attributes' => [
            'line_items'           => [[
                'currency' => 'PHP',
                'amount'   => $centavos,
                'name'     => $label,
                'quantity' => 1,
            ]],
            'payment_method_types' => ['gcash', 'card', 'paymaya'],
            'success_url'          => $success_url,
            'cancel_url'           => $cancel_url,
            'metadata'             => [
                'provider_id'   => $provider_id,
                'plan_id'       => (int)$plan['id'],
                'billing_cycle' => $billing_cycle,
            ],
        ],
    ],
]);

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ],
]);
$res  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($res === false) {
    fail('Could not connect to PayMongo.', 502);
}

$result = json_decode($res, true);

if (!in_array($http, [200, 201], true) || !isset($result['data']['attributes']['checkout_url'])) {
    $detail = $result['errors'][0]['detail'] ?? ($result['errors'][0]['code'] ?? 'PayMongo error.');
    fail('PayMongo checkout failed: ' . $detail, 502);
}

$checkout_url = $result['data']['attributes']['checkout_url'];
$session_id   = $result['data']['id'];

// ── Expire stale pending rows to avoid pile-up ───────────────────────────────
try {
    $pdo->prepare(
        "UPDATE provider_subscriptions SET status='expired', updated_at=NOW()
         WHERE provider_id = :pid AND plan_id IS NOT NULL AND status = 'pending'"
    )->execute([':pid' => $provider_id]);
} catch (Exception $e) {}

// ── Insert pending subscription row ──────────────────────────────────────────
try {
    $ins = $pdo->prepare(
        "INSERT INTO provider_subscriptions
             (provider_id, plan_id, plan, billing_cycle, status, amount, paymongo_link_id, checkout_url, created_at)
         VALUES
             (:pid, :plan_id, 'pro', :cycle, 'pending', :amount, :session_id, :checkout_url, NOW())"
    );
    $ins->execute([
        ':pid'          => $provider_id,
        ':plan_id'      => (int)$plan['id'],
        ':cycle'        => $billing_cycle,
        ':amount'       => $amount,
        ':session_id'   => $session_id,
        ':checkout_url' => $checkout_url,
    ]);
} catch (Exception $e) {
    fail('Subscription record could not be created: ' . $e->getMessage(), 500);
}

$subscription_id = (int)$pdo->lastInsertId();

ok([
    'checkout_url'    => $checkout_url,
    'subscription_id' => $subscription_id,
]);
