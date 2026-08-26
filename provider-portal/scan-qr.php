<?php
// provider-portal/scan-qr.php
session_start();
require_once '../config/database.php';
require_once '../provider-portal/includes/portal-auth.php';
require_once '../includes/booking_workflow_helper.php';
require_once '../config/config.php';

// Tier gate — scan-qr is Pro only
$_scan_db = (new Database())->getConnection();
$db = $_scan_db;
require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('QR Scanner'); exit; }

$pdo    = $_scan_db;
$result = null;
$token  = trim($_POST['token'] ?? $_GET['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token) {
    $result = scanQrAndStartService($pdo, $token, $_SESSION['staff_id'] ?? 0);
}

$active_menu = 'scan_qr';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR Scanner – <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--primary-dim:rgba(46,139,87,.12);--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{margin-bottom:24px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:13.5px;display:flex;align-items:center;gap:9px}
.alert-success{background:#c6f6d5;border:1px solid rgba(46,139,87,.3);color:#276749}
.alert-error{background:#fed7d7;border:1px solid rgba(229,62,62,.3);color:#c53030}
.card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,.06);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:#fafcfb}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark)}
.card-header .hicon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;flex-shrink:0}
.card-body{padding:22px}
.card-body.center{text-align:center}
.form-control{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit;color:#2d3748;background:#fafcfb}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-dim)}
.input-row{display:flex;gap:8px}
.input-row .form-control{flex:1}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .2s}
.btn-primary{background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(46,139,87,.3)}
.btn-secondary{background:#e2e8f0;color:#4a5568}
.btn-secondary:hover{background:#cbd5e0}
.btn-outline{background:#fff;border:1.5px solid var(--primary);color:var(--primary)}
.btn-outline:hover{background:var(--primary-dim)}
.hidden{display:none}
#qr-reader{max-width:480px;margin:0 auto 18px;border-radius:10px;overflow:hidden}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <h1><i class="fas fa-qrcode"></i> Scan Seeker QR Code</h1>
    <p>Ask the seeker to open their booking and show the QR. Scan it to start the service.</p>
</div>

<?php if ($result): ?>
<div class="alert alert-<?= $result['success'] ? 'success' : 'error' ?>">
    <i class="fas fa-<?= $result['success'] ? 'circle-check' : 'circle-exclamation' ?>"></i>
    <?= htmlspecialchars($result['message']) ?>
    <?php if ($result['success'] && isset($result['booking'])): ?>
        — Booking #<?= $result['booking']['id'] ?>, <?= htmlspecialchars($result['booking']['first_name'] . ' ' . $result['booking']['last_name']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="hicon" style="background:linear-gradient(135deg,#3498db,#2980b9)"><i class="fas fa-camera"></i></div>
        <h2>Camera Scanner</h2>
    </div>
    <div class="card-body center">
        <div id="qr-reader"></div>
        <button id="startBtn" class="btn btn-primary"><i class="fas fa-camera"></i> Start Camera</button>
        <button id="stopBtn" class="btn btn-secondary hidden"><i class="fas fa-stop-circle"></i> Stop</button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="hicon" style="background:linear-gradient(135deg,#9b59b6,#8e44ad)"><i class="fas fa-keyboard"></i></div>
        <h2>Manual Token Entry</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="input-row">
                <input type="text" name="token" class="form-control" placeholder="Paste QR token…" value="<?= htmlspecialchars($token) ?>">
                <button class="btn btn-outline" type="submit"><i class="fas fa-check"></i> Validate</button>
            </div>
        </form>
    </div>
</div>

</div><!-- main-content -->
</div><!-- portal-main -->
</div><!-- dashboard-layout -->

<form id="autoForm" method="POST" style="display:none">
    <input type="hidden" name="token" id="scannedToken">
</form>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let scanner = null;
document.getElementById('startBtn').onclick = function() {
    scanner = new Html5Qrcode("qr-reader");
    scanner.start({facingMode:"environment"}, {fps:10,qrbox:260}, decoded => {
        scanner.stop();
        document.getElementById('scannedToken').value = decoded;
        document.getElementById('autoForm').submit();
    }, ()=>{}).then(()=>{
        this.classList.add('hidden');
        document.getElementById('stopBtn').classList.remove('hidden');
    });
};
document.getElementById('stopBtn').onclick = function() {
    scanner?.stop().then(()=>{
        document.getElementById('startBtn').classList.remove('hidden');
        this.classList.add('hidden');
    });
};
</script>
</body></html>
