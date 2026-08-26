<?php
chdir(dirname(__DIR__));
// payment-failed.php
// PayMongo redirects here when the user cancels / payment fails.
// URL: /pestify/payment-failed.php?booking_id=XX
require_once 'config/config.php';
require_once 'config/database.php';

if (!isLoggedIn() || !isSeeker()) {
    redirect('login.php');
}

$database = new Database();
$db       = $database->getConnection();
$uid      = (int)$_SESSION['user_id'];
$bid      = (int)($_GET['booking_id'] ?? 0);

$booking      = null;

if ($bid) {
    try {
        /* Fetch booking details */
        $stmt = $db->prepare(
            "SELECT a.*, p.company_name
             FROM availed_services a
             JOIN providers p ON a.provider_id = p.id
             WHERE a.id = :bid AND (a.seeker_user_id = :uid OR a.user_id = :uid2)
             LIMIT 1"
        );
        $stmt->execute([':bid' => $bid, ':uid' => $uid, ':uid2' => $uid]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            /* Mark payment transaction as failed if pending */
            $db->prepare(
                "UPDATE payment_transactions
                 SET status = 'failed', updated_at = NOW()
                 WHERE availed_service_id = :bid
                   AND seeker_id = :uid
                   AND status = 'pending'"
            )->execute([':bid' => $bid, ':uid' => $uid]);
        }
    } catch (Exception $e) { /* non-fatal */ }
}

$payAmt   = $booking
    ? ($booking['payment_method'] === 'downpayment'
        ? (float)$booking['downpayment_amount']
        : (float)$booking['total_amount'])
    : 0;
$payLabel = $booking && $booking['payment_method'] === 'downpayment' ? 'Downpayment' : 'Full Payment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Cancelled – Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .container { max-width: 680px; margin: 0 auto; padding: 2rem 1rem 4rem; }

        .result-card {
            background: #fff; border-radius: 20px; padding: 48px 40px 40px;
            box-shadow: 0 8px 40px rgba(0,0,0,.10); text-align: center;
        }
        .icon-circle {
            width: 90px; height: 90px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 38px; margin: 0 auto 22px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #d97706;
            box-shadow: 0 6px 20px rgba(217,119,6,.18);
            animation: pop .5s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes pop { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }

        .result-card h1 { font-size: 26px; font-weight: 800; color: #92400e; margin: 0 0 10px; }
        .result-card .sub { font-size: 15px; color: #6b7280; margin: 0 0 28px; line-height: 1.6; }

        .chips { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-bottom: 28px; }
        .chip {
            display: inline-flex; align-items: center; gap: 7px;
            background: #fef3c7; color: #92400e; border: 1.5px solid #fcd34d;
            padding: 8px 16px; border-radius: 999px;
            font-size: 13px; font-weight: 600;
        }

        .info-box {
            background: #fffbeb; border: 1.5px solid #fde68a;
            border-radius: 14px; padding: 18px 22px; margin-bottom: 30px;
            text-align: left;
        }
        .info-box p { margin: 0; font-size: 13px; color: #78350f; line-height: 1.7; }
        .info-box i { margin-right: 6px; color: #d97706; }

        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

        .btn-retry {
            display: inline-flex; align-items: center; gap: 8px;
            background: #059669; color: #fff;
            padding: 13px 28px; border-radius: 10px;
            font-size: 15px; font-weight: 700;
            text-decoration: none; border: none; cursor: pointer;
            transition: all .2s; box-shadow: 0 4px 14px rgba(5,150,105,.35);
        }
        .btn-retry:hover { background: #047857; transform: translateY(-1px); }

        .btn-secondary {
            display: inline-flex; align-items: center; gap: 8px;
            background: transparent; color: #6b7280; border: 2px solid #d1d5db;
            padding: 13px 24px; border-radius: 10px;
            font-size: 15px; font-weight: 700;
            text-decoration: none; cursor: pointer; transition: all .2s;
        }
        .btn-secondary:hover { background: #f9fafb; color: #374151; border-color: #9ca3af; }

        .btn-danger-outline {
            display: inline-flex; align-items: center; gap: 8px;
            background: transparent; color: #dc2626; border: 2px solid #fca5a5;
            padding: 11px 22px; border-radius: 10px;
            font-size: 14px; font-weight: 700;
            text-decoration: none; cursor: pointer; transition: all .2s;
        }
        .btn-danger-outline:hover { background: #fee2e2; }

        .help-text {
            margin-top: 24px; font-size: 13px; color: #9ca3af; line-height: 1.6;
        }
        .help-text a { color: #059669; text-decoration: none; font-weight: 600; }
        .help-text a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<?php
chdir(dirname(__DIR__));
$current_page = 'my-requests';
if (file_exists(appPath('includes/header.php'))) include appPath('includes/header.php');
?>

<div class="container" style="padding-top:3rem;">

    <div class="result-card">
        <div class="icon-circle"><i class="fas fa-exclamation-circle"></i></div>
        <h1>Payment Not Completed</h1>

        <?php if ($booking): ?>
        <p class="sub">
            You left the checkout before completing your
            <strong><?= $payLabel ?></strong>
            for <strong><?= htmlspecialchars($booking['service_name'] ?? 'your booking') ?></strong>
            with <strong><?= htmlspecialchars($booking['company_name']) ?></strong>.<br>
            No charges were made. Your booking is still reserved.
        </p>

        <div class="chips">
            <span class="chip"><i class="fas fa-hashtag"></i> Booking #<?= $bid ?></span>
            <?php if ($payAmt > 0): ?>
            <span class="chip"><i class="fas fa-peso-sign"></i> ?<?= number_format($payAmt, 2) ?> due</span>
            <?php endif; ?>
            <span class="chip"><i class="fas fa-clock"></i> Payment Pending</span>
        </div>

        <div class="info-box">
            <p>
                <i class="fas fa-info-circle"></i>
                Your booking is <strong>still held</strong> for you. You can retry payment using the button below.
                If you don't complete payment, the provider may release your slot.
            </p>
        </div>

        <div class="actions">
            <?php if ($bid): ?>
            <a href="<?php echo appUrl('payment-redirect.php'); ?>?booking_id=<?= $bid ?>" class="btn-retry">
                <i class="fas fa-lock"></i> Retry Payment
            </a>
            <?php else: ?>
            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-retry">
                <i class="fas fa-list-check"></i> Go to My Bookings
            </a>
            <?php endif; ?>
            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-secondary">
                <i class="fas fa-times"></i> Pay Later
            </a>
        </div>

        <p class="help-text">
            Need a different payment method? <a href="<?php echo appUrl('my-requests.php'); ?>">Go to My Bookings</a> and click <em>Pay Now</em> again — GCash, Maya, and card are all available.<br>
            Having trouble? <a href="<?php echo appUrl('contact.php'); ?>">Contact us</a>.
        </p>

        <?php else: ?>
        <!-- Generic fallback when no booking found -->
        <p class="sub">The payment was not completed or could not be found. No charges were made.</p>
        <div class="actions">
            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-retry">
                <i class="fas fa-list-check"></i> My Bookings
            </a>
            <a href="<?php echo appUrl('providers.php'); ?>" class="btn-secondary">
                <i class="fas fa-search"></i> Browse Providers
            </a>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php if (file_exists(appPath('includes/footer.php'))) include appPath('includes/footer.php'); ?>
</body>
</html>
