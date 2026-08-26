<?php
// admin/includes/admin-sidebar.php
// Include AFTER config.php is loaded (requires appUrl()).
$role      = (string)($_SESSION['admin_role']      ?? 'admin');
$full_name = (string)($_SESSION['admin_full_name'] ?? 'Admin');

$can_super      = ($role === 'super_admin');
$can_management = ($role === 'admin');
$can_hr         = ($role === 'hr');
$can_finance    = ($role === 'finance');
$active_menu    = $active_menu ?? '';

// Consume flash error
$_sb_error = '';
if (!empty($_SESSION['admin_error'])) {
    $_sb_error = (string)$_SESSION['admin_error'];
    unset($_SESSION['admin_error']);
}

$logout_url = appUrl('logout.php');
?>
<style>
.admin-sidebar{width:260px;background:linear-gradient(160deg,#1a2744 0%,#2d3561 100%);color:#fff;position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column;box-shadow:3px 0 15px rgba(0,0,0,.15)}
.sb-brand{padding:22px 20px 16px;border-bottom:1px solid rgba(255,255,255,.12);display:flex;align-items:center;gap:12px}
.sb-brand-icon{width:40px;height:40px;background:linear-gradient(135deg,#2E8B57,#27ae60);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.sb-brand h2{font-size:18px;font-weight:700;color:#fff;margin:0}
.sb-brand p{font-size:11px;color:rgba(255,255,255,.6);margin:0}
.sb-nav{flex:1;padding:12px 0;overflow-y:auto}
.sb-section{padding:8px 16px 4px;font-size:10px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
.sb-item{display:flex;align-items:center;gap:11px;padding:11px 20px;color:rgba(255,255,255,.82);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .2s;border-left:3px solid transparent}
.sb-item:hover{background:rgba(255,255,255,.1);color:#fff}
.sb-item.active{background:rgba(255,255,255,.15);color:#fff;border-left-color:#fff}
.sb-item i{width:18px;text-align:center;font-size:14px;flex-shrink:0}
.sb-item .badge{margin-left:auto;background:#e74c3c;color:#fff;font-size:10px;padding:2px 7px;border-radius:999px;font-weight:700}
.sb-dept-label{display:inline-flex;align-items:center;gap:5px;font-size:10px;padding:2px 8px;border-radius:999px;margin-left:auto;font-weight:700}
.sb-dept-hr{background:rgba(155,89,182,.3);color:#c39bd3}
.sb-dept-fin{background:rgba(39,174,96,.3);color:#82e0aa}
.sb-dept-mgmt{background:rgba(52,152,219,.3);color:#85c1e9}
.sb-footer{padding:16px;border-top:1px solid rgba(255,255,255,.1)}
.sb-user{display:flex;align-items:center;gap:10px;padding:10px;background:rgba(255,255,255,.08);border-radius:10px}
.sb-avatar{width:36px;height:36px;background:linear-gradient(135deg,#2E8B57,#27ae60);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0}
.sb-user-info h4{font-size:12px;color:#fff;font-weight:600;margin:0}
.sb-user-info p{font-size:10px;color:rgba(255,255,255,.6);margin:0;text-transform:capitalize}
.sb-flash-error{margin:10px 12px 0;padding:8px 12px;background:rgba(231,76,60,.25);border:1px solid rgba(231,76,60,.4);border-radius:8px;font-size:12px;color:#f1948a;line-height:1.4}
.main-content{margin-left:260px;min-height:100vh}
@media(max-width:1024px){.admin-sidebar{width:220px}.main-content{margin-left:220px}}
@media(max-width:768px){.admin-sidebar{display:none}.main-content{margin-left:0}}
</style>

<aside class="admin-sidebar">
    <div class="sb-brand">
        <div class="sb-brand-icon"><i class="fas fa-bug"></i></div>
        <div>
            <h2>Pestify</h2>
            <p>Admin Portal</p>
        </div>
    </div>

    <?php if ($_sb_error): ?>
    <div class="sb-flash-error"><i class="fas fa-lock"></i> <?= htmlspecialchars($_sb_error) ?></div>
    <?php endif; ?>

    <nav class="sb-nav">
        <!-- Super Admin -->
        <?php if ($can_super): ?>
        <div class="sb-section">Platform</div>
        <a href="<?= appUrl('super-admin-dashboard.php') ?>" class="sb-item <?= $active_menu==='super_dash'   ?'active':'' ?>"><i class="fas fa-shield-alt"></i> Overview</a>
        <a href="<?= appUrl('verify-providers.php') ?>"      class="sb-item <?= $active_menu==='verify'       ?'active':'' ?>"><i class="fas fa-user-check"></i> Verify Providers</a>
        <a href="<?= appUrl('user-management.php') ?>"       class="sb-item <?= $active_menu==='user_mgmt'    ?'active':'' ?>"><i class="fas fa-user-shield"></i> Admin Users</a>
        <a href="<?= appUrl('subscription-plans.php') ?>"  class="sb-item <?= $active_menu==='sub_plans'    ?'active':'' ?>"><i class="fas fa-star"></i> Subscription Plans</a>
        <?php endif; ?>

        <!-- Management -->
        <?php if ($can_management): ?>
        <div class="sb-section">Management</div>
        <a href="<?= appUrl('dashboard.php') ?>"         class="sb-item <?= $active_menu==='dashboard'   ?'active':'' ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a href="<?= appUrl('users.php') ?>"             class="sb-item <?= $active_menu==='users'       ?'active':'' ?>"><i class="fas fa-users"></i> Users</a>
        <a href="<?= appUrl('providers.php') ?>"         class="sb-item <?= $active_menu==='providers'   ?'active':'' ?>"><i class="fas fa-building"></i> Providers</a>
        <a href="<?= appUrl('services.php') ?>"          class="sb-item <?= $active_menu==='services'    ?'active':'' ?>"><i class="fas fa-bug"></i> Services</a>
        <a href="<?= appUrl('service-requests.php') ?>"  class="sb-item <?= $active_menu==='requests'    ?'active':'' ?>"><i class="fas fa-calendar-check"></i> Requests</a>
        <a href="<?= appUrl('schedules.php') ?>"         class="sb-item <?= $active_menu==='schedules'   ?'active':'' ?>"><i class="fas fa-calendar-alt"></i> Schedules</a>
        <div class="sb-section">System</div>
        <a href="<?= appUrl('settings.php') ?>"          class="sb-item <?= $active_menu==='settings'    ?'active':'' ?>"><i class="fas fa-cog"></i> Settings</a>
        <?php endif; ?>

        <!-- HR Department -->
        <?php if ($can_hr): ?>
        <div class="sb-section">HR Department</div>
        <a href="<?= appUrl('hr/dashboard.php') ?>"    class="sb-item <?= $active_menu==='hr_dash'     ?'active':'' ?>"><i class="fas fa-chart-pie"></i> HR Dashboard <span class="sb-dept-label sb-dept-hr">HR</span></a>
        <a href="<?= appUrl('hr/employees.php') ?>"    class="sb-item <?= $active_menu==='employees'   ?'active':'' ?>"><i class="fas fa-id-badge"></i> Employees</a>
        <a href="<?= appUrl('hr/attendance.php') ?>"   class="sb-item <?= $active_menu==='attendance'  ?'active':'' ?>"><i class="fas fa-user-clock"></i> Attendance</a>
        <a href="<?= appUrl('hr/timekeeping.php') ?>"  class="sb-item <?= $active_menu==='hr_time'     ?'active':'' ?>"><i class="fas fa-qrcode"></i> Timekeeping</a>
        <a href="<?= appUrl('hr/payroll.php') ?>"      class="sb-item <?= $active_menu==='payroll'     ?'active':'' ?>"><i class="fas fa-money-check-alt"></i> Payroll</a>
        <a href="<?= appUrl('hr/recruitment.php') ?>"  class="sb-item <?= $active_menu==='recruitment' ?'active':'' ?>"><i class="fas fa-user-plus"></i> Recruitment</a>
        <a href="<?= appUrl('hr/requests.php') ?>"     class="sb-item <?= $active_menu==='hr_requests' ?'active':'' ?>"><i class="fas fa-file-alt"></i> HR Requests</a>
        <?php endif; ?>

        <!-- Finance Department -->
        <?php if ($can_finance): ?>
        <div class="sb-section">Finance Department</div>
        <a href="<?= appUrl('finance/dashboard.php') ?>"   class="sb-item <?= $active_menu==='fin_dash'    ?'active':'' ?>"><i class="fas fa-chart-line"></i> Finance Dashboard <span class="sb-dept-label sb-dept-fin">Finance</span></a>
        <a href="<?= appUrl('finance/income.php') ?>"      class="sb-item <?= $active_menu==='income'      ?'active':'' ?>"><i class="fas fa-arrow-circle-up"></i> Income / Sales</a>
        <a href="<?= appUrl('finance/expenses.php') ?>"    class="sb-item <?= $active_menu==='expenses'    ?'active':'' ?>"><i class="fas fa-arrow-circle-down"></i> Expenses</a>
        <a href="<?= appUrl('finance/requests.php') ?>"    class="sb-item <?= $active_menu==='fin_requests'?'active':'' ?>"><i class="fas fa-hand-holding-usd"></i> Budget Requests</a>
        <a href="<?= appUrl('finance/timekeeping.php') ?>" class="sb-item <?= $active_menu==='fin_time'    ?'active':'' ?>"><i class="fas fa-clock"></i> Timekeeping</a>
        <?php endif; ?>

        <a href="<?= htmlspecialchars($logout_url) ?>" class="sb-item" style="margin-top:8px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
            <div class="sb-user-info">
                <h4><?= htmlspecialchars($full_name) ?></h4>
                <p><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $role))) ?></p>
            </div>
        </div>
    </div>
</aside>
