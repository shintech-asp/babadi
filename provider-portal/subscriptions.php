<?php
// provider-portal/subscriptions.php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
date_default_timezone_set('Asia/Manila');

if ($portal_role !== 'owner') { header('Location: dashboard.php'); exit; }

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

require_once 'includes/portal-tier.php';

function safeRow_sub($db, $sql, $p = []) {
    try { $s = $db->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return null; }
}
function safeAll_sub($db, $sql, $p = []) {
    try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return []; }
}

$success = $error = '';

// M5: PRG landing — show success after activation redirect
if (isset($_GET['activated'])) {
    $exp = $_GET['exp'] ?? null;
    $success = 'Your Pro subscription is now active!' . ($exp ? ' Expires ' . date('M j, Y', strtotime($exp)) . '.' : '');
}

if (isset($_GET['cancelled'])) $error = 'Payment cancelled. No charge was made.';

// ── POST: Cancel pending ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_pending'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        try {
            $db->prepare(
                "UPDATE provider_subscriptions SET status='cancelled', updated_at=NOW()
                 WHERE provider_id=:p AND plan_id IS NOT NULL AND status='pending'"
            )->execute([':p' => $pid]);
            $success = 'Pending payment cancelled. You can start a new subscription below.';
        } catch (Exception $e) { $error = 'Could not cancel. Please try again.'; }
    }
}

// ── POST: Subscribe ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cycle'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } elseif (!defined('PAYMONGO_SECRET_KEY') || !PAYMONGO_SECRET_KEY) {
        $error = 'PayMongo is not configured. Add PAYMONGO_SECRET_KEY to config/config.php.';
    } else {
        $cycle = ($_POST['cycle'] === 'yearly') ? 'yearly' : 'monthly';
        $plan  = safeRow_sub($db, "SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY id ASC LIMIT 1");
        if (!$plan) { $error = 'No active plan found. Contact admin.'; }
        else {
            $amount   = $cycle === 'yearly' ? (float)$plan['yearly_price'] : (float)$plan['monthly_price'];
            $label    = $cycle === 'yearly' ? $plan['name'] . ' (Yearly)' : $plan['name'] . ' (Monthly)';
            $centavos = (int)round($amount * 100);

            $success_url = SITE_URL . "/provider-portal/subscription-success.php?pid={$pid}&cycle={$cycle}";
            $cancel_url  = SITE_URL . "/provider-portal/subscriptions.php?cancelled=1";

            $payload = ['data' => ['attributes' => [
                'billing'              => ['name' => $portal_company],
                'line_items'           => [['currency' => 'PHP', 'amount' => $centavos, 'name' => $label, 'quantity' => 1]],
                'payment_method_types' => ['gcash', 'card', 'paymaya'],
                'success_url'          => $success_url,
                'cancel_url'           => $cancel_url,
                'metadata'             => ['provider_id' => $pid, 'cycle' => $cycle, 'plan_id' => (int)$plan['id']],
            ]]];

            $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                ],
            ]);
            $res  = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result = json_decode($res, true);

            if (in_array($http, [200, 201]) && isset($result['data']['attributes']['checkout_url'])) {
                $checkout_url = $result['data']['attributes']['checkout_url'];
                $session_id   = $result['data']['id'];
                // M1 fix: expire prior abandoned pending rows so they don't pile up or confuse success.php
                try {
                    $db->prepare(
                        "UPDATE provider_subscriptions SET status='expired', updated_at=NOW()
                         WHERE provider_id=:p AND plan_id IS NOT NULL AND status='pending'"
                    )->execute([':p' => $pid]);
                } catch (Exception $e) {}

                // Don't swallow INSERT error — abort redirect if record can't be created
                $insert_ok = true;
                try {
                    $db->prepare(
                        "INSERT INTO provider_subscriptions (provider_id, plan_id, plan, billing_cycle, status, amount, paymongo_link_id, checkout_url, created_at)
                         VALUES (:pid, :planid, 'pro', :cycle, 'pending', :amt, :sid, :curl, NOW())"
                    )->execute([':pid' => $pid, ':planid' => (int)$plan['id'], ':cycle' => $cycle, ':amt' => $amount, ':sid' => $session_id, ':curl' => $checkout_url]);
                } catch (Exception $e) {
                    $insert_ok = false;
                    $error = "Could not create subscription record before redirecting to payment. Please contact support. (Detail: " . htmlspecialchars($e->getMessage()) . ")";
                }
                if ($insert_ok) { header("Location: $checkout_url"); exit; }
            } else {
                $err   = $result['errors'][0]['detail'] ?? ($result['errors'][0]['code'] ?? 'PayMongo error. Check your API keys.');
                $error = "Could not create checkout: $err";
            }
        }
    }
}

$plan    = safeRow_sub($db, "SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY id ASC LIMIT 1");

// Detect pending (abandoned) checkout so user can continue or cancel
$pending_sub = safeRow_sub($db,
    "SELECT * FROM provider_subscriptions WHERE provider_id=:p AND plan_id IS NOT NULL AND status='pending' ORDER BY created_at DESC LIMIT 1",
    [':p' => $pid]
);
$pending_checkout_url = null;
if ($pending_sub) {
    $pending_checkout_url = $pending_sub['checkout_url'];
    // Fallback: re-fetch from PayMongo if URL wasn't stored (old rows)
    if (!$pending_checkout_url && $pending_sub['paymongo_link_id'] && defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY) {
        $ch2 = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . urlencode($pending_sub['paymongo_link_id']));
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>[
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ]]);
        $pm_res  = curl_exec($ch2);
        $pm_http = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($pm_http === 200) {
            $pm_data = json_decode($pm_res, true);
            $pending_checkout_url = $pm_data['data']['attributes']['checkout_url'] ?? null;
        }
    }
}

$history = safeAll_sub($db,
    "SELECT * FROM provider_subscriptions WHERE provider_id=:p AND plan_id IS NOT NULL ORDER BY created_at DESC LIMIT 20",
    [':p' => $pid]
);

$monthly = (float)($plan['monthly_price'] ?? 500);
$yearly  = (float)($plan['yearly_price']  ?? 5000);
$savings = ($monthly * 12) - $yearly;

$days_left = 0;
if ($tier_is_paid && $tier_expires) {
    $days_left = max(0, (int)ceil((strtotime($tier_expires) - time()) / 86400));
}

$active_menu = 'subscriptions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Subscription &middot; <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096;--pro:#6366f1;--pro2:#4f46e5}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:28px;max-width:860px}
.page-hd{margin-bottom:22px}
.page-hd h1{font-size:20px;font-weight:800;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-hd p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.alert-error{background:#fee2e2;border:1px solid rgba(220,38,38,.2);color:#991b1b}
.alert-warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e}
.tier-current{background:#fff;border-radius:14px;border:1px solid var(--border);padding:18px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.tier-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.tier-icon.free{background:#f1f5f9;color:var(--muted)}
.tier-icon.paid,.tier-icon.grace{background:linear-gradient(135deg,var(--pro),var(--pro2));color:#fff}
.tier-info h3{font-size:15px;font-weight:800;color:var(--dark);margin-bottom:2px}
.tier-info p{font-size:12px;color:var(--muted)}
.tier-badge-pill{margin-left:auto;padding:6px 16px;border-radius:999px;font-size:11px;font-weight:800}
.tier-badge-pill.free{background:#f1f5f9;color:var(--muted)}
.tier-badge-pill.paid{background:#eef2ff;color:var(--pro2)}
.tier-badge-pill.grace{background:#fef3c7;color:#92400e}
.plan-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.plan-card{background:#fff;border-radius:14px;border:1.5px solid var(--border);padding:24px;position:relative;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.plan-card.rec{border-color:var(--pro);box-shadow:0 0 0 3px rgba(99,102,241,.1),0 4px 16px rgba(0,0,0,.06)}
.rec-ribbon{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,var(--pro),var(--pro2));color:#fff;font-size:9px;font-weight:800;padding:3px 14px;border-radius:999px;letter-spacing:.7px;white-space:nowrap}
.plan-cycle{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:8px}
.plan-price{font-size:34px;font-weight:900;color:var(--dark);line-height:1;font-variant-numeric:tabular-nums}
.plan-price sup{font-size:16px;font-weight:700;vertical-align:super;color:var(--muted)}
.plan-period{font-size:11px;color:var(--muted);margin-top:3px}
.plan-save{display:inline-block;background:#dcfce7;color:#166534;font-size:10px;font-weight:800;padding:2px 9px;border-radius:999px;margin:6px 0 14px}
.plan-divider{height:1px;background:var(--border);margin:14px 0}
.plan-feat{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#374151;margin-bottom:8px;line-height:1.4}
.plan-feat i{color:var(--primary);font-size:10px;margin-top:2px;flex-shrink:0}
.btn-plan{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:12px;border-radius:10px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;margin-top:14px;transition:all .2s}
.btn-outline-plan{background:#f8fafc;color:var(--dark);border:1.5px solid var(--border)}
.btn-outline-plan:hover{border-color:var(--pro);color:var(--pro)}
.btn-pro{background:linear-gradient(135deg,var(--pro),var(--pro2));color:#fff;box-shadow:0 4px 14px rgba(99,102,241,.3)}
.btn-pro:hover{transform:translateY(-1px)}
.card{background:#fff;border-radius:12px;border:1px solid var(--border);overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.card-head{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.card-head h2{font-size:13px;font-weight:700;color:var(--dark)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:9px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:12.5px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-green{background:#dcfce7;color:#166534}
.pill-gray{background:#f1f5f9;color:#475569}
.pill-amber{background:#fef3c7;color:#92400e}
.pill-blue{background:#dbeafe;color:#1d4ed8}
.pill-red{background:#fee2e2;color:#991b1b}
.no-key-box{background:#fef9c3;border:1.5px solid #fde047;border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:12.5px;color:#854d0e;display:flex;gap:10px}
.no-key-box code{background:#fef08a;padding:1px 5px;border-radius:4px;font-size:11px}
@media(max-width:680px){.plan-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

<div class="page-hd">
    <h1><i class="fas fa-star" style="color:var(--pro)"></i>
        <?= $tier_is_paid ? 'Your Subscription' : 'Upgrade to Pro' ?>
    </h1>
    <p><?= $tier_is_paid
        ? 'Manage your Pro subscription and billing history.'
        : 'Unlock the full provider portal &mdash; HR, Finance, CRM, and Biometric Attendance.' ?></p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($provider_tier === 'grace'): ?>
<div class="alert alert-warn">
    <i class="fas fa-triangle-exclamation"></i>
    Your subscription expired. You have <strong><?= $days_left ?> day(s)</strong> of grace access remaining. Renew below to keep full access.
</div>
<?php endif; ?>

<?php if ($pending_sub): ?>
<div class="alert alert-warn" style="flex-direction:column;align-items:flex-start;gap:10px">
    <div style="display:flex;align-items:center;gap:8px;font-weight:700"><i class="fas fa-clock"></i> Pending Payment</div>
    <div style="font-size:12.5px">
        You have an unfinished <strong><?= ucfirst($pending_sub['billing_cycle'] ?? 'monthly') ?></strong>
        payment of <strong>&#8369;<?= number_format((float)$pending_sub['amount'], 2) ?></strong> started
        <?= date('M j, Y g:i A', strtotime($pending_sub['created_at'])) ?>.
        <?= $pending_checkout_url ? 'You can continue where you left off, or cancel to start a new one.' : 'The checkout session may have expired &mdash; cancel to start fresh.' ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($pending_checkout_url): ?>
        <a href="<?= htmlspecialchars($pending_checkout_url) ?>" target="_blank"
           style="display:inline-flex;align-items:center;gap:6px;background:#2E8B57;color:#fff;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none">
            <i class="fas fa-arrow-up-right-from-square"></i> Continue Payment
        </a>
        <?php endif; ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Cancel this pending payment?')">
            <input type="hidden" name="cancel_pending" value="1">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <button type="submit" style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:none;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">
                <i class="fas fa-xmark"></i> Cancel Payment
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!defined('PAYMONGO_SECRET_KEY') || !PAYMONGO_SECRET_KEY): ?>
<div class="no-key-box">
    <i class="fas fa-triangle-exclamation" style="margin-top:2px;flex-shrink:0"></i>
    <div>
        <strong>PayMongo Not Configured</strong><br>
        Add your secret key to <code>config/config.php</code>:<br>
        <code>define('PAYMONGO_SECRET_KEY', 'sk_test_xxxxxxxx');</code>
    </div>
</div>
<?php endif; ?>

<!-- Current tier status -->
<div class="tier-current">
    <div class="tier-icon <?= $provider_tier ?>">
        <?php echo $tier_is_paid ? '<i class="fas fa-star"></i>' : '<i class="fas fa-user"></i>'; ?>
    </div>
    <div class="tier-info">
        <h3>Current Plan: <?php
            if ($provider_tier === 'paid')       echo 'Pro';
            elseif ($provider_tier === 'grace')  echo 'Pro (Grace Period)';
            else                                 echo 'Free';
        ?></h3>
        <p><?php
            if ($provider_tier === 'paid' && $tier_expires)
                echo 'Renews ' . date('M j, Y', strtotime($tier_expires)) . ' &middot; ' . ucfirst($tier_cycle ?? 'monthly') . ' billing';
            elseif ($provider_tier === 'grace' && $tier_grace)
                echo 'Grace period ends ' . date('M j, Y', strtotime($tier_grace));
            else
                echo 'Limited features &middot; No expiry';
        ?></p>
    </div>
    <span class="tier-badge-pill <?= $provider_tier ?>">
        <?php
        if ($provider_tier === 'paid')       echo 'Pro Active';
        elseif ($provider_tier === 'grace')  echo 'Grace Period';
        else                                 echo 'Free';
        ?>
    </span>
</div>

<!-- Plan cards -->
<div class="plan-grid">
    <!-- Monthly -->
    <div class="plan-card">
        <div class="plan-cycle">Monthly</div>
        <div class="plan-price"><sup>&#8369;</sup><?= number_format($monthly, 0) ?></div>
        <div class="plan-period">per month &middot; billed monthly</div>
        <div class="plan-divider"></div>
        <div class="plan-feat"><i class="fas fa-check"></i> Full HR &mdash; attendance, payroll, recruitment</div>
        <div class="plan-feat"><i class="fas fa-check"></i> Geofenced biometrics clock-in (10km radius)</div>
        <div class="plan-feat"><i class="fas fa-check"></i> Full Finance &mdash; expenses, budget, reports</div>
        <div class="plan-feat"><i class="fas fa-check"></i> Full CRM &mdash; schedules, QR scan, archive</div>
        <div class="plan-feat"><i class="fas fa-check"></i> 3-day grace period on expiry</div>
        <?php if (defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY && !$pending_sub): ?>
        <form method="POST">
            <input type="hidden" name="cycle" value="monthly">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <button type="submit" class="btn-plan btn-outline-plan">
                <i class="fas fa-credit-card"></i>
                <?= $tier_is_paid ? 'Renew Monthly' : 'Pay Monthly' ?>
            </button>
        </form>
        <?php elseif ($pending_sub): ?>
        <button class="btn-plan btn-outline-plan" disabled style="opacity:.45;cursor:not-allowed" title="Resolve your pending payment above first">
            <i class="fas fa-clock"></i> Payment Pending
        </button>
        <?php else: ?>
        <button class="btn-plan btn-outline-plan" disabled style="opacity:.5;cursor:not-allowed">Configure PayMongo First</button>
        <?php endif; ?>
    </div>

    <!-- Yearly -->
    <div class="plan-card rec">
        <span class="rec-ribbon">BEST VALUE</span>
        <div class="plan-cycle">Yearly</div>
        <div class="plan-price"><sup>&#8369;</sup><?= number_format($yearly, 0) ?></div>
        <div class="plan-period">per year &middot; billed once</div>
        <?php if ($savings > 0): ?>
        <div class="plan-save">Save &#8369;<?= number_format($savings, 0) ?> vs monthly</div>
        <?php endif; ?>
        <div class="plan-divider"></div>
        <div class="plan-feat"><i class="fas fa-check"></i> Full HR &mdash; attendance, payroll, recruitment</div>
        <div class="plan-feat"><i class="fas fa-check"></i> Geofenced biometrics clock-in (10km radius)</div>
        <div class="plan-feat"><i class="fas fa-check"></i> Full Finance &mdash; expenses, budget, reports</div>
        <div class="plan-feat"><i class="fas fa-check"></i> Full CRM &mdash; schedules, QR scan, archive</div>
        <div class="plan-feat"><i class="fas fa-check"></i> 3-day grace period on expiry</div>
        <?php if (defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY && !$pending_sub): ?>
        <form method="POST">
            <input type="hidden" name="cycle" value="yearly">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <button type="submit" class="btn-plan btn-pro">
                <i class="fas fa-star"></i>
                <?= $tier_is_paid ? 'Renew Yearly &mdash; Best Value' : 'Pay Yearly &mdash; Best Value' ?>
            </button>
        </form>
        <?php elseif ($pending_sub): ?>
        <button class="btn-plan btn-pro" disabled style="opacity:.45;cursor:not-allowed" title="Resolve your pending payment above first">
            <i class="fas fa-clock"></i> Payment Pending
        </button>
        <?php else: ?>
        <button class="btn-plan btn-pro" disabled style="opacity:.5;cursor:not-allowed">Configure PayMongo First</button>
        <?php endif; ?>
    </div>
</div>

<p style="text-align:center;font-size:11px;color:var(--muted);margin-bottom:22px">
    Secure payment via GCash, Card, or PayMaya &middot; Powered by PayMongo
</p>

<!-- Payment History -->
<div class="card">
    <div class="card-head">
        <i class="fas fa-receipt" style="color:var(--primary)"></i>
        <h2>Payment History</h2>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Plan</th><th>Billing</th><th>Amount</th><th>Status</th><th>Expires</th><th>Date</th></tr>
        </thead>
        <tbody>
        <?php if (empty($history)): ?>
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">
            <i class="fas fa-receipt" style="font-size:28px;opacity:.2;display:block;margin-bottom:8px"></i>
            No payment history yet.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($history as $h):
            [$pc, $pt] = match($h['status']) {
                'active'  => ['pill-green', 'Active'],
                'grace'   => ['pill-amber', 'Grace'],
                'expired' => ['pill-gray',  'Expired'],
                'pending' => ['pill-blue',  'Pending'],
                default   => ['pill-gray',  ucfirst($h['status'])],
            };
        ?>
        <tr>
            <td><strong>Pro Plan</strong></td>
            <td style="text-transform:capitalize"><?= escape($h['billing_cycle'] ?? '&mdash;') ?></td>
            <td style="font-weight:700;font-variant-numeric:tabular-nums">&#8369;<?= number_format((float)$h['amount'], 2) ?></td>
            <td><span class="pill <?= $pc ?>"><?= $pt ?></span></td>
            <td style="font-size:11px;color:var(--muted)"><?= $h['expires_at'] ? date('M j, Y', strtotime($h['expires_at'])) : '&mdash;' ?></td>
            <td style="font-size:11px;color:var(--muted)"><?= date('M j, Y', strtotime($h['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></div>
</div>
</body>
</html>
