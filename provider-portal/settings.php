<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

// Owner only
if ($portal_role !== 'owner') { header('Location: dashboard.php'); exit; }

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

require_once 'includes/portal-tier.php';

function safeRow($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){return null;}}
function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

// ── Helper: get/set portal settings (stored in admin_settings as provider_{pid}_{key}) ──
function getSetting($db, $pid, $key, $default='') {
    try {
        $s = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key=:k LIMIT 1");
        $s->execute([':k' => "portal_{$pid}_{$key}"]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? $r['setting_value'] : $default;
    } catch(Exception $e){ return $default; }
}
function setSetting($db, $pid, $key, $value) {
    try {
        $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (:k,:v)
                      ON DUPLICATE KEY UPDATE setting_value=:v")
           ->execute([':k'=>"portal_{$pid}_{$key}", ':v'=>$value]);
    } catch(Exception $e){}
}

$success = $error = '';
$tab = $_GET['tab'] ?? 'company';

// C3: CSRF validation for all settings forms
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = 'Invalid request token. Please try again.';
}

// ── POST: Company Info ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($_POST['form'] ?? '') === 'company') {
    try {
        $db->prepare("UPDATE providers SET company_name=:cn, description=:desc, address=:addr, city=:city, state=:state, zip_code=:zip, business_registration_number=:brn, license_number=:lic WHERE id=:id")
           ->execute([
               ':cn'   => trim($_POST['company_name']   ?? ''),
               ':desc' => trim($_POST['description']    ?? ''),
               ':addr' => trim($_POST['address']        ?? ''),
               ':city' => trim($_POST['city']           ?? ''),
               ':state'=> trim($_POST['state']          ?? ''),
               ':zip'  => trim($_POST['zip_code']       ?? ''),
               ':brn'  => trim($_POST['brn']            ?? ''),
               ':lic'  => trim($_POST['license']        ?? ''),
               ':id'   => $pid,
           ]);
        $_SESSION['portal_company'] = trim($_POST['company_name'] ?? $portal_company);
        $success = 'Company information saved.';
    } catch(Exception $e){ $error = $e->getMessage(); }
    $tab = 'company';
}

// ── POST: HR Settings ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($_POST['form'] ?? '') === 'hr') {
    $keys = ['annual_leave_days','sick_leave_days','personal_leave_days','maternity_leave_days','paternity_leave_days','payroll_cutoff_1','payroll_cutoff_2'];
    foreach ($keys as $k) setSetting($db, $pid, $k, trim($_POST[$k] ?? ''));
    // Update default work schedule
    $sched_id = (int)($_POST['schedule_id'] ?? 0);
    if ($sched_id) {
        $days = ['mon','tue','wed','thu','fri','sat','sun'];
        $sets = []; $params = [':id'=>$sched_id,':pid'=>$pid,':name'=>trim($_POST['sched_name']??'Standard'),':grace'=>(int)($_POST['grace_period']??15)];
        foreach ($days as $d) {
            $sets[] = "{$d}_start=:{$d}s, {$d}_end=:{$d}e";
            $params[":{$d}s"] = $_POST["{$d}_start"] ?: null;
            $params[":{$d}e"] = $_POST["{$d}_end"]   ?: null;
        }
        $db->prepare("UPDATE hr_work_schedules SET name=:name, grace_period=:grace, ".implode(', ',$sets)." WHERE id=:id AND (provider_id=:pid OR provider_id IS NULL)")
           ->execute($params);
    }
    $success = 'HR settings saved.';
    $tab = 'hr';
}

// ── POST: Finance Settings ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($_POST['form'] ?? '') === 'finance') {
    $keys = ['sss_rate','philhealth_rate','pagibig_rate','pagibig_cap','overtime_rate','night_diff_rate'];
    foreach ($keys as $k) setSetting($db, $pid, $k, trim($_POST[$k] ?? ''));
    $success = 'Finance settings saved.';
    $tab = 'finance';
}

// ── POST: Office Location (geofence center) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($_POST['form'] ?? '') === 'location') {
    $lat = (float)($_POST['office_lat'] ?? 0);
    $lng = (float)($_POST['office_lng'] ?? 0);
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
        $error = 'Invalid coordinates. Please drop a pin on the map.';
    } else {
        // Server-side Cavite boundary check (mirrors registration validation)
        $cavite = [[14.0534,120.5648],[14.1022,120.6246],[14.1375,120.6842],[14.1718,120.7374],[14.2205,120.7588],[14.2769,120.7861],[14.3369,120.8190],[14.4002,120.8587],[14.4458,120.9198],[14.4799,120.9643],[14.5080,121.0142],[14.4874,121.0719],[14.4409,121.0740],[14.3838,121.0583],[14.3232,121.0329],[14.2728,121.0092],[14.2219,120.9837],[14.1718,120.9598],[14.1299,120.9361],[14.0922,120.9063],[14.0736,120.8616],[14.0598,120.7992],[14.0517,120.7308],[14.0470,120.6540],[14.0534,120.5648]];
        $inside = false; $j = count($cavite) - 1;
        for ($i = 0; $i < count($cavite); $j = $i++) {
            $yi=(float)$cavite[$i][0];$xi=(float)$cavite[$i][1];$yj=(float)$cavite[$j][0];$xj=(float)$cavite[$j][1];
            if ((($yi>$lat)!==($yj>$lat))&&($lng<(($xj-$xi)*($lat-$yi)/($yj-$yi)+$xi))) $inside=!$inside;
        }
        if (!$inside) {
            $error = 'The pinned location is outside Cavite. Only Cavite locations are allowed.';
        } else {
            try {
                $db->prepare("UPDATE providers SET office_lat=:lat, office_lng=:lng WHERE id=:id")
                   ->execute([':lat' => $lat, ':lng' => $lng, ':id' => $pid]);
                $success = 'Office location saved. Staff geofencing is now calibrated to this address.';
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
    $tab = 'location';
}

// ── POST: Notifications ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($_POST['form'] ?? '') === 'notifications') {
    $keys = ['notif_new_employee','notif_leave_request','notif_budget_request','notif_booking'];
    foreach ($keys as $k) setSetting($db, $pid, $k, isset($_POST[$k]) ? '1' : '0');
    $success = 'Notification settings saved.';
    $tab = 'notifications';
}

// ── Fetch current data ──
// NOTE: avoid $company — portal-sidebar.php assigns that name in global scope
$prov  = safeRow($db, "SELECT * FROM providers WHERE id=:id", [':id'=>$pid]);
if (!$prov && !$error) {
    $error = 'Could not load company data (session may be stale). Please log out and log back in.';
}
$schedule = safeRow($db, "SELECT * FROM hr_work_schedules WHERE (provider_id=:p OR provider_id IS NULL) AND is_default=1 ORDER BY provider_id DESC LIMIT 1", [':p'=>$pid]);

// HR settings
$leave_days = [
    'annual'    => getSetting($db,$pid,'annual_leave_days','15'),
    'sick'      => getSetting($db,$pid,'sick_leave_days','15'),
    'personal'  => getSetting($db,$pid,'personal_leave_days','5'),
    'maternity' => getSetting($db,$pid,'maternity_leave_days','105'),
    'paternity' => getSetting($db,$pid,'paternity_leave_days','7'),
];
$cutoff1 = getSetting($db,$pid,'payroll_cutoff_1','15');
$cutoff2 = getSetting($db,$pid,'payroll_cutoff_2','30');

// Finance settings
$sss_rate       = getSetting($db,$pid,'sss_rate','4.5');
$ph_rate        = getSetting($db,$pid,'philhealth_rate','2.0');
$pagibig_rate   = getSetting($db,$pid,'pagibig_rate','2.0');
$pagibig_cap    = getSetting($db,$pid,'pagibig_cap','5000');
$ot_rate        = getSetting($db,$pid,'overtime_rate','1.25');
$nd_rate        = getSetting($db,$pid,'night_diff_rate','0.10');

// Notification settings
$notif = [
    'new_employee'   => getSetting($db,$pid,'notif_new_employee','1'),
    'leave_request'  => getSetting($db,$pid,'notif_leave_request','1'),
    'budget_request' => getSetting($db,$pid,'notif_budget_request','1'),
    'booking'        => getSetting($db,$pid,'notif_booking','1'),
];

// Load office coordinates — prefer office_lat/lng, fall back to latitude/longitude set during registration
$office_coords = safeRow($db, "SELECT office_lat, office_lng, latitude, longitude FROM providers WHERE id=:id", [':id'=>$pid]);
$office_lat = $office_coords['office_lat'] ?? ($office_coords['latitude'] ?? '');
$office_lng = $office_coords['office_lng'] ?? ($office_coords['longitude'] ?? '');

$active_menu = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
<style>
:root{
    --primary:#2E8B57;--primary-dim:rgba(46,139,87,.12);
    --dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096;
    --green:#16a34a;--green-dim:rgba(22,163,74,.1);
    --red:#dc2626;--red-dim:rgba(220,38,38,.1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}

.page-header{margin-bottom:24px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}

/* Alert */
.alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:13.5px;display:flex;align-items:center;gap:9px;animation:slideIn .25s ease}
@keyframes slideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.alert-success{background:var(--green-dim);border:1px solid rgba(22,163,74,.22);color:#14532d}
.alert-error  {background:var(--red-dim);  border:1px solid rgba(220,38,38,.22);  color:#7f1d1d}

/* Tabs */
.settings-tabs{display:flex;gap:4px;margin-bottom:24px;background:#fff;padding:5px;border-radius:12px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.05);flex-wrap:wrap}
.tab-btn{padding:9px 18px;border:none;background:transparent;border-radius:9px;font-size:13px;font-weight:600;font-family:inherit;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:7px;transition:all .2s;text-decoration:none;white-space:nowrap}
.tab-btn:hover{color:var(--dark);background:#f8fafc}
.tab-btn.active{background:var(--primary);color:#fff;box-shadow:0 3px 10px rgba(46,139,87,.25)}
.tab-btn i{font-size:12px}

/* Settings card */
.settings-card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,.06);overflow:hidden;margin-bottom:20px}
.settings-card-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.settings-card-header h2{font-size:15px;font-weight:700;color:var(--dark)}
.settings-card-header p{font-size:12px;color:var(--muted);margin-top:2px}
.settings-card-header .header-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;color:#fff;flex-shrink:0}
.settings-card-body{padding:24px}

/* Form grid */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:12px;font-weight:700;color:#2d3748;text-transform:uppercase;letter-spacing:.4px}
.form-hint{font-size:11px;color:var(--muted);margin-top:2px}
.form-control{padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit;color:#2d3748;background:#fafcfb;transition:border .18s,box-shadow .18s}
.form-control:focus{outline:none;border-color:var(--primary);background:#fff;box-shadow:0 0 0 3px var(--primary-dim)}
.input-group{display:flex;align-items:center}
.input-addon{padding:10px 12px;background:#f1f5f9;border:1.5px solid var(--border);border-right:none;border-radius:9px 0 0 9px;font-size:13px;font-weight:600;color:var(--muted);white-space:nowrap}
.input-addon-right{border-right:1.5px solid var(--border);border-left:none;border-radius:0 9px 9px 0}
.input-group .form-control{border-radius:0 9px 9px 0;border-left:none}
.input-group .form-control.right{border-radius:9px 0 0 9px;border-right:none;border-left:1.5px solid var(--border)}

/* Toggle switch */
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--border)}
.toggle-row:last-child{border-bottom:none}
.toggle-label h4{font-size:13px;font-weight:600;color:var(--dark)}
.toggle-label p{font-size:12px;color:var(--muted);margin-top:2px}
.toggle-switch{position:relative;width:44px;height:24px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#e2e8f0;border-radius:999px;cursor:pointer;transition:.2s}
.toggle-slider::before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 4px rgba(0,0,0,.2)}
input:checked + .toggle-slider{background:var(--primary)}
input:checked + .toggle-slider::before{transform:translateX(20px)}

/* Schedule table */
.sched-table{width:100%;border-collapse:collapse;font-size:13px}
.sched-table th{background:#f8fafc;padding:9px 12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
.sched-table td{padding:8px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.sched-table tr:last-child td{border-bottom:none}
.sched-table .form-control{padding:7px 10px;font-size:12px}

/* Submit button */
.btn-save{display:inline-flex;align-items:center;gap:8px;padding:11px 24px;background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .2s;margin-top:6px}
.btn-save:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(46,139,87,.3)}

/* Section divider */
.section-divider{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin:20px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border)}

@media(max-width:900px){.form-grid,.form-grid-3{grid-template-columns:1fr}.form-group.full{grid-column:1}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-sliders"></i> Management Settings</h1>
    <p>Configure your portal, HR policies, finance rules, and notification preferences.</p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="settings-tabs">
    <a href="?tab=company"       class="tab-btn <?= $tab==='company'?'active':'' ?>"><i class="fas fa-building"></i> Company</a>
    <a href="?tab=location"      class="tab-btn <?= $tab==='location'?'active':'' ?>"><i class="fas fa-map-pin"></i> Office Location</a>
    <a href="?tab=hr"            class="tab-btn <?= $tab==='hr'?'active':'' ?>"><i class="fas fa-users"></i> HR & Payroll</a>
    <a href="?tab=finance"       class="tab-btn <?= $tab==='finance'?'active':'' ?>"><i class="fas fa-coins"></i> Finance</a>
    <a href="?tab=notifications" class="tab-btn <?= $tab==='notifications'?'active':'' ?>"><i class="fas fa-bell"></i> Notifications</a>
</div>

<!-- ══════════ COMPANY TAB ══════════ -->
<?php if ($tab === 'company'): ?>
<form method="POST">
<input type="hidden" name="form" value="company">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

<div class="settings-card">
    <div class="settings-card-header">
        <div class="header-icon" style="background:linear-gradient(135deg,#3498db,#2980b9)"><i class="fas fa-building"></i></div>
        <div><h2>Company Information</h2><p>Basic details about your business shown across the portal</p></div>
    </div>
    <div class="settings-card-body">
        <div class="form-grid">
            <div class="form-group full">
                <label class="form-label">Company Name *</label>
                <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($prov['company_name'] ?? '') ?>" required>
            </div>
            <div class="form-group full">
                <label class="form-label">Business Description</label>
                <textarea name="description" class="form-control" rows="3" style="resize:vertical"><?= htmlspecialchars($prov['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Business Registration No.</label>
                <input type="text" name="brn" class="form-control" value="<?= htmlspecialchars($prov['business_registration_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">License Number</label>
                <input type="text" name="license" class="form-control" value="<?= htmlspecialchars($prov['license_number'] ?? '') ?>">
            </div>
        </div>

        <div class="section-divider"><i class="fas fa-map-marker-alt"></i> Address</div>
        <div class="form-grid">
            <div class="form-group full">
                <label class="form-label">Street Address</label>
                <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($prov['address'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($prov['city'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">State / Province</label>
                <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($prov['state'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">ZIP Code</label>
                <input type="text" name="zip_code" class="form-control" value="<?= htmlspecialchars($prov['zip_code'] ?? '') ?>">
            </div>
        </div>
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Company Info</button>
    </div>
</div>
</form>

<!-- ══════════ LOCATION TAB ══════════ -->
<?php elseif ($tab === 'location'): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
#office-map{height:420px;border-radius:10px;border:1.5px solid var(--border);cursor:crosshair}
.coord-display{display:flex;gap:12px;margin-top:14px;flex-wrap:wrap}
.coord-box{flex:1;min-width:160px;background:#f8fafc;border:1.5px solid var(--border);border-radius:9px;padding:10px 14px}
.coord-box label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);display:block;margin-bottom:4px}
.coord-box span{font-size:15px;font-weight:700;color:var(--dark);font-variant-numeric:tabular-nums}
.map-hint{font-size:12.5px;color:var(--muted);margin-bottom:10px;display:flex;align-items:center;gap:7px}
</style>

<form method="POST" id="location-form">
<input type="hidden" name="form" value="location">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="office_lat" id="inp-lat" value="<?= htmlspecialchars($office_lat) ?>">
<input type="hidden" name="office_lng" id="inp-lng" value="<?= htmlspecialchars($office_lng) ?>">

<div class="settings-card">
    <div class="settings-card-header">
        <div class="header-icon" style="background:linear-gradient(135deg,#e74c3c,#c0392b)"><i class="fas fa-map-pin"></i></div>
        <div>
            <h2>Office Location &amp; Geofence</h2>
            <p>Pin your office on the map — staff must be within 10km to clock in biometrically</p>
        </div>
    </div>
    <div class="settings-card-body">

        <p class="map-hint">
            <i class="fas fa-hand-pointer" style="color:var(--primary)"></i>
            Click anywhere on the map to set or move the pin. Search your address using the search bar, then click to confirm.
        </p>

        <!-- Map -->
        <div id="office-map"></div>

        <!-- Coordinate display -->
        <div class="coord-display">
            <div class="coord-box">
                <label><i class="fas fa-arrows-up-down"></i> Latitude</label>
                <span id="disp-lat"><?= $office_lat ? htmlspecialchars($office_lat) : '—' ?></span>
            </div>
            <div class="coord-box">
                <label><i class="fas fa-arrows-left-right"></i> Longitude</label>
                <span id="disp-lng"><?= $office_lng ? htmlspecialchars($office_lng) : '—' ?></span>
            </div>
            <div class="coord-box" style="flex:2;background:#f0fdf4;border-color:#bbf7d0">
                <label><i class="fas fa-circle-info" style="color:var(--primary)"></i> Geofence Radius</label>
                <span style="color:var(--primary)">10 km around the pin</span>
            </div>
        </div>

        <!-- Geocode feedback (shown by JS) -->
        <div id="geocode-note" style="display:none;background:#eef2ff;border:1px solid #c7d2fe;border-radius:9px;padding:10px 14px;margin-top:12px;font-size:12.5px;color:#3730a3;gap:8px;align-items:flex-start">
            <i class="fas fa-circle-info" style="margin-top:2px;flex-shrink:0"></i>
            <span></span>
        </div>

        <!-- Cavite violation warning (shown by JS) -->
        <div id="cavite-warn" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:9px;padding:10px 14px;margin-top:12px;font-size:12.5px;color:#991b1b;gap:8px;align-items:center">
            <i class="fas fa-circle-xmark" style="flex-shrink:0"></i>
            That location is outside Cavite. Only offices within Cavite can be set as a geofence center.
        </div>

        <?php if (!$office_lat || !$office_lng): ?>
        <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:10px;padding:11px 15px;margin-top:14px;font-size:12px;color:#92400e">
            <i class="fas fa-triangle-exclamation"></i> No location pinned yet. Biometric clock-in is disabled until you save a location.
        </div>
        <?php endif; ?>

        <button type="submit" class="btn-save" style="margin-top:18px" id="save-location-btn" <?= (!$office_lat && !$office_lng) ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>
            <i class="fas fa-map-pin"></i> Save Location
        </button>
        <span id="pin-hint" style="font-size:12px;color:var(--muted);margin-left:10px;<?= ($office_lat || $office_lng) ? 'display:none' : '' ?>">Drop a pin first</span>
    </div>
</div>
</form>

<script>
(function() {
    var initLat = <?= $office_lat ? (float)$office_lat : 14.4391 ?>;
    var initLng = <?= $office_lng ? (float)$office_lng : 120.9768 ?>;
    var hasPin  = <?= ($office_lat && $office_lng) ? 'true' : 'false' ?>;
    var regAddr = <?= json_encode(trim(($prov['address'] ?? '') . ', ' . ($prov['city'] ?? '') . ', ' . ($prov['state'] ?? '')), JSON_UNESCAPED_UNICODE) ?>;

    // Cavite polygon for client-side validation (mirrors server-side check)
    var CAVITE = [[14.0534,120.5648],[14.1022,120.6246],[14.1375,120.6842],[14.1718,120.7374],[14.2205,120.7588],[14.2769,120.7861],[14.3369,120.8190],[14.4002,120.8587],[14.4458,120.9198],[14.4799,120.9643],[14.5080,121.0142],[14.4874,121.0719],[14.4409,121.0740],[14.3838,121.0583],[14.3232,121.0329],[14.2728,121.0092],[14.2219,120.9837],[14.1718,120.9598],[14.1299,120.9361],[14.0922,120.9063],[14.0736,120.8616],[14.0598,120.7992],[14.0517,120.7308],[14.0470,120.6540],[14.0534,120.5648]];

    function pointInCavite(lat, lng) {
        var inside = false, j = CAVITE.length - 1;
        for (var i = 0; i < CAVITE.length; j = i++) {
            var yi=CAVITE[i][0],xi=CAVITE[i][1],yj=CAVITE[j][0],xj=CAVITE[j][1];
            if (((yi>lat)!==(yj>lat))&&(lng<((xj-xi)*(lat-yi)/(yj-yi)+xi))) inside=!inside;
        }
        return inside;
    }

    var map = L.map('office-map').setView([initLat, initLng], hasPin ? 16 : 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(map);

    // Draw Cavite boundary on map
    L.polygon(CAVITE, {color:'#6366f1', weight:2, fillColor:'#6366f1', fillOpacity:0.05, dashArray:'6,4'})
     .addTo(map).bindTooltip('Cavite boundary — pin must be inside', {permanent:false});

    var marker = null, geofenceCircle = null;

    var greenIcon = L.divIcon({
        html: '<div style="width:32px;height:32px;background:#2E8B57;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.3)"></div>',
        className: '', iconSize:[32,32], iconAnchor:[16,32]
    });

    var warnEl = document.getElementById('cavite-warn');

    function placeMarker(latlng, skipValidation) {
        var inCavite = pointInCavite(latlng.lat, latlng.lng);
        if (!inCavite && !skipValidation) {
            warnEl.style.display = 'flex';
            return;
        }
        warnEl.style.display = 'none';
        if (marker) marker.setLatLng(latlng);
        else {
            marker = L.marker(latlng, {draggable:true, icon:greenIcon}).addTo(map);
            marker.on('dragend', function(e) { placeMarker(e.target.getLatLng()); });
        }
        updateCoords(latlng);
        if (geofenceCircle) map.removeLayer(geofenceCircle);
        geofenceCircle = L.circle(latlng, {radius:10000, color:'#2E8B57', fillColor:'#2E8B57', fillOpacity:0.08, weight:2}).addTo(map);
    }

    function updateCoords(latlng) {
        var lat = latlng.lat.toFixed(7), lng = latlng.lng.toFixed(7);
        document.getElementById('inp-lat').value = lat;
        document.getElementById('inp-lng').value = lng;
        document.getElementById('disp-lat').textContent = lat;
        document.getElementById('disp-lng').textContent = lng;
        var btn = document.getElementById('save-location-btn');
        btn.disabled = false; btn.style.opacity = ''; btn.style.cursor = '';
        document.getElementById('pin-hint').style.display = 'none';
    }

    // Place saved pin
    if (hasPin) placeMarker(L.latLng(initLat, initLng), true);

    // Auto-geocode registered address when no pin exists
    if (!hasPin && regAddr.replace(/,\s*/g,'').trim()) {
        var geocodeUrl = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(regAddr + ', Philippines');
        fetch(geocodeUrl, {headers:{'Accept-Language':'en'}})
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (data && data[0]) {
                    var lat = parseFloat(data[0].lat), lng = parseFloat(data[0].lon);
                    map.setView([lat, lng], 16);
                    if (pointInCavite(lat, lng)) {
                        placeMarker(L.latLng(lat, lng), false);
                        document.querySelector('#geocode-note span').textContent = 'Pin auto-placed from your registered address. Drag or click to adjust, then save.';
                        document.getElementById('geocode-note').style.display = 'flex';
                    } else {
                        document.querySelector('#geocode-note span').textContent = 'Registered address resolved outside Cavite — please click to pin your actual office location.';
                        document.getElementById('geocode-note').style.display = 'flex';
                    }
                }
            })
            .catch(function(){});
    }

    map.on('click', function(e) { placeMarker(e.latlng); });

    document.getElementById('location-form').addEventListener('submit', function(e) {
        if (!document.getElementById('inp-lat').value) {
            e.preventDefault();
            alert('Please drop a pin on the map first.');
        }
    });
})();
</script>

<!-- ══════════ HR TAB ══════════ -->
<?php elseif ($tab === 'hr'): ?>
<form method="POST">
<input type="hidden" name="form" value="hr">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<?php if ($schedule): ?>
<input type="hidden" name="schedule_id" value="<?= $schedule['id'] ?>">
<?php endif; ?>

<div class="settings-card">
    <div class="settings-card-header">
        <div class="header-icon" style="background:linear-gradient(135deg,#9b59b6,#8e44ad)"><i class="fas fa-calendar-alt"></i></div>
        <div><h2>Default Work Schedule</h2><p>Used by timekeeping to calculate late, undertime, and overtime</p></div>
    </div>
    <div class="settings-card-body">
        <div class="form-group" style="max-width:320px;margin-bottom:16px">
            <label class="form-label">Schedule Name</label>
            <input type="text" name="sched_name" class="form-control" value="<?= htmlspecialchars($schedule['name'] ?? 'Standard') ?>">
        </div>
        <div style="overflow-x:auto">
        <table class="sched-table">
            <thead><tr><th>Day</th><th>Shift Start</th><th>Shift End</th><th>Work Hours</th></tr></thead>
            <tbody>
            <?php
            $days = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'];
            foreach ($days as $key => $label):
                $start = $schedule[$key.'_start'] ?? '';
                $end   = $schedule[$key.'_end']   ?? '';
                $hours = ($start && $end) ? round((strtotime($end)-strtotime($start))/3600, 1) : '—';
            ?>
            <tr>
                <td><strong><?= $label ?></strong></td>
                <td><input type="time" name="<?= $key ?>_start" class="form-control" value="<?= $start ?>" style="width:130px"></td>
                <td><input type="time" name="<?= $key ?>_end"   class="form-control" value="<?= $end ?>"   style="width:130px"></td>
                <td style="color:var(--muted);font-weight:600"><?= is_numeric($hours) ? $hours.'h' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="form-group" style="max-width:200px;margin-top:16px">
            <label class="form-label">Grace Period (minutes)</label>
            <input type="number" name="grace_period" class="form-control" value="<?= $schedule['grace_period'] ?? 15 ?>" min="0" max="60">
            <span class="form-hint">Minutes allowed before marking as late</span>
        </div>
    </div>
</div>

<div class="settings-card">
    <div class="settings-card-header">
        <div class="header-icon" style="background:linear-gradient(135deg,#e67e22,#d35400)"><i class="fas fa-umbrella-beach"></i></div>
        <div><h2>Leave Credits Per Year</h2><p>Number of days each employee is entitled to annually</p></div>
    </div>
    <div class="settings-card-body">
        <div class="form-grid-3">
            <?php foreach (['annual'=>'Annual Leave','sick'=>'Sick Leave','personal'=>'Personal Leave','maternity'=>'Maternity Leave','paternity'=>'Paternity Leave'] as $k=>$lbl): ?>
            <div class="form-group">
                <label class="form-label"><?= $lbl ?></label>
                <div class="input-group">
                    <input type="number" name="<?= $k ?>_leave_days" class="form-control right" value="<?= htmlspecialchars($leave_days[$k]) ?>" min="0" max="365">
                    <span class="input-addon input-addon-right">days</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="settings-card">
    <div class="settings-card-header">
        <div class="header-icon" style="background:linear-gradient(135deg,#27ae60,#16a085)"><i class="fas fa-money-bill-wave"></i></div>
        <div><h2>Payroll Cut-off Dates</h2><p>Day of the month when each payroll period ends</p></div>
    </div>
    <div class="settings-card-body">
        <div class="form-grid" style="max-width:500px">
            <div class="form-group">
                <label class="form-label">1st Cut-off (Day of Month)</label>
                <div class="input-group">
                    <span class="input-addon">Day</span>
                    <input type="number" name="payroll_cutoff_1" class="form-control" value="<?= $cutoff1 ?>" min="1" max="28">
                </div>
                <span class="form-hint">e.g. 15 = every 15th of the month</span>
            </div>
            <div class="form-group">
                <label class="form-label">2nd Cut-off (Day of Month)</label>
                <div class="input-group">
                    <span class="input-addon">Day</span>
                    <input type="number" name="payroll_cutoff_2" class="form-control" value="<?= $cutoff2 ?>" min="1" max="31">
                </div>
                <span class="form-hint">e.g. 30 = end of month</span>
            </div>
        </div>
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save HR Settings</button>
    </div>
</div>
</form>

<!-- ══════════ FINANCE TAB ══════════ -->
<?php elseif ($tab === 'finance'): ?>
<form method="POST">
<input type="hidden" name="form" value="finance">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

<div class="settings-card">
    <div class="settings-card-header">
        <div class="header-icon" style="background:linear-gradient(135deg,#27ae60,#16a085)"><i class="fas fa-percent"></i></div>
        <div><h2>Government Deduction Rates</h2><p>Employee contribution rates applied during payroll computation</p></div>
    </div>
    <div class="settings-card-body">
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">SSS Employee Rate</label>
                <div class="input-group">
                    <input type="number" name="sss_rate" class="form-control right" value="<?= $sss_rate ?>" min="0" max="100" step="0.01">
                    <span class="input-addon input-addon-right">%</span>
                </div>
                <span class="form-hint">Standard: 4.5%</span>
            </div>
            <div class="form-group">
                <label class="form-label">PhilHealth Employee Rate</label>
                <div class="input-group">
                    <input type="number" name="philhealth_rate" class="form-control right" value="<?= $ph_rate ?>" min="0" max="100" step="0.01">
                    <span class="input-addon input-addon-right">%</span>
                </div>
                <span class="form-hint">Standard: 2%</span>
            </div>
            <div class="form-group">
                <label class="form-label">Pag-IBIG Rate</label>
                <div class="input-group">
                    <input type="number" name="pagibig_rate" class="form-control right" value="<?= $pagibig_rate ?>" min="0" max="100" step="0.01">
                    <span class="input-addon input-addon-right">%</span>
                </div>
                <span class="form-hint">Standard: 2%</span>
            </div>
            <div class="form-group">
                <label class="form-label">Pag-IBIG Monthly Cap</label>
                <div class="input-group">
                    <span class="input-addon">₱</span>
                    <input type="number" name="pagibig_cap" class="form-control" value="<?= $pagibig_cap ?>" min="0">
                </div>
                <span class="form-hint">Max deduction per month: ₱5,000</span>
            </div>
        </div>

        <div class="section-divider"><i class="fas fa-clock"></i> Overtime & Night Differential</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Overtime Rate Multiplier</label>
                <div class="input-group">
                    <span class="input-addon">×</span>
                    <input type="number" name="overtime_rate" class="form-control" value="<?= $ot_rate ?>" min="1" max="3" step="0.01">
                </div>
                <span class="form-hint">Standard: 1.25× regular rate</span>
            </div>
            <div class="form-group">
                <label class="form-label">Night Differential Rate</label>
                <div class="input-group">
                    <input type="number" name="night_diff_rate" class="form-control right" value="<?= $nd_rate ?>" min="0" max="1" step="0.01">
                    <span class="input-addon input-addon-right">× base</span>
                </div>
                <span class="form-hint">Standard: 10% (0.10) of hourly rate</span>
            </div>
        </div>
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Finance Settings</button>
    </div>
</div>
</form>

<!-- ══════════ NOTIFICATIONS TAB ══════════ -->
<?php elseif ($tab === 'notifications'): ?>
<form method="POST">
<input type="hidden" name="form" value="notifications">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

<div class="settings-card">
    <div class="settings-card-header">
        <div class="header-icon" style="background:linear-gradient(135deg,#e67e22,#d35400)"><i class="fas fa-bell"></i></div>
        <div><h2>Email Notifications</h2><p>Control which events trigger automatic email notifications</p></div>
    </div>
    <div class="settings-card-body">
        <?php
        $notif_items = [
            'notif_new_employee'   => ['New Employee Added',    'Send a welcome email with credentials when an employee is created',       $notif['new_employee']],
            'notif_leave_request'  => ['Leave Request Filed',   'Notify owner when an employee files a new leave request',                 $notif['leave_request']],
            'notif_budget_request' => ['Budget Request Filed',  'Notify owner when a department submits a new budget request',             $notif['budget_request']],
            'notif_booking'        => ['New Booking Received',  'Notify owner when a new service booking is placed by a client',           $notif['booking']],
        ];
        foreach ($notif_items as $key => [$title, $desc, $val]): ?>
        <div class="toggle-row">
            <div class="toggle-label">
                <h4><?= $title ?></h4>
                <p><?= $desc ?></p>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" name="<?= $key ?>" <?= $val === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn-save" style="margin-top:20px"><i class="fas fa-save"></i> Save Notification Settings</button>
    </div>
</div>
</form>
<?php endif; ?>

</div></div></div>
</body>
</html>