<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;
// Sidebar defaults to the free tier when $tier_is_paid is unset — this page
// never loaded portal-tier.php, so a paid provider's own sidebar always
// showed Pro nav items as locked here.
require_once 'includes/portal-tier.php';

$can_crm = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');
if (!$can_crm) { header('Location: dashboard.php'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $portal_role === 'owner') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_status') {
        $sid = (int)($_POST['service_id'] ?? 0);
        $cur = $_POST['current_status'] ?? 'active';
        $new = $cur === 'active' ? 'inactive' : 'active';
        $db->prepare("UPDATE services SET status=:s WHERE id=:id AND provider_id=:p")->execute([':s'=>$new,':id'=>$sid,':p'=>$pid]);
        $success = 'Service status updated.';
    }
}

$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$where  = 'provider_id=:p';
$params = [':p'=>$pid];
if ($status_filter) { $where .= ' AND status=:s'; $params[':s']=$status_filter; }
if ($search) { $where .= ' AND (s.service_name LIKE :q OR sc.name LIKE :q)'; $params[':q']="%$search%"; }

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
<title>My Services - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap">
<style>
:root{
    --ui-primary:#0ea5e9;
    --ui-primary-dark:#0369a1;
    --ui-secondary:#10b981;
    --ui-ink:#0f172a;
    --ui-muted:#64748b;
    --ui-border:#dbe8f5;
    --ui-glow:0 20px 40px rgba(15,23,42,.08);
}
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:'Manrope',sans-serif;
    color:var(--ui-ink);
    background:
        radial-gradient(circle at 10% -10%, rgba(14,165,233,.18), transparent 35%),
        radial-gradient(circle at 95% 5%, rgba(16,185,129,.14), transparent 28%),
        linear-gradient(180deg,#f8fbff 0%,#f1f6fb 100%);
}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:34px 34px 90px}
.page-header{
    display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px;
    border-radius:18px;border:1px solid #dae7f3;box-shadow:var(--ui-glow);background:linear-gradient(180deg,#fff 0%,#fcfeff 100%);
    padding:20px 24px;
}
.page-header h1{
    font-size:28px;font-weight:700;color:#0f2948;display:flex;align-items:center;gap:8px;
    font-family:'Space Grotesk',sans-serif;letter-spacing:-.4px;
}
.page-header h1 i{color:var(--ui-primary)}
.page-header p{font-size:13px;color:var(--ui-muted);margin-top:3px}
.alert{
    padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;
    box-shadow:0 8px 22px rgba(15,23,42,.07);
}
.alert-success{background:#d1fae5;border:1px solid rgba(16,185,129,.2);color:#065f46}
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px;max-width:420px}
.stat-card{
    background:linear-gradient(180deg,#ffffff 0%,#f9fcff 100%);
    padding:16px 18px;border-radius:16px;box-shadow:var(--ui-glow);border:1px solid var(--ui-border);display:flex;align-items:center;gap:12px;
}
.stat-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff}
.si-teal{background:linear-gradient(135deg,var(--ui-primary),var(--ui-primary-dark))}
.si-gray{background:linear-gradient(135deg,#10b981,#059669)}
.stat-info h3{
    font-size:24px;font-weight:700;color:#0f2948;
    font-family:'Space Grotesk',sans-serif;letter-spacing:-.4px;
}
.stat-info p{font-size:11px;color:var(--ui-muted)}
.filters{
    display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;
    padding:14px;border-radius:14px;border:1px solid var(--ui-border);background:#fff;
}
.filters input,.filters select{
    padding:10px 12px;border:1px solid #cfe0ef;border-radius:10px;font-size:13px;font-family:inherit;background:#fbfdff;
}
.filters input{flex:1;min-width:180px}
.btn{
    display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border:none;border-radius:10px;font-size:13px;
    font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s;
}
.btn-primary{
    background:linear-gradient(135deg,var(--ui-primary) 0%,var(--ui-primary-dark) 100%);color:#fff;
    box-shadow:0 10px 18px rgba(14,165,233,.28);
}
.btn-primary:hover{
    background:linear-gradient(135deg,#0284c7 0%,#075985 100%);
    box-shadow:0 14px 26px rgba(14,165,233,.32);
    transform:translateY(-1px);
}
.btn-outline{background:#fff;color:#1e3a5f;border:1px solid #d6e3ef}
.btn-outline:hover{background:#f3f9ff;border-color:#bbd9ef}
.btn-sm{padding:6px 12px;font-size:11px;border-radius:8px}
.services-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.service-card{
    background:linear-gradient(180deg,#fff 0%,#fcfeff 100%);
    border-radius:16px;border:1px solid var(--ui-border);box-shadow:var(--ui-glow);overflow:hidden;transition:all .2s;
}
.service-card:hover{transform:translateY(-3px);box-shadow:0 24px 45px rgba(15,23,42,.12);border-color:#c5def1}
.service-card.inactive{opacity:.65}
.sc-header{padding:16px 18px;border-bottom:1px solid #e4edf5;display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.sc-name{font-size:15px;font-weight:700;color:#0f2948;line-height:1.3}
.sc-category{font-size:11px;color:var(--ui-muted);margin-top:3px}
.sc-body{padding:14px 18px}
.sc-desc{font-size:12px;color:var(--ui-muted);margin-bottom:12px;line-height:1.5;max-height:36px;overflow:hidden}
.sc-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px}
.sc-stat{text-align:center;padding:8px;background:#f8fbff;border-radius:8px;border:1px solid #e8f1f9}
.sc-stat .val{font-size:15px;font-weight:700;color:#0f2948}
.sc-stat .lbl{font-size:10px;color:var(--ui-muted);margin-top:2px}
.sc-footer{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-top:1px solid #e4edf5;background:#f9fcff}
.price-tag{
    font-size:18px;font-weight:800;
    font-family:'Space Grotesk',sans-serif;color:#0369a1;
}
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-teal{background:#e9f6ff;color:#0369a1;border:1px solid #bae6fd}
.pill-gray{background:#f1f5f9;color:#475569;border:1px solid #dbe4ef}
.empty-state{text-align:center;padding:60px;color:var(--ui-muted)}
.empty-state i{font-size:44px;opacity:.2;display:block;margin-bottom:14px}
.pest-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#fef3c7;color:#92400e;border-radius:8px;font-size:11px;font-weight:600;margin-top:6px}
@media (max-width:768px){
    .main-content{padding:20px 16px 70px}
    .page-header h1{font-size:24px}
    .stats-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

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
    <input type="text" name="search" placeholder="Search services or category..." value="<?= htmlspecialchars($search) ?>">
    <select name="status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="active"   <?= $status_filter==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status_filter==='inactive'?'selected':'' ?>>Inactive</option>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
    <a href="services.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
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
            <div class="sc-stat"><div class="val">&#8369;<?= number_format($s['total_revenue'],0) ?></div><div class="lbl">Revenue</div></div>
        </div>
        <?php if ($s['duration']): ?>
        <div style="font-size:12px;color:var(--ui-muted)"><i class="fas fa-clock"></i> <?= $s['duration'] ?> min estimated duration</div>
        <?php endif; ?>
    </div>
    <div class="sc-footer">
        <div class="price-tag">&#8369;<?= number_format($s['price'],0) ?></div>
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
