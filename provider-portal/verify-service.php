<?php
/**
 * provider-verify-service.php  (PROVIDER / CRM PORTAL)
 * ─────────────────────────────────────────────────────────────────────────────
 * Cross-Shared Control Number Verification – Provider / Technician Side
 *
 * The PROVIDER / TECHNICIAN enters the code they RECEIVED (which is the
 * SEEKER code, PCF-YYYY-XXXXXX) from the booking payment notification.
 * The system validates it against `control_number` in availed_services.
 *
 * PLACEMENT:  /pestify/provider-portal/verify-service.php
 *             (also used inline via AJAX inside crm-bookings.php modal)
 *
 * SESSION REQUIREMENTS:  provider portal session (provider_id set)
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once 'includes/auth.php';           // provider session guard
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/ControlNumberService.php';

$database = new Database();
$db       = $database->getConnection();
$pid      = (int)$_SESSION['provider_id'];   // providers.id

/* ── Tiny helpers ────────────────────────────────────────────────────────── */
function safeRow(PDO $db, string $sql, array $p = []): array|false
{
    try {
        $s = $db->prepare($sql);
        $s->execute($p);
        return $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

$cns     = new ControlNumberService($db);
$success = '';   // 'dual_verified' | 'waiting_seeker'
$error   = '';
$booking = null;

/* ══════════════════════════════════════════════════════════════════════════
   POST  –  Provider submits the code they received (which is the SEEKER code)
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $bid     = (int)($_POST['booking_id']        ?? 0);
    $entered = trim($_POST['entered_provider_cn'] ?? '');

    if (!$bid || !$entered) {
        $error = "Please enter the verification code.";
    } else {
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
        $result = $cns->verifyProviderCode($bid, $pid, $entered, $ip);

        if (!$result['success']) {
            $error = $result['error'];
        } elseif ($result['state'] === 'dual_verified') {
            $success = 'verified_both';
        } else {
            $success = 'waiting_seeker';
        }

        $booking = safeRow($db,
            "SELECT * FROM availed_services WHERE id = :id AND provider_id = :pid",
            [':id' => $bid, ':pid' => $pid]
        ) ?: null;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   GET  –  Load booking
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bid = (int)($_GET['booking_id'] ?? 0);
    if ($bid) {
        $booking = safeRow($db,
            "SELECT id, status, service_name, full_name, preferred_date, preferred_time,
                    control_number, provider_verified_at, dual_verified_at
             FROM availed_services
             WHERE id = :id AND provider_id = :pid",
            [':id' => $bid, ':pid' => $pid]
        ) ?: null;
        if (!$booking) $error = "Booking #$bid not found.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify Booking – Provider Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: 'DM Sans', sans-serif;
    background: #f5f7fa; color: #2d3748;
    min-height: 100vh; display: flex;
    align-items: center; justify-content: center; padding: 20px;
}
.card {
    background: #fff; border-radius: 20px;
    box-shadow: 0 8px 32px rgba(0,0,0,.10);
    padding: 36px 32px; width: 100%; max-width: 460px;
}
.logo { text-align: center; margin-bottom: 28px; }
.logo .icon-ring {
    width: 64px; height: 64px; border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: inline-flex; align-items: center; justify-content: center;
    margin-bottom: 12px;
    box-shadow: 0 4px 16px rgba(59,130,246,.35);
}
.logo .icon-ring i { font-size: 28px; color: #fff; }
.logo h2 { font-size: 20px; font-weight: 700; color: #1a2744; }
.logo p  { font-size: 13px; color: #718096; margin-top: 4px; }

.howto {
    background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 12px; padding: 14px 16px;
    margin-bottom: 22px; font-size: 13px; color: #1e40af;
    display: flex; gap: 12px; align-items: flex-start;
}
.howto i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }

.alert {
    padding: 12px 16px; border-radius: 10px;
    margin-bottom: 18px; font-size: 13px;
    display: flex; align-items: flex-start; gap: 10px;
}
.alert-success { background:#d1fae5; color:#065f46; border:1px solid rgba(16,185,129,.2); }
.alert-error   { background:#fee2e2; color:#991b1b; border:1px solid rgba(220,38,38,.2); }
.alert-info    { background:#e0f2fe; color:#0369a1; border:1px solid rgba(14,165,233,.2); }

.booking-box {
    background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 10px; padding: 14px 16px;
    margin-bottom: 20px; font-size: 13px;
}
.booking-box .label {
    font-size: 11px; font-weight: 700; color: #718096;
    text-transform: uppercase; letter-spacing: .5px;
}
.booking-box .val { font-weight: 600; color: #1a2744; margin-top: 2px; }

.form-group { margin-bottom: 18px; }
.form-group label {
    display: block; font-size: 11px; font-weight: 700; color: #718096;
    text-transform: uppercase; letter-spacing: .5px; margin-bottom: 8px;
}
.input-wrap { position: relative; }
.input-wrap i {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%); color: #94a3b8; font-size: 15px;
}
.code-input {
    width: 100%; padding: 13px 14px 13px 42px;
    border: 2px solid #e2e8f0; border-radius: 10px;
    font-size: 16px; font-family: inherit;
    letter-spacing: 3px; text-transform: uppercase; text-align: center;
    transition: border-color .2s;
}
.code-input:focus { outline: none; border-color: #3b82f6; }
.code-hint { font-size: 11px; color: #94a3b8; margin-top: 6px; text-align: center; }

.btn {
    width: 100%; padding: 13px; border: none; border-radius: 10px;
    font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s;
}
.btn-primary { background: #3b82f6; color: #fff; }
.btn-primary:hover { background: #1d4ed8; }

.steps { margin-bottom: 22px; }
.step-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px;
}
.step-row:last-child { border-bottom: none; }
.step-dot {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.dot-done    { background:#d1fae5; color:#065f46; }
.dot-wait    { background:#fef3c7; color:#92400e; }
.dot-pending { background:#f1f5f9; color:#94a3b8; }

.status-icon { text-align: center; padding: 24px 0 16px; }
.status-icon i { font-size: 56px; }
.status-icon p { font-size: 15px; font-weight: 700; margin-top: 12px; }
.status-icon small { font-size: 12px; color:#718096; display:block; margin-top:6px; }

.back {
    display: block; text-align: center; margin-top: 18px;
    font-size: 13px; color: #718096; text-decoration: none;
}
.back:hover { color: #3b82f6; }
</style>
</head>
<body>
<div class="card">

    <div class="logo">
        <div class="icon-ring"><i class="fas fa-shield-check"></i></div>
        <h2>Service Verification</h2>
        <p>Confirm the client is present – Provider Portal</p>
    </div>

    <?php if ($success === 'verified_both'): ?>
    <!-- ══ BOTH VERIFIED ════════════════════════════════════════════════════ -->
    <div class="status-icon">
        <i class="fas fa-circle-check" style="color:#1abc9c"></i>
        <p style="color:#065f46">Service is now In Progress!</p>
        <small>Both codes matched. The service has officially started.</small>
    </div>
    <?php if ($booking): ?>
    <div class="booking-box">
        <div class="label">Booking</div>
        <div class="val">#<?= (int)$booking['id'] ?> — <?= htmlspecialchars($booking['service_name']) ?></div>
    </div>
    <?php endif; ?>
    <a href="crm-bookings.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to CRM Bookings</a>

    <?php elseif ($success === 'waiting_seeker'): ?>
    <!-- ══ PROVIDER VERIFIED – WAITING ON SEEKER ═══════════════════════════ -->
    <div class="alert alert-info">
        <i class="fas fa-hourglass-half" style="margin-top:1px"></i>
        <div>Your code was accepted. Waiting for the client to verify their side.</div>
    </div>
    <?php if ($booking): ?>
    <div class="booking-box">
        <div class="label">Booking</div>
        <div class="val">#<?= (int)$booking['id'] ?> — <?= htmlspecialchars($booking['service_name']) ?></div>
    </div>
    <?php endif; ?>
    <div class="steps">
        <div class="step-row">
            <div class="step-dot dot-done"><i class="fas fa-check"></i></div>
            <div><strong>Your code verified</strong><br><small style="color:#718096">Done — just now</small></div>
        </div>
        <div class="step-row">
            <div class="step-dot dot-wait"><i class="fas fa-hourglass-half"></i></div>
            <div><strong>Client verifies their code</strong><br><small style="color:#718096">Pending on their end</small></div>
        </div>
    </div>
    <a href="crm-bookings.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to CRM Bookings</a>

    <?php else: ?>
    <!-- ══ DEFAULT / ERROR ═══════════════════════════════════════════════════ -->

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-circle-exclamation" style="margin-top:1px"></i>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
    <?php endif; ?>

    <?php
    $canVerify      = $booking
                      && empty($booking['provider_verified_at'])
                      && empty($booking['dual_verified_at'])
                      && !empty($booking['control_number']);
    $alreadyWaiting = $booking
                      && !empty($booking['provider_verified_at'])
                      && empty($booking['dual_verified_at']);
    ?>

    <?php if ($canVerify): ?>
    <div class="howto">
        <i class="fas fa-info-circle"></i>
        <div>
            Enter the <strong>client's control number</strong> you received in the booking
            confirmation notification. It starts with <strong>PCF-</strong>.
            Do not ask the client for it — you should already have it from your notification.
        </div>
    </div>

    <div class="booking-box">
        <div class="label">Booking</div>
        <div class="val">#<?= (int)$booking['id'] ?> — <?= htmlspecialchars($booking['service_name']) ?></div>
        <div style="margin-top:6px;font-size:12px;color:#718096;">
            Client: <?= htmlspecialchars($booking['full_name']) ?>
            &nbsp;·&nbsp;
            <?= date('M j, Y', strtotime($booking['preferred_date'])) ?>
        </div>
    </div>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
        <div class="form-group">
            <label>Client's Control Number (from your notification)</label>
            <div class="input-wrap">
                <i class="fas fa-key"></i>
                <input
                    class="code-input"
                    type="text"
                    name="entered_provider_cn"
                    placeholder="PCF-2026-XXXXXX"
                    maxlength="20"
                    autofocus
                    spellcheck="false"
                >
            </div>
            <p class="code-hint">Check your booking payment notification for this code (starts with PCF-)</p>
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-shield-check"></i> Verify Code
        </button>
    </form>

    <?php elseif ($alreadyWaiting): ?>
    <div class="alert alert-info">
        <i class="fas fa-hourglass-half"></i>
        <div>Your code was already submitted. Waiting for the client to confirm their side.</div>
    </div>
    <div class="steps">
        <div class="step-row">
            <div class="step-dot dot-done"><i class="fas fa-check"></i></div>
            <div><strong>Your code verified</strong></div>
        </div>
        <div class="step-row">
            <div class="step-dot dot-wait"><i class="fas fa-hourglass-half"></i></div>
            <div><strong>Client verifies their code</strong><br><small style="color:#718096">Pending</small></div>
        </div>
    </div>
    <a href="crm-bookings.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to CRM Bookings</a>

    <?php else: ?>
    <!-- Lookup form -->
    <div class="howto">
        <i class="fas fa-info-circle"></i>
        <div>Enter the Booking ID to open the verification form.</div>
    </div>
    <form method="GET" autocomplete="off">
        <div class="form-group">
            <label>Booking ID</label>
            <div class="input-wrap">
                <i class="fas fa-hashtag"></i>
                <input
                    type="number" name="booking_id"
                    placeholder="e.g. 16"
                    style="text-align:left;letter-spacing:0;padding-left:42px"
                    class="code-input" autofocus
                >
            </div>
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find Booking
        </button>
    </form>
    <?php endif; ?>

    <a href="crm-bookings.php" class="back"><i class="fas fa-arrow-left"></i> Back to CRM Bookings</a>
    <?php endif; ?>

</div>
</body>
</html>


<?php
/*
 * ═══════════════════════════════════════════════════════════════════════════
 *  CRM-BOOKINGS PATCH  –  Replace the `verify_control_numbers` POST handler
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * In crm-bookings.php, find the block:
 *
 *   elseif ($action === 'verify_control_numbers') { ... }
 *
 * Replace it entirely with the snippet below.
 * Also add at the top of crm-bookings.php:
 *
 *   require_once '../includes/ControlNumberService.php';
 *   $cns = new ControlNumberService($db);
 *
 * ───────────────────────────────────────────────────────────────────────────
 * SNIPPET START:
 * ───────────────────────────────────────────────────────────────────────────

    elseif ($action === 'verify_control_numbers') {
        $entered = trim($_POST['entered_provider_cn'] ?? '');

        if (!$bid) {
            $error = "Invalid booking reference.";
        } elseif (!$entered) {
            $error = "Please enter the verification code.";
        } else {
            $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
            $result = $cns->verifyProviderCode($bid, $pid, $entered, $ip);

            if (!$result['success']) {
                $error = $result['error'];
            } elseif ($result['state'] === 'dual_verified') {
                $success = "✓ Both sides verified for Booking #$bid. Service is now <strong>In Progress</strong>.";
            } else {
                $success = "✓ Your code accepted for Booking #$bid. Waiting for the client to verify their side.";
            }
        }
    }

 * ───────────────────────────────────────────────────────────────────────────
 * SNIPPET END
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Also update the CRM modal's label to reflect the cross-shared scheme:
 *
 * Find (in the HTML section):
 *   <label>Enter Provider Control Number</label>
 *
 * Replace with:
 *   <label>Enter Client's Control Number <small style="color:#718096">(PCF-… from your notification)</small></label>
 *
 * And update the placeholder attribute:
 *   placeholder="PCF-2026-XXXXXX"
 *
 * ═══════════════════════════════════════════════════════════════════════════
 */