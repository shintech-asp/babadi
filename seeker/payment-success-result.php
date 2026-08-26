<?php
chdir(dirname(__DIR__));
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/ControlNumberService.php');
require_once appPath('includes/payment_receipt_helper.php');

if (!isLoggedIn() || !isSeeker()) {
    redirect('login.php');
}

$database = new Database();
$db = $database->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);
$bid = (int)($_GET['booking_id'] ?? 0);

$booking = null;
$verified = false;
$errorMsg = '';
$seekerCn = '';
$providerCn = '';
$isRemainingPaymentFlow = false;
$latestReceipt = null;

function paymentSuccessControlNumbers(PDO $db, int $bookingId): array {
    try {
        $stmt = $db->prepare('SELECT control_number, provider_control_number FROM availed_services WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

if ($bid > 0) {
    try {
        $bookingStmt = $db->prepare(
            "SELECT a.*, p.company_name, p.user_id AS provider_user_id
             FROM availed_services a
             JOIN providers p ON a.provider_id = p.id
             WHERE a.id = :bid
               AND (a.seeker_user_id = :uid OR a.user_id = :uid2)
             LIMIT 1"
        );
        $bookingStmt->execute([':bid' => $bid, ':uid' => $uid, ':uid2' => $uid]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            $paymentStmt = $db->prepare(
                "SELECT *
                 FROM payment_transactions
                 WHERE availed_service_id = :bid AND seeker_id = :uid
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $paymentStmt->execute([':bid' => $bid, ':uid' => $uid]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

            if ($payment && !empty($payment['transaction_id'])) {
                $transactionId = (string)$payment['transaction_id'];
                $apiBase = str_starts_with($transactionId, 'cs_')
                    ? 'https://api.paymongo.com/v1/checkout_sessions/'
                    : 'https://api.paymongo.com/v1/links/';

                $ch = curl_init($apiBase . $transactionId);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                    ],
                ]);
                $response = curl_exec($ch);
                curl_close($ch);

                $payload = json_decode((string)$response, true);
                $paymongoStatus = $payload['data']['attributes']['payment_intent']['attributes']['status']
                    ?? $payload['data']['attributes']['status']
                    ?? '';

                $verified = in_array($paymongoStatus, ['succeeded', 'paid', 'active'], true);

                $currentStatus = strtolower(trim((string)($booking['status'] ?? '')));
                $paymentType = strtolower(trim((string)($payment['payment_type'] ?? '')));
                $isRemainingPaymentFlow = ($currentStatus === 'waiting_remaining_payment') || ($paymentType === 'remaining');
                $needsSync = ($payment['status'] ?? '') !== 'completed'
                    || ($isRemainingPaymentFlow && $currentStatus === 'waiting_remaining_payment');

                if ($verified && $needsSync) {
                    $db->beginTransaction();
                    try {
                        $isDownpayment = ($booking['payment_method'] ?? '') === 'downpayment';
                        $newPaymentStatus = $isRemainingPaymentFlow ? 'paid' : ($isDownpayment ? 'partial' : 'paid');
                        $paidAmount = $isRemainingPaymentFlow
                            ? (float)($booking['total_amount'] ?? 0)
                            : ($isDownpayment ? (float)($booking['downpayment_amount'] ?? 0) : (float)($booking['total_amount'] ?? 0));
                        $nextStatus = $isRemainingPaymentFlow ? 'waiting_provider_confirmation' : 'preparing';

                        $db->prepare(
                            "UPDATE availed_services
                             SET payment_status = :payment_status,
                                 paid_amount = :paid_amount,
                                 status = CASE
                                     WHEN status IN ('completed', 'cancelled') THEN status
                                     ELSE :status
                                 END,
                                 updated_at = NOW()
                             WHERE id = :bid
                               AND (seeker_user_id = :uid OR user_id = :uid2)"
                        )->execute([
                            ':payment_status' => $newPaymentStatus,
                            ':paid_amount' => $paidAmount,
                            ':status' => $nextStatus,
                            ':bid' => $bid,
                            ':uid' => $uid,
                            ':uid2' => $uid,
                        ]);

                        $db->prepare(
                            "UPDATE payment_transactions
                             SET status = 'completed', updated_at = NOW()
                             WHERE availed_service_id = :bid
                               AND seeker_id = :uid
                               AND status = 'pending'"
                        )->execute([':bid' => $bid, ':uid' => $uid]);

                        $db->commit();
                    } catch (Throwable $e) {
                        $db->rollBack();
                        $errorMsg = 'Payment was verified, but the booking records could not be updated automatically.';
                    }
                }

                if ($verified) {
                    syncCompletedReceiptsForBooking($db, $bid);
                    $receiptsByBooking = fetchReceiptsForBookings($db, [$bid]);
                    $receipts = $receiptsByBooking[$bid] ?? [];
                    if (!empty($receipts)) {
                        $latestReceipt = $receipts[0];
                        foreach ($receipts as $receipt) {
                            if ((string)($receipt['transaction_id'] ?? '') === $transactionId) {
                                $latestReceipt = $receipt;
                                break;
                            }
                        }
                    }

                    try {
                        $service = new ControlNumberService($db);
                        $result = $service->generateAndDistribute($bid, $uid, (int)($booking['provider_user_id'] ?? 0));
                        if (!empty($result['success'])) {
                            $seekerCn = (string)($result['seeker_cn'] ?? '');
                            $providerCn = (string)($result['provider_cn'] ?? '');
                        }
                    } catch (Throwable $e) {
                    }

                    if ($seekerCn === '' || $providerCn === '') {
                        $controlNumbers = paymentSuccessControlNumbers($db, $bid);
                        $seekerCn = (string)($controlNumbers['control_number'] ?? '');
                        $providerCn = (string)($controlNumbers['provider_control_number'] ?? '');
                    }

                    $bookingStmt->execute([':bid' => $bid, ':uid' => $uid, ':uid2' => $uid]);
                    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        }
    } catch (Throwable $e) {
        $errorMsg = 'An unexpected error occurred while loading the payment result.';
    }
}

if ($verified && $isRemainingPaymentFlow && $bid > 0) {
    header('Location: ' . appUrl('my-requests.php?payment=success&booking_id=' . $bid));
    exit;
}

$current_page = 'my-requests';
$use_seeker_unified_ui = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - Pestify</title>
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/seeker-unified.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f7fa; }
        .success-wrap { max-width: 760px; margin: 0 auto; padding: 3rem 1rem 4rem; }
        .success-card, .state-card {
            background: #fff;
            border-radius: 22px;
            padding: 2.75rem 2.25rem;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.10);
            text-align: center;
        }
        .hero-icon {
            width: 88px;
            height: 88px;
            margin: 0 auto 1.25rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        .hero-icon.ok { background: linear-gradient(135deg, #d1fae5, #86efac); color: #047857; }
        .hero-icon.warn { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #b45309; }
        .success-card h1, .state-card h1 { margin: 0 0 .75rem; font-size: 2rem; }
        .lead { margin: 0 0 1.5rem; color: #64748b; line-height: 1.7; }
        .chips { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; margin: 0 0 1.75rem; }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .55rem .9rem;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            font-size: .85rem;
            font-weight: 700;
            color: #0f172a;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            margin: 0 0 1.75rem;
        }
        .panel {
            text-align: left;
            padding: 1rem 1.1rem;
            border-radius: 16px;
        }
        .panel.you { background: linear-gradient(135deg, #1d4ed8, #1e3a8a); color: #fff; }
        .panel.provider { background: linear-gradient(135deg, #059669, #14532d); color: #fff; }
        .panel h3 { margin: 0 0 .6rem; font-size: .95rem; text-transform: uppercase; letter-spacing: .04em; }
        .code {
            display: block;
            margin: 0 0 .75rem;
            padding: .85rem 1rem;
            border-radius: 12px;
            background: rgba(255,255,255,.15);
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-align: center;
            font-family: "Courier New", monospace;
        }
        .receipt {
            text-align: left;
            margin: 0 0 1.75rem;
            padding: 1.1rem 1.2rem;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            background: #f8fafc;
        }
        .receipt h3 { margin: 0 0 .85rem; font-size: 1rem; }
        .receipt-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .8rem 1rem;
        }
        .receipt-item {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: .8rem .9rem;
        }
        .receipt-label {
            display: block;
            margin: 0 0 .3rem;
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .receipt-value { font-weight: 700; color: #0f172a; word-break: break-word; }
        .next-steps {
            text-align: left;
            padding: 1.15rem 1.2rem;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
            margin: 0 0 1.75rem;
        }
        .next-steps h3 { margin: 0 0 .8rem; }
        .next-steps ol { margin: 0; padding-left: 1.1rem; color: #475569; line-height: 1.7; }
        .actions { display: flex; justify-content: center; gap: .9rem; flex-wrap: wrap; }
        .btn-main, .btn-alt {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .9rem 1.25rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
        }
        .btn-main { background: #059669; color: #fff; }
        .btn-alt { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; }
        .error-copy { margin: 0 0 1.5rem; color: #64748b; line-height: 1.7; }
        @media (max-width: 720px) {
            .grid, .receipt-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="seeker-unified">
<?php include appPath('includes/header.php'); ?>
<main class="success-wrap">
    <?php if ($booking && $verified): ?>
        <section class="success-card">
            <div class="hero-icon ok"><i class="fas fa-check"></i></div>
            <h1>Payment Successful</h1>
            <p class="lead">
                Payment for <strong><?php echo htmlspecialchars((string)($booking['service_name'] ?? 'your booking')); ?></strong>
                with <strong><?php echo htmlspecialchars((string)($booking['company_name'] ?? 'the provider')); ?></strong> has been confirmed.
            </p>

            <div class="chips">
                <span class="chip"><i class="fas fa-hashtag"></i> Booking #<?php echo $bid; ?></span>
                <span class="chip"><i class="fas fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime((string)$booking['preferred_date'])); ?></span>
                <span class="chip"><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime((string)$booking['preferred_time'])); ?></span>
                <span class="chip"><i class="fas fa-circle-check"></i> <?php echo ucfirst((string)($booking['payment_status'] ?? 'paid')); ?></span>
            </div>

            <?php if ($latestReceipt): ?>
                <div class="receipt">
                    <h3><i class="fas fa-receipt"></i> Payment Receipt</h3>
                    <div class="receipt-grid">
                        <div class="receipt-item">
                            <span class="receipt-label">Receipt No.</span>
                            <span class="receipt-value"><?php echo htmlspecialchars((string)($latestReceipt['receipt_number'] ?? '')); ?></span>
                        </div>
                        <div class="receipt-item">
                            <span class="receipt-label">Payment Type</span>
                            <span class="receipt-value"><?php echo htmlspecialchars(paymentReceiptTypeLabel((string)($latestReceipt['payment_type'] ?? ''))); ?></span>
                        </div>
                        <div class="receipt-item">
                            <span class="receipt-label">Amount Paid</span>
                            <span class="receipt-value">PHP <?php echo number_format((float)($latestReceipt['amount'] ?? 0), 2); ?></span>
                        </div>
                        <div class="receipt-item">
                            <span class="receipt-label">Method</span>
                            <span class="receipt-value"><?php echo htmlspecialchars(paymentReceiptMethodLabel((string)($latestReceipt['payment_method'] ?? ''))); ?></span>
                        </div>
                        <div class="receipt-item">
                            <span class="receipt-label">Reference</span>
                            <span class="receipt-value"><?php echo htmlspecialchars((string)($latestReceipt['transaction_id'] ?? 'N/A')); ?></span>
                        </div>
                        <div class="receipt-item">
                            <span class="receipt-label">Issued At</span>
                            <span class="receipt-value">
                                <?php echo !empty($latestReceipt['paid_at']) ? date('M j, Y g:i A', strtotime((string)$latestReceipt['paid_at'])) : 'N/A'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($seekerCn !== '' && $providerCn !== ''): ?>
                <div class="grid">
                    <div class="panel you">
                        <h3>Your Code</h3>
                        <span class="code"><?php echo htmlspecialchars($seekerCn); ?></span>
                        <div>Show this to the provider on service day.</div>
                    </div>
                    <div class="panel provider">
                        <h3>Provider Code</h3>
                        <span class="code"><?php echo htmlspecialchars($providerCn); ?></span>
                        <div>Enter this when the technician arrives.</div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="next-steps">
                <h3>What Happens Next</h3>
                <ol>
                    <li>The provider receives your confirmed booking and payment record.</li>
                    <li>Use the Service Monitoring area in My Requests to follow schedule, status, proof, and completion updates.</li>
                    <li>On service day, complete the mutual confirmation step before the work begins.</li>
                </ol>
            </div>

            <div class="actions">
                <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-main"><i class="fas fa-list-check"></i> Go to My Requests</a>
                <a href="<?php echo appUrl('seeker-booking-calendar.php'); ?>" class="btn-alt"><i class="fas fa-calendar-days"></i> View Booking Calendar</a>
            </div>
        </section>
    <?php elseif ($booking && !$verified): ?>
        <section class="state-card">
            <div class="hero-icon warn"><i class="fas fa-hourglass-half"></i></div>
            <h1>Payment Still Processing</h1>
            <p class="error-copy">
                The payment gateway has not finished confirming Booking #<?php echo $bid; ?> yet.
                <?php if ($errorMsg !== ''): ?>
                    <br><?php echo htmlspecialchars($errorMsg); ?>
                <?php endif; ?>
            </p>
            <div class="actions">
                <a href="<?php echo appUrl('payment-success.php?booking_id=' . $bid); ?>" class="btn-main"><i class="fas fa-rotate"></i> Retry Verification</a>
                <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-alt"><i class="fas fa-list"></i> Back to My Requests</a>
            </div>
        </section>
    <?php else: ?>
        <section class="state-card">
            <div class="hero-icon warn"><i class="fas fa-circle-question"></i></div>
            <h1>Booking Not Found</h1>
            <p class="error-copy">
                The payment result could not be matched to a booking in your account.
                <?php if ($errorMsg !== ''): ?>
                    <br><?php echo htmlspecialchars($errorMsg); ?>
                <?php endif; ?>
            </p>
            <div class="actions">
                <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-main"><i class="fas fa-list-check"></i> Open My Requests</a>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include appPath('includes/footer.php'); ?>
</body>
</html>
