<?php
// admin/subscription-plans.php
$allowed_roles = ['super_admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
date_default_timezone_set('Asia/Manila');

$database = new Database();
$db = $database->getConnection();

$success = $error = '';

function db_run($db, $sql, $p = []) {
    try { $s = $db->prepare($sql); $s->execute($p); return $s; }
    catch (Exception $e) { return null; }
}
function db_row($db, $sql, $p = []) {
    $s = db_run($db, $sql, $p); return $s ? $s->fetch(PDO::FETCH_ASSOC) : null;
}
function db_all($db, $sql, $p = []) {
    $s = db_run($db, $sql, $p); return $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } elseif (isset($_POST['update_plan'])) {
        $monthly = (float)($_POST['monthly_price'] ?? 0);
        $yearly  = (float)($_POST['yearly_price']  ?? 0);
        $name    = trim($_POST['plan_name'] ?? 'Pro Plan');
        $desc    = trim($_POST['description'] ?? '');
        if ($monthly <= 0 || $yearly <= 0) {
            $error = 'Prices must be greater than zero.';
        } elseif (strlen($name) < 2) {
            $error = 'Plan name is too short.';
        } else {
            // Upsert: update if exists, insert if not
            $plan = db_row($db, "SELECT id FROM subscription_plans ORDER BY id ASC LIMIT 1");
            if ($plan) {
                db_run($db, "UPDATE subscription_plans SET name=:n, description=:d, monthly_price=:m, yearly_price=:y, updated_at=NOW() WHERE id=:id",
                    [':n' => $name, ':d' => $desc, ':m' => $monthly, ':y' => $yearly, ':id' => $plan['id']]);
            } else {
                db_run($db, "INSERT INTO subscription_plans (name, description, monthly_price, yearly_price, is_active) VALUES (:n,:d,:m,:y,1)",
                    [':n' => $name, ':d' => $desc, ':m' => $monthly, ':y' => $yearly]);
            }
            $success = 'Plan pricing updated successfully.';
        }
    } elseif (isset($_POST['activate_sub'])) {
        $sub_id = (int)($_POST['sub_id'] ?? 0);
        $cycle  = in_array($_POST['cycle'] ?? 'monthly', ['monthly','yearly']) ? $_POST['cycle'] : 'monthly';
        $expiry = $cycle === 'yearly'
            ? date('Y-m-d H:i:s', strtotime('+1 year'))
            : date('Y-m-d H:i:s', strtotime('+1 month'));
        $grace = date('Y-m-d H:i:s', strtotime($expiry) + 3 * 86400);
        db_run($db, "UPDATE provider_subscriptions SET status='active', billing_cycle=:c, expires_at=:e, grace_ends_at=:g, updated_at=NOW() WHERE id=:id",
            [':c' => $cycle, ':e' => $expiry, ':g' => $grace, ':id' => $sub_id]);
        // H3: audit trail for manual overrides
        db_run($db, "INSERT INTO admin_logs (admin_id, action, details) VALUES (:aid,'subscription_manual_activate',:det)",
            [':aid' => (int)($_SESSION['admin_id'] ?? 0), ':det' => "Manually activated subscription #$sub_id (cycle=$cycle, expires=$expiry)"]);
        $success = "Subscription #$sub_id manually activated ($cycle).";
    } elseif (isset($_POST['expire_sub'])) {
        $sub_id = (int)($_POST['sub_id'] ?? 0);
        db_run($db, "UPDATE provider_subscriptions SET status='expired', expires_at=NOW(), grace_ends_at=NOW(), updated_at=NOW() WHERE id=:id", [':id' => $sub_id]);
        // H3: audit trail
        db_run($db, "INSERT INTO admin_logs (admin_id, action, details) VALUES (:aid,'subscription_manual_expire',:det)",
            [':aid' => (int)($_SESSION['admin_id'] ?? 0), ':det' => "Manually expired subscription #$sub_id"]);
        $success = "Subscription #$sub_id manually expired.";
    }
}

$plan = db_row($db, "SELECT * FROM subscription_plans ORDER BY id ASC LIMIT 1");
$subs = db_all($db,
    "SELECT ps.*, sp.name AS plan_name, p.business_name
     FROM provider_subscriptions ps
     LEFT JOIN subscription_plans sp ON sp.id = ps.plan_id
     LEFT JOIN providers p ON p.id = ps.provider_id
     WHERE ps.plan_id IS NOT NULL
     ORDER BY ps.created_at DESC
     LIMIT 100"
);

$page_title = 'Subscription Plans';
include 'includes/admin-header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096;--pro:#6366f1;--pro2:#4f46e5}
body,*{font-family:'DM Sans',sans-serif}
.subplans-wrap{padding:24px;max-width:1040px}
.page-hd{margin-bottom:20px}
.page-hd h1{font-size:20px;font-weight:800;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-hd p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.alert-error{background:#fee2e2;border:1px solid rgba(220,38,38,.2);color:#991b1b}
.grid2{display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start;margin-bottom:22px}
.card{background:#fff;border-radius:12px;border:1px solid var(--border);overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.card-head{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.card-head h2{font-size:13px;font-weight:700;color:var(--dark)}
.card-body{padding:18px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:5px}
.form-control{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;color:#2d3748;transition:border-color .2s;background:#fff}
.form-control:focus{border-color:var(--primary);outline:none}
.input-group{position:relative}
.input-group .prefix{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:13px;color:var(--muted);font-weight:600}
.input-group .form-control{padding-left:26px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,139,87,.25)}
.btn-primary:hover{filter:brightness(1.07)}
.btn-sm{padding:5px 12px;font-size:11px;font-weight:700;border-radius:6px;cursor:pointer;border:none;font-family:inherit}
.btn-activate{background:#dcfce7;color:#166534}
.btn-expire{background:#fee2e2;color:#991b1b}
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.stat-card{background:#fff;border-radius:10px;border:1px solid var(--border);padding:14px 16px;text-align:center}
.stat-num{font-size:24px;font-weight:900;color:var(--dark);font-variant-numeric:tabular-nums}
.stat-label{font-size:11px;color:var(--muted);margin-top:2px}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:9px 12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left;white-space:nowrap}
tbody td{padding:9px 12px;border-bottom:1px solid var(--border);font-size:12.5px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#fafbff}
.pill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-green{background:#dcfce7;color:#166534}
.pill-gray{background:#f1f5f9;color:#475569}
.pill-amber{background:#fef3c7;color:#92400e}
.pill-blue{background:#dbeafe;color:#1d4ed8}
.pill-red{background:#fee2e2;color:#991b1b}
.no-key-warn{background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:14px}
@media(max-width:780px){.grid2{grid-template-columns:1fr}.stat-grid{grid-template-columns:1fr 1fr}}
</style>

<div class="subplans-wrap">
<div class="page-hd">
    <h1><i class="fas fa-star" style="color:var(--pro)"></i> Subscription Plans</h1>
    <p>Set Pro plan pricing and manage all provider subscriptions.</p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Summary stats -->
<?php
$counts = ['active'=>0,'grace'=>0,'expired'=>0,'pending'=>0];
foreach ($subs as $sub) $counts[$sub['status']] = ($counts[$sub['status']] ?? 0) + 1;
$total_active = $counts['active'] + $counts['grace'];
?>
<div class="stat-grid" style="margin-bottom:20px">
    <div class="stat-card"><div class="stat-num" style="color:var(--pro)"><?= $total_active ?></div><div class="stat-label">Active Subscriptions</div></div>
    <div class="stat-card"><div class="stat-num" style="color:#92400e"><?= $counts['grace'] ?></div><div class="stat-label">In Grace Period</div></div>
    <div class="stat-card"><div class="stat-num"><?= count($subs) ?></div><div class="stat-label">Total Records</div></div>
</div>

<div class="grid2">
<!-- Plan Pricing Editor -->
<div class="card">
    <div class="card-head"><i class="fas fa-sliders" style="color:var(--pro)"></i><h2>Pro Plan Pricing</h2></div>
    <div class="card-body">
        <?php if (!defined('PAYMONGO_SECRET_KEY') || !PAYMONGO_SECRET_KEY): ?>
        <div class="no-key-warn"><i class="fas fa-triangle-exclamation"></i> PayMongo API key not set in <strong>config/config.php</strong>. Payments will not process.</div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="update_plan" value="1">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="form-group">
                <label>Plan Name</label>
                <input class="form-control" type="text" name="plan_name" value="<?= htmlspecialchars($plan['name'] ?? 'Pro Plan') ?>" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($plan['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Monthly Price (PHP)</label>
                <div class="input-group">
                    <span class="prefix">₱</span>
                    <input class="form-control" type="number" name="monthly_price" min="1" step="0.01"
                           value="<?= number_format((float)($plan['monthly_price'] ?? 500), 2, '.', '') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Yearly Price (PHP)</label>
                <div class="input-group">
                    <span class="prefix">₱</span>
                    <input class="form-control" type="number" name="yearly_price" min="1" step="0.01"
                           value="<?= number_format((float)($plan['yearly_price'] ?? 5000), 2, '.', '') ?>" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Pricing</button>
        </form>
    </div>
</div>

<!-- Quick reference -->
<div class="card">
    <div class="card-head"><i class="fas fa-info-circle" style="color:var(--muted)"></i><h2>Free vs Pro Features</h2></div>
    <div style="padding:14px 18px;font-size:12px;color:#374151;line-height:1.8">
        <strong style="display:block;margin-bottom:6px;color:var(--dark)">Free Tier (Default)</strong>
        Staff management &middot; Basic leave requests &middot; Booking revenue view &middot; CRM view-only &middot; Messaging &middot; Basic dashboard
        <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
        <strong style="display:block;margin-bottom:6px;color:var(--pro)">Pro Tier (Paid)</strong>
        Full HR (attendance, timekeeping, payroll, recruitment) &middot; Geofenced biometrics &middot; Full Finance (expenses, budget) &middot; Full CRM (schedules, QR, archive, services) &middot; All dashboard stats
        <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
        <span style="color:var(--muted)">Grace Period: <strong>3 days</strong> after expiry</span>
    </div>
</div>
</div>

<!-- All Subscriptions Table -->
<div class="card">
    <div class="card-head"><i class="fas fa-list" style="color:var(--primary)"></i><h2>All Provider Subscriptions</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr>
                <th>#</th><th>Provider</th><th>Plan</th><th>Billing</th><th>Amount</th>
                <th>Status</th><th>Expires</th><th>Created</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($subs)): ?>
        <tr><td colspan="9" style="text-align:center;padding:36px;color:var(--muted)">
            <i class="fas fa-inbox" style="font-size:28px;opacity:.2;display:block;margin-bottom:8px"></i>
            No subscriptions yet.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($subs as $i => $sub):
            [$pc, $pt] = match($sub['status']) {
                'active'  => ['pill-green', 'Active'],
                'grace'   => ['pill-amber', 'Grace'],
                'expired' => ['pill-gray',  'Expired'],
                'pending' => ['pill-blue',  'Pending'],
                default   => ['pill-gray',  ucfirst($sub['status'])],
            };
        ?>
        <tr>
            <td style="font-size:11px;color:var(--muted)"><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($sub['business_name'] ?? '—') ?></strong><br>
                <span style="font-size:10px;color:var(--muted)">ID <?= (int)$sub['provider_id'] ?></span></td>
            <td><?= htmlspecialchars($sub['plan_name'] ?? 'Pro Plan') ?></td>
            <td style="text-transform:capitalize"><?= escape($sub['billing_cycle'] ?? '—') ?></td>
            <td style="font-weight:700;font-variant-numeric:tabular-nums">&#8369;<?= number_format((float)$sub['amount'], 2) ?></td>
            <td><span class="pill <?= $pc ?>"><?= $pt ?></span></td>
            <td style="font-size:11px;color:var(--muted)"><?= $sub['expires_at'] ? date('M j, Y', strtotime($sub['expires_at'])) : '—' ?></td>
            <td style="font-size:11px;color:var(--muted)"><?= date('M j, Y', strtotime($sub['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Manually activate this subscription?')">
                    <input type="hidden" name="activate_sub" value="1">
                    <input type="hidden" name="sub_id" value="<?= (int)$sub['id'] ?>">
                    <input type="hidden" name="cycle" value="<?= escape($sub['billing_cycle'] ?? 'monthly') ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="btn-sm btn-activate" title="Activate"><i class="fas fa-check"></i></button>
                </form>
                <form method="POST" style="display:inline;margin-left:4px" onsubmit="return confirm('Expire this subscription now?')">
                    <input type="hidden" name="expire_sub" value="1">
                    <input type="hidden" name="sub_id" value="<?= (int)$sub['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="btn-sm btn-expire" title="Expire"><i class="fas fa-ban"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</div>

<?php include 'includes/admin-footer.php'; ?>
