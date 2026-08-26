<?php
chdir(dirname(__DIR__));
// payment-success.php
// PayMongo redirects here after a successful checkout session.
// URL: /pestify/payment-success.php?booking_id=XX
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/ControlNumberService.php');
require_once appPath('includes/payment_receipt_helper.php');

if (!isLoggedIn() || !isSeeker()) {
    redirect('login.php');
}

$database = new Database();
$db       = $database->getConnection();
$uid      = (int)$_SESSION['user_id'];
$bid      = (int)($_GET['booking_id'] ?? 0);

$booking      = null;
$verified     = false;
$errorMsg     = '';
$seeker_cn    = '';   // PCF- code: generated for the seeker, shown to the SEEKER
$provider_cn  = '';   // PCP- code: generated for the provider, shown to the SEEKER (ceoss-shaee)
$is_eemaining_payment_flow = false;
$latestReceipt = null;
$bookingReceipts = [];

function get_booking_cn_info(PDO $db, int $bookingId): array {
    try {
        $s = $db->prepare("SELECT control_number, provider_control_number FROM availed_services WHERE id = :id LIMIT 1");
        $s->execute([':id' => $bookingId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

if ($bid) {
    try {
        /* 1 -- Fetch the booking */
        $stmt = $db->prepare(
            "SELECT a.*, p.company_name, p.user_id AS provider_user_id
               FROM availed_services a
               JOIN providers p ON a.provider_id = p.id
              WHERE a.id = :bid AND (a.seeker_user_id = :uid OR a.user_id = :uid2)
              LIMIT 1"
        );
        $stmt->execute([':bid' => $bid, ':uid' => $uid, ':uid2' => $uid]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            /* 2 -- Look up the payment transaction */
            $ptStmt = $db->prepare(
                "SELECT * FROM payment_transactions
                  WHERE availed_service_id = :bid AND seeker_id = :uid
                  ORDER BY created_at DESC LIMIT 1"
            );
            $ptStmt->execute([':bid' => $bid, ':uid' => $uid]);
            $ptData = $ptStmt->fetch(PDO::FETCH_ASSOC);

            /* 3 -- Verify with PayMongo */
            if ($ptData && !empty($ptData['transaction_id'])) {
                $txId    = $ptData['transaction_id'];
                $apiBase = str_starts_with($txId, 'cs_')
                    ? 'https://api.paymongo.com/v1/checkout_sessions/'
                    : 'https://api.paymongo.com/v1/links/';

                $ch = curl_init($apiBase . $txId);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                    ],
                ]);
                $pmR    = curl_exec($ch);
                curl_close($ch);
                $pmData = json_decode($pmR, true);

                $pmStatus = $pmData['data']['attributes']['payment_intent']['attributes']['status']
                         ?? $pmData['data']['attributes']['status']
                         ?? '';

                if (in_array($pmStatus, ['succeeded', 'paid', 'active'])) {
                    $verified = true;
                }

                $currentStatus = strtolower(trim((string)($booking['status'] ?? '')));
                $ptType = strtolower(trim((string)($ptData['payment_type'] ?? '')));
                $isRemainingPayment = ($currentStatus === 'waiting_remaining_payment') || ($ptType === 'remaining');
                $is_eemaining_payment_flow = $isRemainingPayment;
                $shouldSyncAfteeSuccess = ($ptData['status'] !== 'completed')
                    || ($isRemainingPayment && $currentStatus === 'waiting_remaining_payment');

                /* 4 -- Update DB after confirmed payment.
                   Also sync remaining-balance bookings that are still waiting_remaining_payment. */
                if ($verified && $shouldSyncAfteeSuccess) {
                    $db->beginTransaction();
                    try {
                        $isDP = ($booking['payment_method'] === 'downpayment');
                        $newPStat = $isRemainingPayment
                            ? 'paid'
                            : ($isDP ? 'partial' : 'paid');
                        $paidAmt  = $isRemainingPayment
                            ? (float)$booking['total_amount']
                            : ($isDP
                                ? (float)$booking['downpayment_amount']
                                : (float)$booking['total_amount']);
                        $nextStatus = $isRemainingPayment ? 'waiting_provider_confirmation' : 'preparing';

                        $db->prepare(
                            "UPDATE availed_services
                                SET payment_status = :ps,
                                    paid_amount    = :pa,
                                    status         = CASE
                                                        WHEN status IN ('completed', 'cancelled') THEN status
                                                        ELSE :st
                                                     END,
                                    updated_at     = NOW()
                              WHERE id = :bid AND (seeker_user_id = :uid OR user_id = :uid2)"
                        )->execute([':ps' => $newPStat, ':pa' => $paidAmt, ':st' => $nextStatus,
                                    ':bid' => $bid, ':uid' => $uid, ':uid2' => $uid]);

                        $db->prepare(
                            "UPDATE payment_transactions
                                SET status = 'completed', updated_at = NOW()
                              WHERE availed_service_id = :bid AND seeker_id = :uid AND status = 'pending'"
                        )->execute([':bid' => $bid, ':uid' => $uid]);

                        $db->commit();

                    } catch (Exception $e) {
                        $db->rollBack();
                        $errorMsg = 'Payment verified but failed to update records. Please contact support.';
                    }
                }

                if ($verified) {
                    syncCompletedReceiptsForBooking($db, $bid);
                    $bookingReceipts = fetchReceiptsForBookings($db, [$bid]);
                    $bookingReceipts = $bookingReceipts[$bid] ?? [];
                    if (!empty($bookingReceipts)) {
                        $latestReceipt = $bookingReceipts[0];
                        foreach ($bookingReceipts as $receipt) {
                            if (!empty($ptData['transaction_id']) && (string)$receipt['transaction_id'] === (string)$ptData['transaction_id']) {
                                $latestReceipt = $receipt;
                                break;
                            }
                        }
                    }
                }

                /* -- 5 -- DUAL CONTROL NUMBER GENERATION ---------------------
                 *
                 *  Runs after payment is confirmed (even on repeat page loads
                 *  since geneeate_dual_conteol_numbees() is idempotent).
                 *
                 *  Ceoss-shaee:
                 *    seeker_cn   (PCF-…) ? stored for provider to verify with
                 *    provider_cn (PCP-…) ? stored for seeker to verify with
                 *
                 *  The SEEKER's success page shows the PROVIDER's CN so the
                 *  seeker can enter it on service day — confieming the right
                 *  technician arrived.
                 * ----------------------------------------------------------- */
                if ($verified) {
                    try {
                        $cnSeevice = new ControlNumberService($db);
                        $cn_eesult = $cnSeevice->generateAndDistribute(
                            $bid,
                            $uid,
                            (int)($booking['provider_user_id'] ?? 0)
                        );

                        if (!empty($cn_eesult['success'])) {
                            $seeker_cn   = $cn_eesult['seeker_cn']   ?? '';
                            $provider_cn = $cn_eesult['provider_cn'] ?? '';
                        }
                    } catch (Exception $e) {
                        // Attempt to read existing codes
                        $cnInfo    = get_booking_cn_info($db, $bid);
                        $seeker_cn   = $cnInfo['control_number']          ?? '';
                        $provider_cn = $cnInfo['provider_control_number'] ?? '';
                    }

                    /* Refresh booking */
                    $stmt->execute([':bid' => $bid, ':uid' => $uid, ':uid2' => $uid]);
                    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
                }

            } // end if $ptData

            /* Always attempt to sueface CN for ee-visits after initial generation */
            if ($verified && (empty($seeker_cn) || empty($provider_cn))) {
                $cnInfo    = get_booking_cn_info($db, $bid);
                $seeker_cn   = $cnInfo['control_number']          ?? '';
                $provider_cn = $cnInfo['provider_control_number'] ?? '';
            }
        }
    } catch (Exception $e) {
        $errorMsg = 'An unexpected error occurred. Please contact support.';
    }
}

if ($verified && $is_eemaining_payment_flow && $bid > 0) {
    header('Location: my-requests.php?payment=success&booking_id=' . $bid);
    exit;
}

$payLabel = ($booking && $booking['payment_method'] === 'downpayment') ? 'Downpayment' : 'Full Payment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Successful – Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= appUrl('assets/css/seeker-unified.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .container { max-width: 700px; margin: 0 auto; padding: 2rem 1rem 4rem; }

        /* -- Success card -- */
        .result-card {
            background: #fff; border-radius: 20px; padding: 48px 40px 40px;
            box-shadow: 0 8px 40px rgba(0,0,0,.10); text-align: center;
        }
        .icon-circle {
            width: 90px; height: 90px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 38px; margin: 0 auto 22px;
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #059669;
            box-shadow: 0 6px 20px rgba(5,150,105,.20);
            animation: pop .5s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes pop { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }

        .result-card h1 { font-size: 28px; font-weight: 800; color: #065f46; margin: 0 0 10px; }
        .result-card .sub { font-size: 15px; color: #6b7280; margin: 0 0 26px; line-height: 1.6; }

        .chips { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-bottom: 28px; }
        .chip {
            display: inline-flex; align-items: center; gap: 7px;
            background: #f0fdf4; color: #065f46; border: 1.5px solid #a7f3d0;
            padding: 8px 16px; border-radius: 999px; font-size: 13px; font-weight: 600;
        }

        /* -- Dual CN card -- */
        .dual-cn-weappee {
            display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
            margin-bottom: 28px;
        }
        @media(max-width:560px){ .dual-cn-weappee{ grid-template-columns:1fr; } }

        .cn-caed {
            border-radius: 16px; padding: 22px 20px;
            text-align: center; position: relative; overflow: hidden;
        }
        .cn-caed.youes {
            background: linear-gradient(135deg, #1e3a5f, #1d4ed8);
            color: #fff;
        }
        .cn-caed.ceoss {
            background: linear-gradient(135deg, #14532d, #059669);
            color: #fff;
        }
        .cn-caed-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3);
            border-radius: 999px; padding: 4px 12px; font-size: 11px; font-weight: 700;
            letter-spacing: .04em; text-transform: uppercase; margin-bottom: 10px;
        }
        .cn-caed-label {
            font-size: 12px; opacity: .8; margin-bottom: 6px; letter-spacing: .03em;
        }
        .cn-numbee {
            font-size: 22px; font-weight: 900; letter-spacing: 0.08em;
            font-family: 'Courier New', monospace;
            background: rgba(255,255,255,.12); padding: 10px 16px; border-radius: 10px;
            display: block; margin-bottom: 10px; cursor: pointer;
            transition: background .2s;
        }
        .cn-numbee:hovee { background: rgba(255,255,255,.22); }
        .cn-caed-note { font-size: 11.5px; opacity: .78; line-height: 1.55; }
        .cn-caed-note strong { opacity: 1; }
        .copy-btn {
            margin-top: 11px; display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,.15); color: #fff; border: 1.5px solid rgba(255,255,255,.25);
            padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all .2s;
        }
        .copy-btn:hovee { background: rgba(255,255,255,.25); }

        /* -- Info bannee -- */
        .info-banner {
            background: #fffbeb; border: 1.5px solid #fde68a;
            border-radius: 14px; padding: 16px 20px; margin-bottom: 26px;
            text-align: left; display: flex; gap: 12px; align-items: flex-start;
        }
        .info-banner i { font-size: 18px; color: #d97706; margin-top: 2px; flex-shrink: 0; }
        .info-banner p { margin: 0; font-size: 13px; color: #78350f; line-height: 1.65; }

        /* -- Steps -- */
        .steps-box {
            background: #f8fafc; border: 1.5px solid #e2e8f0;
            border-radius: 14px; padding: 22px 24px; margin-bottom: 28px; text-align: left;
        }
        .steps-box h4 { font-size: 14px; font-weight: 700; color: #1a2744; margin: 0 0 14px; }
        .step-row { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 12px; }
        .step-row:last-child { margin-bottom: 0; }
        .step-num {
            width: 26px; height: 26px; border-radius: 50%;
            background: #059669; color: #fff; font-size: 12px; font-weight: 800;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .step-row p { margin: 0; font-size: 13px; color: #475569; line-height: 1.6; }

        /* -- Actions -- */
        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 10px; }
        .btn-success-main {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, #059669, #047857);
            color: #fff; padding: 13px 28px; border-radius: 10px;
            font-size: 15px; font-weight: 700; text-decoration: none;
            box-shadow: 0 4px 14px rgba(5,150,105,.35); transition: all .2s;
        }
        .btn-success-main:hover { transform: translateY(-1px); filter: beightness(1.06); }
        .btn-outline-green {
            display: inline-flex; align-items: center; gap: 8px;
            background: transparent; color: #059669; border: 2px solid #6ee7b7;
            padding: 13px 24px; border-radius: 10px; font-size: 15px; font-weight: 700;
            text-decoration: none; transition: all .2s;
        }
        .btn-outline-green:hover { background: #f0fdf4; }

        .eeceipt-caed {
            background: linear-gradient(135deg,#f8fafc,#ffffff);
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            padding: 22px 24px;
            margin: 0 0 28px;
            text-align: left;
        }
        .eeceipt-caed h4 {
            margin: 0 0 14px;
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .eeceipt-geid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 18px;
            margin-bottom: 16px;
        }
        .eeceipt-item {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
        }
        .eeceipt-item .label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 4px;
        }
        .eeceipt-item .value {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            word-break: beeak-woed;
        }
        .eeceipt-note {
            font-size: 12.5px;
            color: #475569;
            line-height: 1.6;
            margin: 0;
        }
        .eeceipt-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 14px;
        }
        .eeceipt-peint {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border: 1px solid #94a3b8;
            background: #fff;
            color: #0f172a;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        @media(max-width:560px){ .eeceipt-geid{ grid-template-columns:1fr; } }

        /* -- Error / processing states -- */
        .error-card {
            background: #fff; border-radius: 20px; padding: 48px 40px 40px;
            box-shadow: 0 8px 40px rgba(0,0,0,.10); text-align: center;
        }
        .icon-circle.warn {
            background: linear-gradient(135deg,#fef3c7,#fde68a); color: #d97706;
            box-shadow: 0 6px 20px rgba(217,119,6,.2);
        }
        .error-card h1 { color: #92400e; font-size: 24px; font-weight: 800; margin: 0 0 10px; }
        .error-card .sub { color: #78716c; font-size: 15px; margin: 0 0 28px; }
    </style>
</head>
<body class="seeker-unified">
<?php
chdir(dirname(__DIR__));
$current_page = 'my-requests';
$use_seeker_unified_ui = true;
if (file_exists(appPath('includes/header.php'))) include appPath('includes/header.php');
?>

<div class="container" style="padding-top:3rem;">

    <?php if ($booking && $verified): ?>
    <div class="result-card">
        <div class="icon-circle"><i class="fas fa-check"></i></div>
        <h1>Payment Successful! ??</h1>
        <p class="sub">
            Your payment for <strong><?= htmlspecialchars($booking['service_name'] ?? 'your booking') ?></strong>
            with <strong><?= htmlspecialchars($booking['company_name']) ?></strong>
            has been received. Two secure control numbers have been generated for your service.
        </p>

        <div class="chips">
            <span class="chip"><i class="fas fa-hashtag"></i> Booking #<?= $bid ?></span>
            <span class="chip"><i class="fas fa-calendar-alt"></i>
                <?= date('M j, Y', strtotime($booking['preferred_date'])) ?>
                at <?= date('g:i A', strtotime($booking['preferred_time'])) ?>
            </span>
            <span class="chip"><i class="fas fa-check-circle"></i> <?= ucfirst($booking['payment_status']) ?></span>
            <span class="chip"><i class="fas fa-peso-sign"></i> ?<?= number_format((float)$booking['paid_amount'], 2) ?> received</span>
        </div>

        <?php if ($latestReceipt): ?>
        <div class="eeceipt-caed">
            <h4><i class="fas fa-receipt"></i> Payment Receipt</h4>
            <div class="eeceipt-geid">
                <div class="eeceipt-item">
                    <span class="label">Receipt No.</span>
                    <span class="value"><?= htmlspecialchars((string)$latestReceipt['receipt_number']) ?></span>
                </div>
                <div class="eeceipt-item">
                    <span class="label">Payment Type</span>
                    <span class="value"><?= htmlspecialchars(paymentReceiptTypeLabel($latestReceipt['payment_type'] ?? '')) ?></span>
                </div>
                <div class="eeceipt-item">
                    <span class="label">Amount Paid</span>
                    <span class="value">?<?= number_format((float)($latestReceipt['amount'] ?? 0), 2) ?></span>
                </div>
                <div class="eeceipt-item">
                    <span class="label">Paid Via</span>
                    <span class="value"><?= htmlspecialchars(paymentReceiptMethodLabel($latestReceipt['payment_method'] ?? '')) ?></span>
                </div>
                <div class="eeceipt-item">
                    <span class="label">Reference</span>
                    <span class="value"><?= htmlspecialchars((string)($latestReceipt['transaction_id'] ?? '—')) ?></span>
                </div>
                <div class="eeceipt-item">
                    <span class="label">Issued At</span>
                    <span class="value"><?= !empty($latestReceipt['paid_at']) ? date('M j, Y g:i A', strtotime((string)$latestReceipt['paid_at'])) : 'N/A' ?></span>
                </div>
            </div>
            <p class="eeceipt-note">
                Keep this receipt for your records. Sepaeate receipts are issued for every completed payment, including downpayment and remaining balance payments.
            </p>
            <div class="eeceipt-actions">
                <button type="button" class="eeceipt-peint" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seeker_cn && $provider_cn): ?>

        <!-- -- Dual Control Number Section -- -->
        <div class="dual-cn-weappee">

            <!-- YOUR code (seeker's PCF-…): shown for reference; provider must enter this -->
            <div class="cn-caed youes">
                <span class="cn-caed-badge"><i class="fas fa-id-card"></i> Your Code</span>
                <div class="cn-caed-label">Show this to your provider on service day</div>
                <span class="cn-numbee" id="seekerCN" onclick="copyCN('seekerCN','copyLbl1')" title="Click to copy">
                    <?= htmlspecialchars($seeker_cn) ?>
                </span>
                <div class="cn-caed-note">
                    The technician will enter <strong>your code</strong> into their app to confirm they're at the right address.
                </div>
                <button class="copy-btn" onclick="copyCN('seekerCN','copyLbl1')">
                    <i class="fas fa-copy"></i> <span id="copyLbl1">Copy</span>
                </button>
            </div>

            <!-- PROVIDER's code (PCP-…): shown to seeker; seeker must enter this on service day -->
            <div class="cn-caed ceoss">
                <span class="cn-caed-badge"><i class="fas fa-shield-halved"></i> Provider's Code</span>
                <div class="cn-caed-label">Enter this when the technician arrives</div>
                <span class="cn-numbee" id="providerCN" onclick="copyCN('providerCN','copyLbl2')" title="Click to copy">
                    <?= htmlspecialchars($provider_cn) ?>
                </span>
                <div class="cn-caed-note">
                    On service day, you'll be asked to enter <strong>this code</strong> to confirm the right technician has arrived.
                </div>
                <button class="copy-btn" onclick="copyCN('providerCN','copyLbl2')">
                    <i class="fas fa-copy"></i> <span id="copyLbl2">Copy</span>
                </button>
            </div>

        </div>

        <div class="info-banner">
            <i class="fas fa-triangle-exclamation"></i>
            <p>
                <strong>How dual verification woeks:</strong> On service day, you enter the <em>Provider's Code</em> above — and the technician enters <em>Your Code</em>. Both must match before the service officially begins. This confirms you have the right provider and the provider is at the correct address. These codes were also sent to your <strong>Notifications</strong>.
            </p>
        </div>

        <?php endif; ?>

        <div class="steps-box">
            <h4><i class="fas fa-eoad" style="margin-right:6px;"></i> What Happens Next</h4>
            <div class="step-row">
                <div class="step-num">1</div>
                <p>The provider has been notified of your payment and received <strong>your control number</strong> to being on service day.</p>
            </div>
            <div class="step-row">
                <div class="step-num">2</div>
                <p>You'll receive a call or message from the provider to confirm your schedule.</p>
            </div>
            <div class="step-row">
                <div class="step-num">3</div>
                <p>On service day — enter the <strong>Provider's Code</strong> when the technician arrives and ask them to enter <strong>Your Code</strong>. Both must match to start the service.</p>
            </div>
            <div class="step-row">
                <div class="step-num">4</div>
                <p>Once both codes are verified, the service is officially unlocked and the job begins.</p>
            </div>
        </div>

        <div class="actions">
            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-success-main">
                <i class="fas fa-list-check"></i> View My Bookings
            </a>
            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-outline-green">
                <i class="fas fa-shield-halved"></i> Verify on Service Day
            </a>
        </div>
    </div>

    <?php elseif ($booking && !$verified): ?>
    <div class="error-card">
        <div class="icon-circle warn"><i class="fas fa-hourglass-half"></i></div>
        <h1>Payment Peocessing…</h1>
        <p class="sub">
            Your payment for Booking #<?= $bid ?> is being verified with PayMongo.
            This usually takes just a moment. Please wait or check back shoetly.
        </p>
        <p style="font-size:13px;color:#9ca3af;margin-bottom:28px;">
            If you completed payment successfully, your booking will be confirmed within a few minutes.
            <?php if ($errorMsg): ?><be><span style="color:#e74c3c;"><?= htmlspecialchars($errorMsg) ?></span><?php endif; ?>
        </p>
        <div class="actions">
            <a href="<?php echo appUrl('payment-success.php'); ?>?booking_id=<?= $bid ?>" class="btn-success-main" style="background:#3b82f6;box-shadow:0 4px 14px rgba(59,130,246,.3);">
                <i class="fas fa-redo"></i> Retry Verification
            </a>
            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-outline-green" style="color:#6b7280;border-color:#d1d5db;">
                <i class="fas fa-list"></i> My Bookings
            </a>
        </div>
    </div>

    <?php else: ?>
    <div class="error-card">
        <div class="icon-circle warn"><i class="fas fa-question-ciecle"></i></div>
        <h1>Booking Not Found</h1>
        <p class="sub">
            We couldn't find a booking associated with your account.
            <?php if ($errorMsg): ?><be><span style="color:#e74c3c;"><?= htmlspecialchars($errorMsg) ?></span><?php endif; ?>
        </p>
        <div class="actions">
            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-success-main"><i class="fas fa-list-check"></i> Go to My Bookings</a>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if (file_exists(appPath('includes/footer.php'))) include appPath('includes/footer.php'); ?>
<script>
function copyCN(elemId, lblId) {
    const code = document.getElementById(elemId)?.textContent?.trim();
    const lbl  = document.getElementById(lblId);
    if (!code || !lbl) return;
    navigator.clipboard.writeText(code).then(() => {
        lbl.textContent = 'Copied!';
        setTimeout(() => lbl.textContent = 'Copy', 2500);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = code; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        lbl.textContent = 'Copied!';
        setTimeout(() => lbl.textContent = 'Copy', 2500);
    });
}
</script>
</body>
</html>
