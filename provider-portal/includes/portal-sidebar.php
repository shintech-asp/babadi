<?php
// provider-portal/includes/portal-sidebar.php
// Self-load tier data so callers never need to remember to include portal-tier.php
require_once __DIR__ . '/portal-tier.php';

$role    = $_SESSION['portal_role']      ?? 'hr';
$dept    = $_SESSION['portal_dept']      ?? 'hr';
$company = $_SESSION['portal_company']   ?? 'Provider';
$name    = $_SESSION['portal_full_name'] ?? 'Staff';
$active  = $active_menu ?? '';

// Tier — portal-tier.php must have been included before sidebar
$tier    = $provider_tier ?? 'free';
$is_paid = $tier_is_paid  ?? false;
$t_exp   = $tier_expires  ?? null;
$t_grace = $tier_grace    ?? null;

$must_change = !empty($_SESSION['portal_must_change']);

$is_owner    = ($role === 'owner');
$can_hr      = $is_owner || in_array($dept, ['hr',      'all']);
$can_finance = $is_owner || in_array($dept, ['finance', 'all']);
$can_crm     = $is_owner || in_array($dept, ['crm',     'all']);

$grace_days = 0;
if ($tier === 'grace' && $t_grace) {
    $secs_left  = strtotime($t_grace) - time();
    // L2: show at least "1d" while still in grace; "0d" is confusing when access is still active
    $grace_days = max(1, (int)ceil($secs_left / 86400));
}

// Unread messages count
$_msg_count = 0;
if (isset($db, $portal_provider_id)) {
    try {
        $__pu = $db->prepare("SELECT user_id FROM providers WHERE id=:p");
        $__pu->execute([':p' => (int)$portal_provider_id]);
        $__pr = $__pu->fetch(PDO::FETCH_ASSOC);
        if ($__pr) {
            $__mq = $db->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=:u AND is_read=0");
            $__mq->execute([':u' => $__pr['user_id']]);
            $_msg_count = (int)$__mq->fetchColumn();
        }
    } catch (Exception $e) {}
}
?>
<style>
:root{--pro:#6366f1;--pro2:#4f46e5;--green:#2E8B57;--green2:#27ae60}
.portal-sidebar{width:250px;background:linear-gradient(160deg,#1a2744 0%,#2d3561 100%);color:#fff;position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column;box-shadow:3px 0 15px rgba(0,0,0,.15)}
.sb-brand{padding:16px;border-bottom:1px solid rgba(255,255,255,.1)}
.sb-brand-wrap{display:flex;align-items:center;gap:10px}
.sb-brand-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--green),var(--green2));border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.sb-brand h2{font-size:14px;font-weight:800;color:#fff;margin:0;line-height:1.2}
.sb-brand p{font-size:9px;color:rgba(255,255,255,.55);margin:0}
.tier-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-top:6px}
.tier-pill.free{background:rgba(255,255,255,.1);color:rgba(255,255,255,.55)}
.tier-pill.paid{background:linear-gradient(135deg,var(--pro),var(--pro2));color:#fff;box-shadow:0 2px 8px rgba(99,102,241,.4)}
.tier-pill.grace{background:rgba(245,158,11,.2);color:#fbbf24}
.sb-company{padding:8px 14px;background:rgba(46,139,87,.15);border-bottom:1px solid rgba(255,255,255,.07);font-size:10px;color:rgba(255,255,255,.6)}
.sb-company strong{color:#fff;font-size:11px;display:block}
.sb-nav{flex:1;padding:8px 0;overflow-y:auto}
.sb-section{padding:7px 14px 3px;font-size:8px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:1.5px;margin-top:4px}
.sb-item{display:flex;align-items:center;gap:10px;padding:10px 17px;color:rgba(255,255,255,.8);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .15s;border-left:2px solid transparent}
.sb-item:hover{background:rgba(255,255,255,.08);color:#fff}
.sb-item.active{background:rgba(255,255,255,.13);color:#fff;border-left-color:var(--green2)}
.sb-item.upgrade-cta{background:rgba(99,102,241,.15);color:#a5b4fc;border-left-color:var(--pro)}
.sb-item.grace-cta{background:rgba(245,158,11,.15);color:#fbbf24;border-left-color:#f59e0b}
.sb-item i{width:15px;text-align:center;font-size:12px;flex-shrink:0}
.sb-item.locked{opacity:.42;cursor:default;pointer-events:none}
.pro-chip{margin-left:auto;background:rgba(99,102,241,.3);color:#a5b4fc;font-size:8px;font-weight:800;padding:2px 6px;border-radius:999px;letter-spacing:.3px;flex-shrink:0}
.free-chip{margin-left:auto;background:rgba(39,174,96,.2);color:#6ee7b7;font-size:8px;font-weight:700;padding:2px 6px;border-radius:999px;flex-shrink:0}
.msg-badge{margin-left:auto;background:#e74c3c;color:#fff;font-size:9px;padding:1px 6px;border-radius:999px;font-weight:700;flex-shrink:0}
.sb-footer{padding:12px;border-top:1px solid rgba(255,255,255,.08)}
.sb-user{display:flex;align-items:center;gap:8px;padding:8px;background:rgba(255,255,255,.07);border-radius:8px}
.sb-avatar{width:30px;height:30px;background:linear-gradient(135deg,var(--green),var(--green2));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0}
.sb-user-info h4{font-size:11px;color:#fff;font-weight:700;margin:0}
.sb-user-info p{font-size:9px;color:rgba(255,255,255,.5);margin:0;text-transform:capitalize}
.portal-main{margin-left:250px;min-height:100vh}
.portal-back-top{position:fixed;top:14px;right:20px;z-index:250;display:inline-flex;align-items:center;gap:7px;background:#fff;color:#1f2937;text-decoration:none;padding:9px 14px;border-radius:10px;font-size:12px;font-weight:700;border:1px solid #e2e8f0;box-shadow:0 8px 22px rgba(2,6,23,.14);transition:all .2s}
.portal-back-top:hover{transform:translateY(-1px)}
@media(max-width:768px){.portal-sidebar{display:none}.portal-main{margin-left:0}.portal-back-top{display:none}}
</style>

<?php if ($is_owner): ?>
<a href="<?= appUrl('providers-dashboard.php') ?>" class="portal-back-top"><i class="fas fa-arrow-left"></i> Back to Provider</a>
<?php endif; ?>

<aside class="portal-sidebar">
<div class="sb-brand">
    <div class="sb-brand-wrap">
        <div class="sb-brand-icon"><i class="fas fa-bug"></i></div>
        <div><h2>Pestify</h2><p>Provider Portal</p></div>
    </div>
    <?php
    if ($tier === 'paid')       echo '<div class="tier-pill paid"><i class="fas fa-star"></i> Pro</div>';
    elseif ($tier === 'grace')  echo '<div class="tier-pill grace"><i class="fas fa-clock"></i> Grace &middot; ' . $grace_days . 'd left</div>';
    else                        echo '<div class="tier-pill free">Free</div>';
    ?>
</div>
<div class="sb-company">
    <div style="font-size:8px;opacity:.6;margin-bottom:1px">Company</div>
    <strong><?= htmlspecialchars($company) ?></strong>
</div>

<nav class="sb-nav">
<?php if ($must_change): ?>
    <div class="sb-section">Action Required</div>
    <div class="sb-item" style="color:rgba(255,200,100,.9);font-size:11px;line-height:1.4;cursor:default;white-space:normal;pointer-events:none">
        <i class="fas fa-exclamation-triangle" style="color:#f6ad55;flex-shrink:0"></i>
        Set a new password to access the portal.
    </div>
<?php else: ?>

    <?php if ($tier === 'grace'): ?>
    <a href="subscriptions.php" class="sb-item grace-cta"><i class="fas fa-triangle-exclamation"></i> Renew &mdash; <?= $grace_days ?>d left</a>
    <?php endif; ?>

    <div class="sb-section">Overview</div>
    <a href="dashboard.php" class="sb-item <?= $active === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>

    <?php if ($can_hr): ?>
    <div class="sb-section">HR Department</div>
    <a href="employees.php"      class="sb-item <?= $active === 'employees'  ? 'active' : '' ?>"><i class="fas fa-id-badge"></i> Employees</a>
    <a href="leave-requests.php" class="sb-item <?= $active === 'leaves'     ? 'active' : '' ?>">
        <i class="fas fa-file-alt"></i> Leave Requests <?php if (!$is_paid) echo '<span class="free-chip">Basic</span>'; ?>
    </a>
    <?php if ($is_paid): ?>
    <a href="hr-dashboard.php"   class="sb-item <?= $active === 'hr_dash'    ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> HR Dashboard</a>
    <a href="attendance.php"     class="sb-item <?= $active === 'attendance' ? 'active' : '' ?>"><i class="fas fa-user-clock"></i> Attendance</a>
    <a href="timekeeping.php"    class="sb-item <?= $active === 'timekeeping'? 'active' : '' ?>"><i class="fas fa-qrcode"></i> Timekeeping</a>
    <a href="payroll.php"        class="sb-item <?= $active === 'payroll'    ? 'active' : '' ?>"><i class="fas fa-money-check-alt"></i> Payroll</a>
    <a href="recruitment.php"    class="sb-item <?= $active === 'recruitment'? 'active' : '' ?>"><i class="fas fa-user-plus"></i> Recruitment</a>
    <?php else: ?>
    <div class="sb-item locked"><i class="fas fa-user-clock"></i> Attendance <span class="pro-chip">PRO</span></div>
    <div class="sb-item locked"><i class="fas fa-qrcode"></i> Timekeeping <span class="pro-chip">PRO</span></div>
    <div class="sb-item locked"><i class="fas fa-money-check-alt"></i> Payroll <span class="pro-chip">PRO</span></div>
    <div class="sb-item locked"><i class="fas fa-user-plus"></i> Recruitment <span class="pro-chip">PRO</span></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($can_finance): ?>
    <div class="sb-section">Finance Department</div>
    <a href="income.php" class="sb-item <?= $active === 'income' ? 'active' : '' ?>">
        <i class="fas fa-arrow-circle-up"></i> Income <?php if (!$is_paid) echo '<span class="free-chip">View</span>'; ?>
    </a>
    <?php if ($is_paid): ?>
    <a href="finance-dashboard.php" class="sb-item <?= $active === 'fin_dash'  ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Finance Dashboard</a>
    <a href="expenses.php"          class="sb-item <?= $active === 'expenses'   ? 'active' : '' ?>"><i class="fas fa-arrow-circle-down"></i> Expenses</a>
    <a href="budget-requests.php"   class="sb-item <?= $active === 'budget'     ? 'active' : '' ?>"><i class="fas fa-hand-holding-usd"></i> Budget Requests</a>
    <?php else: ?>
    <div class="sb-item locked"><i class="fas fa-chart-line"></i> Finance Dashboard <span class="pro-chip">PRO</span></div>
    <div class="sb-item locked"><i class="fas fa-arrow-circle-down"></i> Expenses <span class="pro-chip">PRO</span></div>
    <div class="sb-item locked"><i class="fas fa-hand-holding-usd"></i> Budget Requests <span class="pro-chip">PRO</span></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($can_crm): ?>
    <div class="sb-section">CRM / Operations</div>
    <a href="crm-bookings.php" class="sb-item <?= $active === 'crm_bookings' ? 'active' : '' ?>"><i class="fas fa-calendar-check"></i> Bookings</a>
    <a href="crm-requests.php" class="sb-item <?= $active === 'crm_requests' ? 'active' : '' ?>"><i class="fas fa-box-open"></i> Requests</a>
    <a href="crm-services.php" class="sb-item <?= $active === 'crm_services' ? 'active' : '' ?>">
        <i class="fas fa-briefcase"></i> Services <?php if (!$is_paid) echo '<span class="free-chip">View</span>'; ?>
    </a>
    <?php if ($is_paid): ?>
    <a href="crm-dashboard.php" class="sb-item <?= $active === 'crm_dash'   ? 'active' : '' ?>"><i class="fas fa-headset"></i> CRM Dashboard</a>
    <a href="schedules.php"     class="sb-item <?= $active === 'schedules'   ? 'active' : '' ?>"><i class="fas fa-calendar-alt"></i> Schedules</a>
    <?php else: ?>
    <div class="sb-item locked"><i class="fas fa-headset"></i> CRM Dashboard <span class="pro-chip">PRO</span></div>
    <div class="sb-item locked"><i class="fas fa-calendar-alt"></i> Schedules <span class="pro-chip">PRO</span></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($is_owner): ?>
    <div class="sb-section">Management</div>
    <a href="staff.php"     class="sb-item <?= $active === 'staff'    ? 'active' : '' ?>"><i class="fas fa-user-shield"></i> Manage Staff</a>
    <?php if ($is_paid): ?>
    <a href="archive.php"   class="sb-item <?= $active === 'archive'  ? 'active' : '' ?>"><i class="fas fa-box-archive"></i> Archive</a>
    <?php else: ?>
    <div class="sb-item locked"><i class="fas fa-box-archive"></i> Archive <span class="pro-chip">PRO</span></div>
    <?php endif; ?>
    <a href="settings.php"  class="sb-item <?= $active === 'settings' ? 'active' : '' ?>"><i class="fas fa-sliders"></i> Settings</a>
    <?php if ($tier === 'free'): ?>
    <a href="subscriptions.php" class="sb-item upgrade-cta <?= $active === 'subscriptions' ? 'active' : '' ?>">
        <i class="fas fa-star"></i> Upgrade to Pro
    </a>
    <?php else: ?>
    <a href="subscriptions.php" class="sb-item <?= $active === 'subscriptions' ? 'active' : '' ?>">
        <i class="fas fa-credit-card"></i> Subscription
        <?php
        // Expiry warning badge
        if ($t_exp && isset($portal_provider_id)) {
            $days = (int)ceil((strtotime($t_exp) - time()) / 86400);
            if ($days <= 7 && $days > 0) echo '<span style="margin-left:auto;background:#e74c3c;color:#fff;font-size:9px;padding:1px 6px;border-radius:999px;font-weight:700">!</span>';
        }
        ?>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <div class="sb-section">Account</div>
    <?php if ($can_hr || $can_finance || $can_crm || $is_owner): ?>
    <a href="portal-messages.php" class="sb-item <?= $active === 'messages' ? 'active' : '' ?>">
        <i class="fas fa-comment-dots"></i> Messages
        <?php if ($_msg_count > 0) echo '<span class="msg-badge">' . $_msg_count . '</span>'; ?>
    </a>
    <?php endif; ?>
    <a href="change-password.php" class="sb-item <?= $active === 'password' ? 'active' : '' ?>"><i class="fas fa-key"></i> Change Password</a>
    <a href="logout.php" class="sb-item"><i class="fas fa-sign-out-alt"></i> Logout</a>

<?php endif; // !must_change ?>
</nav>

<div class="sb-footer">
    <div class="sb-user">
        <div class="sb-avatar"><?= strtoupper(substr($name, 0, 1)) ?></div>
        <div class="sb-user-info">
            <h4><?= htmlspecialchars($name) ?></h4>
            <p><?= htmlspecialchars($role) ?> &middot; <?= htmlspecialchars($dept) ?></p>
        </div>
    </div>
</div>
</aside>
