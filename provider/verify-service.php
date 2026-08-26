<?php
// provider-portal/verify-service.php  (or portal/verify-service.php)
// Provider side: the technician enters the SEEKER's control number (PCF-…)
// to confirm they are at the correct job site with the correct client.
$appRoot = dirname(__DIR__);
chdir($appRoot);
$portalAuthPath = $appRoot . '/provider-portal/includes/portal-auth.php';
if (!file_exists($portalAuthPath)) {
    http_response_code(500);
    exit('Missing portal auth bootstrap.');
}
require_once $portalAuthPath;
require_once $appRoot . '/config/config.php';
require_once $appRoot . '/config/database.php';
require_once $appRoot . '/includes/control_number_helper.php';

$database = new Database();
$db  = $database->getConnection();
$pid = (int)$portal_provider_id;

/* -- AJAX: POST action=verify_provider -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_provider') {
    header('Content-Type: application/json');
    $bid  = (int)($_POST['booking_id'] ?? 0);
    $code = trim($_POST['code'] ?? '');

    if (!$bid || !$code) {
        echo json_encode(['ok' => false, 'message' => 'Missing booking or control number.']);
        exit;
    }

    // Confirm booking belongs to this provider
    $own = $db->prepare(
        "SELECT id FROM availed_services WHERE id = :bid AND provider_id = :pid LIMIT 1"
    );
    $own->execute([':bid' => $bid, ':pid' => $pid]);
    if (!$own->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Booking not found or access denied.']);
        exit;
    }

    // verify_control_number with role 'provider' ? checks seeker's control_number (PCF-…)
    $result = verify_control_number(
        $db, $bid,
        $pid,         // use provider_id as the actor identifier
        'provider',
        $code,
        $_SERVER['REMOTE_ADDR'] ?? ''
    );

    echo json_encode($result);
    exit;
}

/* -- Page: fetch today's & upcoming bookings for this provider ---------- */
$bid       = (int)($_GET['booking_id'] ?? 0);
$active_bk = null;

$stmt = $db->prepare(
    "SELECT a.id, a.service_name, a.preferred_date, a.preferred_time,
            a.status, a.payment_status, a.full_name, a.contact_number, a.address,
            a.control_number, a.provider_control_number,
            a.seeker_verified_at, a.provider_verified_at, a.dual_verified_at
       FROM availed_services a
      WHERE a.provider_id = :pid
        AND a.status IN ('preparing','starting','ongoing')
        AND a.payment_status IN ('paid','partial')
        AND (a.control_number IS NOT NULL AND a.control_number <> '')
      ORDER BY a.preferred_date ASC, a.preferred_time ASC"
);
$stmt->execute([':pid' => $pid]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($bid) {
    foreach ($bookings as $b) {
        if ((int)$b['id'] === $bid) { $active_bk = $b; break; }
    }
}
if (!$active_bk && !empty($bookings)) {
    $active_bk = $bookings[0];
    $bid = (int)$active_bk['id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Service – Provider Portal</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family:'Segoe UI',sans-serif; background:#f0fdf4; }
        .page-wrap { max-width:720px; margin:0 auto; padding:3rem 1rem 5rem; }
        h2.page-title { font-size:22px; font-weight:800; color:#1a2744; margin:0 0 6px; }
        p.page-sub    { font-size:14px; color:#6b7280; margin:0 0 28px; }

        /* booking selector */
        .bk-select { margin-bottom:24px; }
        .bk-select label { font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:6px; }
        .bk-select select {
            width:100%; padding:10px 14px; border:1.5px solid #d1d5db; border-radius:10px;
            font-size:14px; color:#1f2937; background:#fff; appearance:none;
        }

        /* job card */
        .job-card {
            background:#fff; border-radius:18px; padding:28px 30px;
            box-shadow:0 4px 24px rgba(0,0,0,.08); margin-bottom:22px;
        }
        .job-header { margin-bottom:18px; }
        .job-header .bname { font-size:17px; font-weight:800; color:#1a2744; }
        .job-header .bmeta { font-size:13px; color:#6b7280; margin-top:4px; }
        .job-header .bmeta span { margin-right:14px; }

        .client-row {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
            gap:12px; margin-bottom:22px;
        }
        .client-chip {
            background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px;
            padding:12px 14px;
        }
        .client-chip .c-label { font-size:11px; color:#9ca3af; font-weight:600; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
        .client-chip .c-val   { font-size:14px; font-weight:700; color:#1a2744; word-break:break-word; }

        /* status grid */
        .status-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:22px; }
        @media(max-width:450px){ .status-grid{ grid-template-columns:1fr; } }
        .status-tile { border-radius:12px; padding:16px 14px; text-align:center; }
        .status-tile.pending { background:#fef9c3; border:1.5px solid #fde047; }
        .status-tile.done    { background:#d1fae5; border:1.5px solid #6ee7b7; }
        .status-tile i { font-size:20px; margin-bottom:5px; display:block; }
        .status-tile.pending i { color:#ca8a04; }
        .status-tile.done    i { color:#059669; }
        .status-tile .t-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
        .status-tile.pending .t-label { color:#92400e; }
        .status-tile.done    .t-label { color:#065f46; }
        .status-tile .t-note { font-size:11px; margin-top:3px; opacity:.75; }

        /* dual done */
        .dual-done {
            background:linear-gradient(135deg,#059669,#047857); color:#fff;
            border-radius:14px; padding:20px 24px; text-align:center; margin-bottom:20px;
        }
        .dual-done i  { font-size:28px; margin-bottom:8px; display:block; }
        .dual-done h3 { margin:0 0 4px; font-size:17px; font-weight:800; }
        .dual-done p  { margin:0; font-size:13px; opacity:.85; }

        /* provider's own code */
        .prov-code-box {
            background:linear-gradient(135deg,#14532d,#059669); color:#fff;
            border-radius:14px; padding:18px 20px; text-align:center; margin-bottom:18px;
        }
        .prov-code-box .label { font-size:11px; opacity:.75; letter-spacing:.06em; text-transform:uppercase; margin-bottom:5px; }
        .prov-code-box .code  {
            font-family:'Courier New',monospace; font-size:21px; font-weight:900;
            letter-spacing:.1em; background:rgba(255,255,255,.12);
            border-radius:8px; padding:8px 14px; display:inline-block; cursor:pointer;
        }
        .prov-code-box .hint  { font-size:11.5px; opacity:.7; margin-top:8px; }

        /* action */
        .action-area { text-align:center; }
        .btn-verify {
            display:inline-flex; align-items:center; gap:9px;
            background:linear-gradient(135deg,#1d4ed8,#1e3a8a); color:#fff;
            padding:14px 32px; border-radius:12px; font-size:16px; font-weight:700;
            border:none; cursor:pointer; box-shadow:0 4px 16px rgba(29,78,216,.35);
            transition:all .2s;
        }
        .btn-verify:hover { transform:translateY(-2px); filter:brightness(1.07); }
        .btn-verify:disabled { opacity:.5; cursor:default; transform:none; }
        .hint-text { font-size:12px; color:#9ca3af; margin-top:10px; }

        /* modal */
        .modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.55); z-index:1000;
            align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:#fff; border-radius:22px; padding:36px 32px 28px;
            max-width:420px; width:calc(100% - 32px);
            box-shadow:0 16px 60px rgba(0,0,0,.22); text-align:center;
            animation:slideUp .3s ease;
        }
        @keyframes slideUp { from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }
        .modal-icon { font-size:40px; margin-bottom:12px; }
        .modal-box h3 { font-size:19px; font-weight:800; color:#1a2744; margin:0 0 8px; }
        .modal-box p  { font-size:13.5px; color:#6b7280; margin:0 0 18px; line-height:1.6; }

        .modal-input {
            width:100%; padding:13px 16px; border:2px solid #d1d5db; border-radius:10px;
            font-size:17px; font-family:'Courier New',monospace; font-weight:700;
            letter-spacing:.08em; text-transform:uppercase; text-align:center;
            outline:none; transition:border .2s; margin-bottom:12px; box-sizing:border-box;
        }
        .modal-input:focus { border-color:#1d4ed8; }
        .modal-input.error { border-color:#ef4444; }
        .modal-input.success-input { border-color:#059669; background:#f0fdf4; }

        .modal-feedback {
            padding:10px 14px; border-radius:8px; font-size:13px; font-weight:600;
            margin-bottom:12px; display:none;
        }
        .modal-feedback.err { background:#fee2e2; color:#b91c1c; display:block; }
        .modal-feedback.ok  { background:#d1fae5; color:#065f46; display:block; }

        .modal-actions { display:flex; gap:10px; }
        .btn-modal-submit {
            flex:1; padding:12px 18px;
            background:linear-gradient(135deg,#1d4ed8,#1e3a8a); color:#fff;
            border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer;
        }
        .btn-modal-submit:disabled { opacity:.5; cursor:default; }
        .btn-modal-cancel {
            flex:1; padding:12px 18px;
            background:#f1f5f9; color:#475569; border:none;
            border-radius:10px; font-size:14px; font-weight:700; cursor:pointer;
        }
        .btn-modal-cancel:hover { background:#e2e8f0; }

        .no-bookings {
            background:#fff; border-radius:18px; padding:48px 32px;
            text-align:center; box-shadow:0 4px 24px rgba(0,0,0,.07);
        }
        .no-bookings i { font-size:48px; color:#d1d5db; margin-bottom:14px; display:block; }
    </style>
</head>
<body>
<?php if (file_exists(appPath('includes/portal-header.php'))) include appPath('includes/portal-header.php'); ?>

<div class="page-wrap">
    <h2 class="page-title"><i class="fas fa-shield-halved" style="margin-right:8px;color:#1d4ed8;"></i>Service Verification</h2>
    <p class="page-sub">Enter the client's control number (PCF-…) to confirm you are at the correct job site.</p>

    <?php if (empty($bookings)): ?>
    <div class="no-bookings">
        <i class="fas fa-calendar-check"></i>
        <h3>No jobs awaiting verification</h3>
        <p>Only paid jobs in Preparing / Starting status appear here.</p>
    </div>

    <?php else: ?>

    <?php if (count($bookings) > 1): ?>
    <div class="bk-select">
        <label>Select Job</label>
        <select onchange="location.href='verify-service.php?booking_id='+this.value">
            <?php foreach ($bookings as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $b['id'] == $bid ? 'selected' : '' ?>>
                #<?= $b['id'] ?> — <?= htmlspecialchars($b['full_name']) ?>
                (<?= date('M j', strtotime($b['preferred_date'])) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($active_bk): ?>
    <?php
        $sk_done   = !empty($active_bk['seeker_verified_at']);
        $pv_done   = !empty($active_bk['provider_verified_at']);
        $dual_done = !empty($active_bk['dual_verified_at']);
        $seeker_cn   = $active_bk['control_number']          ?? '';  // PCF – provider must enter this
        $provider_cn = $active_bk['provider_control_number'] ?? '';  // PCP – provider's own code
    ?>

    <div class="job-card">
        <div class="job-header">
            <div class="bname">Booking #<?= $active_bk['id'] ?> — <?= htmlspecialchars($active_bk['service_name'] ?: 'Pest Control Service') ?></div>
            <div class="bmeta">
                <span><i class="fas fa-calendar-alt"></i> <?= date('M j, Y', strtotime($active_bk['preferred_date'])) ?> at <?= date('g:i A', strtotime($active_bk['preferred_time'])) ?></span>
                <span><i class="fas fa-circle" style="color:<?= $dual_done ? '#059669' : ($sk_done || $pv_done ? '#d97706' : '#9ca3af') ?>;font-size:8px;vertical-align:middle;"></i>
                    <?= $dual_done ? 'Fully Verified' : ($sk_done || $pv_done ? 'Partially Verified' : 'Pending Verification') ?>
                </span>
            </div>
        </div>

        <div class="client-row">
            <div class="client-chip">
                <div class="c-label">Client Name</div>
                <div class="c-val"><?= htmlspecialchars($active_bk['full_name']) ?></div>
            </div>
            <div class="client-chip">
                <div class="c-label">Contact</div>
                <div class="c-val"><?= htmlspecialchars($active_bk['contact_number']) ?></div>
            </div>
            <div class="client-chip">
                <div class="c-label">Address</div>
                <div class="c-val"><?= htmlspecialchars(substr($active_bk['address'] ?? '—', 0, 60)) ?><?= strlen($active_bk['address'] ?? '') > 60 ? '…' : '' ?></div>
            </div>
        </div>

        <?php if ($dual_done): ?>
        <div class="dual-done">
            <i class="fas fa-circle-check"></i>
            <h3>Both Parties Verified ?</h3>
            <p>Service officially started at <?= date('M j, Y g:i A', strtotime($active_bk['dual_verified_at'])) ?>.</p>
        </div>
        <?php else: ?>

        <div class="status-grid">
            <div class="status-tile <?= $pv_done ? 'done' : 'pending' ?>">
                <i class="fas <?= $pv_done ? 'fa-circle-check' : 'fa-hourglass-half' ?>"></i>
                <div class="t-label">Your Verification</div>
                <div class="t-note"><?= $pv_done ? 'Verified ?' : 'Pending' ?></div>
            </div>
            <div class="status-tile <?= $sk_done ? 'done' : 'pending' ?>">
                <i class="fas <?= $sk_done ? 'fa-circle-check' : 'fa-hourglass-half' ?>"></i>
                <div class="t-label">Client Verification</div>
                <div class="t-note"><?= $sk_done ? 'Verified ?' : 'Pending' ?></div>
            </div>
        </div>

        <?php endif; ?>

        <!-- Provider's own code: seeker must enter this -->
        <?php if ($provider_cn): ?>
        <div class="prov-code-box">
            <div class="label">Your Code — Client will enter this into their app</div>
            <div class="code" onclick="copyCN('<?= htmlspecialchars($provider_cn) ?>')"><?= htmlspecialchars($provider_cn) ?></div>
            <div class="hint">The client enters your code to confirm you are the right technician</div>
        </div>
        <?php endif; ?>

        <div class="action-area">
            <?php if ($dual_done): ?>
                <div class="hint-text"><i class="fas fa-lock-open" style="color:#059669;"></i> Service already fully verified.</div>
            <?php elseif ($pv_done): ?>
                <div class="hint-text"><i class="fas fa-clock" style="color:#d97706;"></i> You've verified. Waiting for the client to enter their code.</div>
            <?php else: ?>
                <button class="btn-verify" onclick="openModal(<?= $active_bk['id'] ?>)">
                    <i class="fas fa-key"></i> Enter Client's Code
                </button>
                <div class="hint-text">Ask the client to show their verification screen and read out their <strong>PCF-…</strong> code.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- -- Verification Modal -- -->
<div class="modal-overlay" id="verifyModal">
    <div class="modal-box">
        <div class="modal-icon">??</div>
        <h3>Enter Client's Code</h3>
        <p>Ask the client to show their verification screen. Type the <strong>client's code</strong> (PCF-…) exactly as shown.</p>

        <input type="text" id="cnInput" class="modal-input"
               placeholder="PCF-2026-XXXXXX"
               maxlength="20" autocomplete="off" spellcheck="false"
               oninput="this.value=this.value.toUpperCase()"
               onkeydown="if(event.key==='Enter')submitVerify()">

        <div class="modal-feedback" id="modalFeedback"></div>

        <div class="modal-actions">
            <button class="btn-modal-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-modal-submit" id="submitBtn" onclick="submitVerify()">
                <i class="fas fa-shield-halved"></i> Verify
            </button>
        </div>
    </div>
</div>

<script>
let activeBid = 0;

function openModal(bid) {
    activeBid = bid;
    document.getElementById('cnInput').value = '';
    document.getElementById('cnInput').className = 'modal-input';
    document.getElementById('modalFeedback').className = 'modal-feedback';
    document.getElementById('modalFeedback').textContent = '';
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-shield-halved"></i> Verify';
    document.getElementById('verifyModal').classList.add('open');
    setTimeout(() => document.getElementById('cnInput').focus(), 200);
}

function closeModal() {
    document.getElementById('verifyModal').classList.remove('open');
}

document.getElementById('verifyModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function submitVerify() {
    const code = document.getElementById('cnInput').value.trim();
    if (!code) { showFeedback('err', 'Please enter the client\'s code.'); return; }

    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying…';

    const form = new FormData();
    form.append('action',     'verify_provider');
    form.append('booking_id', activeBid);
    form.append('code',       code);

    fetch('verify-service.php', { method:'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('cnInput').classList.add('success-input');
                showFeedback('ok', data.message);
                setTimeout(() => { closeModal(); location.reload(); }, data.dual_verified ? 2000 : 2800);
            } else {
                document.getElementById('cnInput').classList.add('error');
                showFeedback('err', data.message || 'Incorrect code. Try again.');
                document.getElementById('submitBtn').disabled = false;
                document.getElementById('submitBtn').innerHTML = '<i class="fas fa-shield-halved"></i> Verify';
            }
        })
        .catch(() => {
            showFeedback('err', 'Network error. Please try again.');
            document.getElementById('submitBtn').disabled = false;
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-shield-halved"></i> Verify';
        });
}

function showFeedback(type, msg) {
    const el = document.getElementById('modalFeedback');
    el.className = 'modal-feedback ' + type;
    el.textContent = msg;
}

function copyCN(code) {
    navigator.clipboard.writeText(code).catch(() => {});
}
</script>
<?php include appPath('includes/provider-guide.php'); ?>
<?php if (file_exists(appPath('includes/portal-footer.php'))) include appPath('includes/portal-footer.php'); ?>
</body>
</html>
