<?php
chdir(dirname(__DIR__));
// payment-cancel.php
// PayMongo cancel_url destination for seeker checkout cancellation.
require_once 'config/config.php';
require_once 'config/database.php';

if (!isLoggedIn() || !isSeeker()) {
    redirect('login.php');
}

$database = new Database();
$db       = $database->getConnection();
$uid      = (int)$_SESSION['user_id'];
$bid      = (int)($_GET['booking_id'] ?? 0);

if ($bid > 0) {
    try {
        $db->prepare(
            "UPDATE payment_transactions
             SET status = 'failed', updated_at = NOW()
             WHERE availed_service_id = :bid
               AND seeker_id = :uid
               AND status = 'pending'"
        )->execute([':bid' => $bid, ':uid' => $uid]);
    } catch (Exception $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Payment Cancelled - Pestify</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{font-family:Segoe UI,sans-serif;background:#f5f7fa;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px}
        .card{max-width:560px;width:100%;background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.12);padding:34px 28px;text-align:center}
        .icon{width:78px;height:78px;border-radius:50%;background:linear-gradient(135deg,#fde68a,#fbbf24);margin:0 auto 16px;display:flex;align-items:center;justify-content:center;color:#92400e;font-size:34px}
        h1{margin:0 0 8px;font-size:28px;color:#92400e}
        p{margin:0;color:#6b7280;line-height:1.6}
        .actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:24px}
        .btn{display:inline-flex;align-items:center;gap:7px;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700;font-size:14px}
        .btn-primary{background:#059669;color:#fff}
        .btn-secondary{border:2px solid #d1d5db;color:#4b5563;background:#fff}
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
        <h1>Payment Cancelled</h1>
        <p>You exited the PayMongo checkout before completing payment. Your booking remains pending until payment is completed.</p>
        <div class="actions">
            <?php if ($bid > 0): ?>
            <a class="btn btn-primary" href="<?php echo appUrl('payment-redirect.php'); ?>?booking_id=<?= $bid ?>">
                <i class="fas fa-lock"></i> Retry PayMongo Payment
            </a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?php echo appUrl('my-requests.php'); ?>">
                <i class="fas fa-list-check"></i> Go to My Requests
            </a>
        </div>
    </div>
</body>
</html>
