<?php
// api/v1/portal/subscriptions/trial.php
// POST — owner only — activate a true no-payment 1-month free trial.
// Mirrors provider-portal/subscriptions.php's start_trial handler exactly:
// eligibility is "this provider has never had ANY subscription row before"
// (trial or paid), which also caps it at one trial ever by construction.
// getProviderTier() needs no changes — a trial row with status='active'
// and a real plan_id is indistinguishable from a paid one for feature
// gating purposes.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$staff       = require_portal_role('owner');
$provider_id = (int)$staff['provider_id'];

$pdo = db();

$count_stmt = $pdo->prepare('SELECT COUNT(*) FROM provider_subscriptions WHERE provider_id = ?');
$count_stmt->execute([$provider_id]);
$everHadSub = (int)$count_stmt->fetchColumn();

if ($everHadSub > 0) {
    fail('You are not eligible for a free trial — a trial is only available for brand-new accounts.', 403);
}

$plan_stmt = $pdo->prepare("SELECT id FROM subscription_plans WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
$plan_stmt->execute();
$plan_id = $plan_stmt->fetchColumn();

if (!$plan_id) {
    fail('No active plan found. Contact admin.', 503);
}

try {
    $pdo->prepare(
        "INSERT INTO provider_subscriptions
            (provider_id, plan_id, plan, billing_cycle, status, is_trial, amount, started_at, expires_at, created_at)
         VALUES
            (:pid, :plan_id, 'pro', 'monthly', 'active', 1, 0, NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW())"
    )->execute([':pid' => $provider_id, ':plan_id' => (int)$plan_id]);
} catch (Exception $e) {
    fail('Could not start your free trial. Please try again.', 500);
}

$expires_stmt = $pdo->prepare('SELECT expires_at FROM provider_subscriptions WHERE provider_id = ? ORDER BY id DESC LIMIT 1');
$expires_stmt->execute([$provider_id]);
$expires_at = $expires_stmt->fetchColumn();

ok(['data' => ['expires_at' => $expires_at]]);
