<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$can_crm = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');
if (!$can_crm) { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';

// Gate write actions for free tier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $portal_role === 'owner' && $tier_is_paid) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_status') {
        $sid = (int)($_POST['service_id'] ?? 0);
        $cur = $_POST['current_status'] ?? 'active';
        $new = $cur === 'active' ? 'inactive' : 'active';
        $db->prepare("UPDATE services SET status=:s WHERE id=:id AND provider_id=:p")->execute([':s'=>$new,':id'=>$sid,':p'=>$pid]);
        $success = "Service status updated.";
    }
}

$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$where  = "provider_id=:p";
$params = [':p'=>$pid];
if ($status_filter) { $where .= " AND status=:s"; $params[':s']=$status_filter; }
if ($search) { $where .= " AND (s.service_name LIKE :q OR sc.name LIKE :q)"; $params[':q']="%$search%"; }

$services = safeAll($db,"SELECT s.*, sc.name AS category,
    (SELECT COUNT(*) FROM availed_services a WHERE a.service_id=s.id AND a.status='completed') AS total_completed,
    (SELECT COUNT(*) FROM availed_services a WHERE a.service_id=s.id AND a.status='pending') AS total_pending,
    (SELECT COALESCE(SUM(a.total_amount),0) FROM availed_services a WHERE a.service_id=s.id AND a.status='completed') AS total_revenue
    FROM services s LEFT JOIN service_categories sc ON sc.id = s.category_id WHERE $where ORDER BY s.status='active' DESC, s.created_at DESC",$params);

$active_count   = safeCount($db,"SELECT COUNT(*) FROM services WHERE provider_id=:p AND status='active'",[':p'=>$pid]);
$inactive_count = safeCount($db,"SELECT COUNT(*) FROM services WHERE provider_id=:p AND status='inactive'",[':p'=>$pid]);

$active_menu = 'crm_services';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Services · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#1abc9c;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px;max-width:400px}
.stat-card{background:#fff;padding:16px 18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;color:#fff}
.si-teal{background:linear-gradient(135deg,#1abc9c,#16a085)}
.si-gray{background:linear-gradient(135deg,#95a5a6,#7f8c8d)}
.stat-info h3{font-size:20px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
.filters input,.filters select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff}
.filters input{flex:1;min-width:180px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
/* Service cards grid */
.services-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.service-card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;transition:all .2s}
.service-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.1)}
.service-card.inactive{opacity:.65}
.sc-header{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.sc-name{font-size:15px;font-weight:700;color:var(--dark);line-height:1.3}
.sc-category{font-size:11px;color:var(--muted);margin-top:3px}
.sc-body{padding:14px 18px}
.sc-desc{font-size:12px;color:var(--muted);margin-bottom:12px;line-height:1.5;max-height:36px;overflow:hidden}
.sc-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px}
.sc-stat{text-align:center;padding:8px;background:#f8fafc;border-radius:8px}
.sc-stat .val{font-size:15px;font-weight:700;color:var(--dark)}
.sc-stat .lbl{font-size:10px;color:var(--muted);margin-top:2px}
.sc-footer{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-top:1px solid var(--border);background:#fafbfc}
.price-tag{font-size:17px;font-weight:800;color:var(--primary)}
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-teal{background:#d1fae5;color:#065f46}
.pill-gray{background:#f1f5f9;color:#475569}
.empty-state{text-align:center;padding:60px;color:var(--muted)}
.empty-state i{font-size:44px;opacity:.2;display:block;margin-bottom:14px}
/* pesticide badge */
.pest-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#fef3c7;color:#92400e;border-radius:8px;font-size:11px;font-weight:600;margin-top:6px}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

<?php if (!$tier_is_paid): ?>
<div style="background:#eef2ff;border:1.5px solid #c7d2fe;border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#3730a3">
    <i class="fas fa-lock" style="flex-shrink:0;font-size:16px"></i>
    <div><strong>Free Tier — View Only.</strong> Creating, editing, and deleting services requires a Pro subscription. <a href="subscriptions.php" style="color:#4f46e5;font-weight:700">Upgrade to Pro &rarr;</a></div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-briefcase"></i> My Services</h1>
        <p>View and manage all services offered by <?= htmlspecialchars($portal_company) ?></p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-teal"><i class="fas fa-circle-check"></i></div><div class="stat-info"><h3><?= $active_count ?></h3><p>Active Services</p></div></div>
    <div class="stat-card"><div class="stat-icon si-gray"><i class="fas fa-circle-pause"></i></div><div class="stat-info"><h3><?= $inactive_count ?></h3><p>Inactive</p></div></div>
</div>

<form method="GET" class="filters">
    <input type="text" name="search" placeholder="Search services or category…" value="<?= htmlspecialchars($search) ?>">
    <select name="status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="active"   <?= $status_filter==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status_filter==='inactive'?'selected':'' ?>>Inactive</option>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
    <a href="crm-services.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
</form>

<?php if (empty($services)): ?>
<div class="empty-state"><i class="fas fa-briefcase"></i><p>No services found.</p></div>
<?php else: ?>
<div class="services-grid">
<?php foreach ($services as $s): ?>
<div class="service-card <?= $s['status']==='inactive'?'inactive':'' ?>">
    <div class="sc-header">
        <div>
            <div class="sc-name"><?= htmlspecialchars($s['service_name']) ?></div>
            <div class="sc-category"><?= htmlspecialchars($s['category']??'General') ?></div>
            <?php if ($s['pesticide_name']): ?>
            <div class="pest-badge"><i class="fas fa-flask"></i><?= htmlspecialchars($s['pesticide_name']) ?></div>
            <?php endif; ?>
        </div>
        <span class="pill <?= $s['status']==='active'?'pill-teal':'pill-gray' ?>"><?= ucfirst($s['status']) ?></span>
    </div>
    <div class="sc-body">
        <?php if ($s['description']): ?>
        <div class="sc-desc"><?= htmlspecialchars($s['description']) ?></div>
        <?php endif; ?>
        <div class="sc-stats">
            <div class="sc-stat"><div class="val"><?= $s['total_completed'] ?></div><div class="lbl">Completed</div></div>
            <div class="sc-stat"><div class="val"><?= $s['total_pending'] ?></div><div class="lbl">Pending</div></div>
            <div class="sc-stat"><div class="val">₱<?= number_format($s['total_revenue'],0) ?></div><div class="lbl">Revenue</div></div>
        </div>
        <?php if ($s['duration']): ?>
        <div style="font-size:12px;color:var(--muted)"><i class="fas fa-clock"></i> <?= $s['duration'] ?> min estimated duration</div>
        <?php endif; ?>
    </div>
    <div class="sc-footer">
        <div class="price-tag">₱<?= number_format($s['price'],0) ?></div>
        <?php if ($portal_role === 'owner'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
            <input type="hidden" name="current_status" value="<?= $s['status'] ?>">
            <button type="submit" class="btn btn-sm <?= $s['status']==='active'?'btn-outline':'btn-primary' ?>">
                <i class="fas <?= $s['status']==='active'?'fa-pause':'fa-play' ?>"></i>
                <?= $s['status']==='active'?'Deactivate':'Activate' ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div></div></div>
</body></html>