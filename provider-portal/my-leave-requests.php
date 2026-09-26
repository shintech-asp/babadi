<?php
// provider-portal/my-leave-requests.php — employee self-service: submit + view own leave requests
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$self_emp_id = (int)($_SESSION['portal_employee_id'] ?? 0);
if (!$self_emp_id) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';
require_once 'includes/leave_balance_helper.php';

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $type  = $_POST['leave_type'] ?? '';
    $start = $_POST['start_date'] ?? '';
    $end   = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $validTypes = ['annual','sick','personal','maternity','paternity'];

    if (!in_array($type, $validTypes, true)) {
        $error = 'Please select a valid leave type.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $error = 'Please choose valid start and end dates.';
    } elseif (strtotime($end) < strtotime($start)) {
        $error = 'End date cannot be before the start date.';
    } else {
        $days = countLeaveCalendarDays($start, $end);
        $bal  = getLeaveBalance($db, $pid, $self_emp_id, $type, date('Y', strtotime($start)));
        if ($days > $bal['remaining']) {
            $error = "You only have {$bal['remaining']} $type leave day(s) left this year, but this request is for $days day(s).";
        } else {
            try {
                $db->prepare(
                    "INSERT INTO leave_requests (provider_id, employee_id, leave_type, start_date, end_date, reason, status)
                     VALUES (:p, :e, :t, :s, :en, :r, 'pending')"
                )->execute([':p'=>$pid, ':e'=>$self_emp_id, ':t'=>$type, ':s'=>$start, ':en'=>$end, ':r'=>$reason]);
                $success = 'Leave request submitted.';
            } catch (Exception $e) {
                $error = 'Could not submit request. Please try again.';
            }
        }
    }
}

$myRequests = safeAll($db,
    "SELECT * FROM leave_requests WHERE employee_id=:e AND provider_id=:p ORDER BY created_at DESC",
    [':e'=>$self_emp_id, ':p'=>$pid]
);

$myBalances = [];
foreach (['annual','sick','personal','maternity','paternity'] as $lt) {
    $myBalances[$lt] = getLeaveBalance($db, $pid, $self_emp_id, $lt, date('Y'));
}

$active_menu = 'my_leaves';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leave Requests · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{margin-bottom:22px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.alert-error{background:#fee2e2;border:1px solid rgba(239,68,68,.2);color:#991b1b}
.layout-grid{display:grid;grid-template-columns:.9fr 1.1fr;gap:20px;align-items:start}
@media (max-width:900px){.layout-grid{grid-template-columns:1fr}}
.card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;font-weight:700;color:var(--dark)}
.card-body{padding:18px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:12px;font-weight:600;color:var(--dark);margin-bottom:6px}
.form-control{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
textarea.form-control{min-height:80px;resize:vertical}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.btn-primary{background:var(--primary);color:#fff}
.history-row{padding:12px 18px;border-bottom:1px solid var(--border);font-size:12px}
.history-row:last-child{border-bottom:none}
.history-top{display:flex;justify-content:space-between;gap:8px;margin-bottom:3px}
.history-type{font-weight:700;color:var(--dark);text-transform:capitalize}
.pill{font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;text-transform:uppercase}
.pill-pending{background:#fef9c3;color:#854d0e}
.pill-approved{background:#d1fae5;color:#065f46}
.pill-rejected{background:#fee2e2;color:#991b1b}
.history-meta{color:var(--muted)}
.empty-state{padding:30px;text-align:center;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

    <div class="page-header">
        <h1><i class="fas fa-file-alt"></i> Leave Requests</h1>
        <p>Submit a leave request and track its approval status.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><i class="fas fa-umbrella-beach"></i> My Leave Balances (<?= date('Y') ?>)</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:0">
            <?php foreach ($myBalances as $lt => $bal): ?>
            <div style="padding:14px;text-align:center;border-right:1px solid var(--border);border-bottom:1px solid var(--border)">
                <div style="font-size:20px;font-weight:800;color:<?= $bal['remaining'] <= 0 ? '#991b1b' : 'var(--primary)' ?>"><?= $bal['remaining'] ?></div>
                <div style="font-size:11px;color:var(--muted);text-transform:capitalize">of <?= $bal['total'] ?> <?= $lt ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="layout-grid">
        <div class="card">
            <div class="card-header"><i class="fas fa-paper-plane"></i> New Request</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Leave Type</label>
                        <select name="leave_type" class="form-control" required>
                            <option value="">— Select —</option>
                            <option value="annual">Annual</option>
                            <option value="sick">Sick</option>
                            <option value="personal">Personal</option>
                            <option value="maternity">Maternity</option>
                            <option value="paternity">Paternity</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Reason</label>
                        <textarea name="reason" class="form-control" placeholder="Briefly explain the reason for this leave..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-clock-rotate-left"></i> My Requests</div>
            <?php if (empty($myRequests)): ?>
                <div class="empty-state">No leave requests yet.</div>
            <?php else: foreach ($myRequests as $lr): ?>
                <div class="history-row">
                    <div class="history-top">
                        <span class="history-type"><?= htmlspecialchars($lr['leave_type']) ?> Leave</span>
                        <span class="pill pill-<?= htmlspecialchars($lr['status']) ?>"><?= htmlspecialchars($lr['status']) ?></span>
                    </div>
                    <div class="history-meta">
                        <?= htmlspecialchars(date('M j', strtotime($lr['start_date']))) ?> – <?= htmlspecialchars(date('M j, Y', strtotime($lr['end_date']))) ?>
                        <?php if (!empty($lr['reason'])): ?> &middot; <?= htmlspecialchars($lr['reason']) ?><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

</div></div>
</div>
</body></html>
