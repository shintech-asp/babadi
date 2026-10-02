<?php
// provider-portal/subscription-success.php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
date_default_timezone_set('Asia/Manila');

if ($portal_role !== 'owner') { header('Location: dashboard.php'); exit; }

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$error = $success = '';

// ── Find the most recent pending subscription with plan_id ───────────────
try {
    $s = $db->prepare(
        "SELECT * FROM provider_subscriptions
         WHERE provider_id = :p AND plan_id IS NOT NULL AND status = 'pending'
         ORDER BY created_at DESC LIMIT 1"
    );
    $s->execute([':p' => $pid]);
    $sub = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sub = null;
}

if (!$sub) {
    header('Location: subscriptions.php?error=noorder'); exit;
}

// Fix #2: derive cycle from the DB row, not the client URL
$cycle = in_array($sub['billing_cycle'] ?? '', ['monthly', 'yearly']) ? $sub['billing_cycle'] : 'monthly';

// Fix #1: never auto-approve; always require real PayMongo verification
$verified = false;
if (!defined('PAYMONGO_SECRET_KEY') || !PAYMONGO_SECRET_KEY) {
    $error = 'PayMongo is not configured. Add PAYMONGO_SECRET_KEY to config/config.php to activate subscriptions.';
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
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200) {
        $result = json_decode($res, true);
        $pm_status = $result['data']['attributes']['payment_intent']['attributes']['status']
                  ?? $result['data']['attributes']['status']
                  ?? null;
        // H4 fix: exclude 'active' (open-but-unpaid session state); only genuinely-paid statuses
        $verified = in_array($pm_status, ['paid', 'succeeded', 'completed']);

        // Fallback: check payments array
        if (!$verified) {
            $payments = $result['data']['attributes']['payments'] ?? [];
            foreach ($payments as $pmnt) {
                if (($pmnt['attributes']['status'] ?? '') === 'paid') {
                    $verified = true; break;
                }
            }
        }
    }
}

if ($verified) {
    try {
        $now = date('Y-m-d H:i:s');

        // M2 fix: if currently active, extend from existing expires_at; don't shorten early renewals
        $baseTime = ($sub['expires_at'] && $sub['expires_at'] > $now)
            ? $sub['expires_at'] : $now;
        $expiry = $cycle === 'yearly'
            ? date('Y-m-d H:i:s', strtotime('+1 year',  strtotime($baseTime)))
            : date('Y-m-d H:i:s', strtotime('+1 month', strtotime($baseTime)));
        $grace  = date('Y-m-d H:i:s', strtotime($expiry) + (3 * 86400));

        // Idempotency: only activate if still pending
        $stmt = $db->prepare(
            "UPDATE provider_subscriptions
             SET status='active', billing_cycle=:cycle, expires_at=:exp, grace_ends_at=:grace, started_at=COALESCE(started_at, NOW()), updated_at=NOW()
             WHERE id=:id AND status='pending'"
        );
        $stmt->execute([':cycle' => $cycle, ':exp' => $expiry, ':grace' => $grace, ':id' => (int)$sub['id']]);

        if ($stmt->rowCount() > 0) {
            // M5 fix: PRG — redirect so refresh doesn't re-verify with PayMongo
            header('Location: subscriptions.php?activated=1&exp=' . urlencode($expiry));
            exit;
        } else {
            // Already activated (race or refresh)
            header('Location: subscriptions.php?activated=1');
            exit;
        }
    } catch (Exception $e) {
        $error = "Payment verified but activation failed. Contact support. (Ref: " . htmlspecialchars($sub['paymongo_link_id']) . ")";
    }
} elseif (!$error) {
    $error = "We could not confirm your payment yet. If you completed the payment, it may take a moment — please visit your subscription page again shortly.";
}

require_once 'includes/portal-tier.php';
$active_menu = 'subscriptions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Subscription <?= $success ? 'Activated' : 'Pending' ?> &middot; <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096;--pro:#6366f1;--pro2:#4f46e5}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0;display:flex;align-items:center;justify-content:center;padding:40px}
.result-card{background:#fff;border-radius:16px;border:1px solid var(--border);padding:40px 36px;max-width:480px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.07)}
.icon-wrap{width:72px;height:72px;border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 20px}
.icon-success{background:linear-gradient(135deg,#d1fae5,#a7f3d0);color:#065f46}
.icon-pending{background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e}
.icon-error{background:linear-gradient(135deg,#fee2e2,#fecaca);color:#991b1b}
h2{font-size:20px;font-weight:800;color:var(--dark);margin-bottom:8px}
.sub-text{font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:22px;max-width:340px;margin-left:auto;margin-right:auto}
.feature-list{background:#f8fafc;border-radius:10px;padding:16px;margin-bottom:22px;text-align:left}
.feature-list li{list-style:none;font-size:12px;color:#374151;padding:4px 0;display:flex;align-items:center;gap:8px}
.feature-list li i{color:var(--primary);font-size:10px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;font-family:inherit;text-decoration:none;transition:all .2s;margin:4px}
.btn-primary{background:var(--primary);color:#fff;box-shadow:0 4px 12px rgba(46,139,87,.3)}
.btn-primary:hover{transform:translateY(-1px);filter:brightness(1.05)}
.btn-ghost{background:#f1f5f9;color:var(--dark);border:1.5px solid var(--border)}
.btn-ghost:hover{border-color:var(--primary);color:var(--primary)}
@media(max-width:540px){.portal-main{padding:16px}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="result-card">
<?php if ($success): ?>
    <div class="icon-wrap icon-success"><i class="fas fa-star"></i></div>
    <h2>You're Pro!</h2>
    <p class="sub-text"><?= htmlspecialchars($success) ?> All portal features are now unlocked for your team.</p>
    <ul class="feature-list">
        <li><i class="fas fa-check"></i> Full HR &mdash; attendance, payroll, recruitment, timekeeping</li>
        <li><i class="fas fa-check"></i> Geofenced biometrics clock-in (10km radius)</li>
        <li><i class="fas fa-check"></i> Full Finance &mdash; expenses, budget requests, reports</li>
        <li><i class="fas fa-check"></i> Full CRM &mdash; schedules, QR scan, archive, service management</li>
        <li><i class="fas fa-check"></i> 3-day grace period on next renewal</li>
    </ul>
    <div>
        <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-gauge"></i> Go to Dashboard</a>
        <a href="subscriptions.php" class="btn btn-ghost"><i class="fas fa-receipt"></i> View Billing</a>
    </div>
<?php elseif ($error): ?>
    <div class="icon-wrap icon-error"><i class="fas fa-circle-exclamation"></i></div>
    <h2>Payment Not Confirmed</h2>
    <p class="sub-text"><?= htmlspecialchars($error) ?></p>
    <div>
        <a href="subscriptions.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Subscriptions</a>
    </div>
<?php else: ?>
    <div class="icon-wrap icon-pending"><i class="fas fa-clock"></i></div>
    <h2>Processing…</h2>
    <p class="sub-text">We're verifying your payment. This usually takes just a moment. Please wait.</p>
    <div><a href="subscriptions.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Subscriptions</a></div>
<?php endif; ?>
</div>
</div>
</div>
</body>
</html>
