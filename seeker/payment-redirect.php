<?php
chdir(dirname(__DIR__));
// payment-redirect.php — in Pestify root
// Seeker clicks "Pay Now" from notification — this page confirms and redirects to PayMongo.
session_start();
require_once 'config/config.php';

$loginUrl = appUrl('login.php');
$homeUrl = appUrl('index.php');
$providerDetailsUrl = appUrl('provider-details.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $loginUrl . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? $homeUrl)); exit;
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

date_default_timezone_set('Asia/Manila');

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = (int)$_SESSION['user_id'];

if (!$booking_id) { header('Location: ' . $homeUrl); exit; }

// Fetch booking — must belong to this seeker and be payable.
// Joins users so the PayMongo billing payload below can be pre-filled with the
// seeker's real email (availed_services itself has no email column). Also
// joins services for requires_inspection: an inspection-required booking's
// 'accepted' status covers both the pre-inspection estimate and the
// post-agreement final price, and only the latter is payable — this is the
// server-side enforcement of the same gate seeker/my-requests.php's "Pay
// Now" button visibility uses, so this URL can't be hit directly (bookmarked,
// stale tab, etc.) to create a real PayMongo checkout for an estimate amount.
$bk = $db->prepare("SELECT a.*, p.company_name, p.id AS pid, u.email AS seeker_email
                    FROM availed_services a
                    JOIN providers p ON p.id = a.provider_id
                    JOIN users u ON u.id = :uid3
                    LEFT JOIN services s ON s.id = a.service_id
                    WHERE a.id = :id
                      AND (a.user_id = :uid OR a.seeker_user_id = :uid2)
                      AND (
                            (a.status IN ('accepted', 'waiting_provider_confirmation') AND a.payment_status = 'unpaid'
                             AND (COALESCE(s.requires_inspection, 0) = 0 OR a.inspection_agreed_at IS NOT NULL))
                            OR
                            (a.status = 'waiting_remaining_payment' AND a.payment_status = 'partial')
                          )");
$bk->execute([':id' => $booking_id, ':uid' => $user_id, ':uid2' => $user_id, ':uid3' => $user_id]);
$booking = $bk->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    // Already paid, wrong user, not payable, or (for an inspection-required
    // service) not yet agreed to a final price.
    $_SESSION['error'] = 'This booking is not available for payment.';
    header('Location: ' . $homeUrl); exit;
}

$is_remaining_payment = (
    strtolower((string)($booking['status'] ?? '')) === 'waiting_remaining_payment'
    && strtolower((string)($booking['payment_status'] ?? '')) === 'partial'
);

// Check if a checkout session already exists (created on accept)
$txn = $db->prepare("SELECT transaction_id FROM payment_transactions
                     WHERE availed_service_id = :id
                        AND payment_method = 'paymongo_checkout'
                       AND payment_type = :ptype
                        AND status = 'pending'
                     ORDER BY created_at DESC LIMIT 1");
$txn->execute([
    ':id' => $booking_id,
    ':ptype' => $is_remaining_payment ? 'remaining' : (($booking['payment_method'] === 'downpayment') ? 'downpayment' : 'full'),
]);
$txn_row    = $txn->fetch(PDO::FETCH_ASSOC);
$session_id = $txn_row['transaction_id'] ?? null;

$checkout_url = '';

// If we have a session ID, retrieve the checkout URL from PayMongo
if ($session_id && defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY) {
    $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/$session_id");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    $checkout_url = $data['data']['attributes']['checkout_url'] ?? '';
}

// If no checkout URL found, create a new one
if (!$checkout_url && defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY) {
    $pay_now  = $is_remaining_payment
                ? (float)$booking['remaining_amount']
                : (($booking['payment_method'] === 'downpayment')
                    ? (float)$booking['downpayment_amount']
                    : (float)$booking['total_amount']);
    $centavos = (int)round($pay_now * 100);
    $labelPrefix = $is_remaining_payment ? "Remaining Balance - Booking #{$booking_id}" : "Booking #{$booking_id}";
    $label    = $labelPrefix . ($booking['service_name'] ? " - {$booking['service_name']}" : '');

    // Pre-fills PayMongo's hosted checkout page's Contact Information fields with
    // what's already on file, so the seeker isn't asked to retype their phone/email.
    $billing = ['name' => $booking['full_name']];
    if (!empty($booking['seeker_email']))   $billing['email'] = $booking['seeker_email'];
    if (!empty($booking['contact_number'])) $billing['phone'] = $booking['contact_number'];

    $payload = ['data' => ['attributes' => [
        'billing'              => $billing,
        'send_email_receipt'   => false,
        'show_description'     => true,
        'show_line_items'      => true,
        'cancel_url'           => SITE_URL . "/payment-cancel.php?booking_id=$booking_id",
        'success_url'          => SITE_URL . "/payment-success.php?booking_id=$booking_id",
        'description'          => $label,
        'line_items'           => [[
            'currency' => 'PHP', 'amount' => $centavos,
            'name' => $booking['service_name'] ?: 'Pest Control Service', 'quantity' => 1,
        ]],
        'payment_method_types' => ['gcash', 'card', 'paymaya'],
        'metadata'             => ['booking_id' => $booking_id],
    ]]];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = json_decode($response, true);

    if ($http_code === 200 && isset($result['data']['attributes']['checkout_url'])) {
        $checkout_url = $result['data']['attributes']['checkout_url'];
        $new_sid      = $result['data']['id'];
        try {
            $db->prepare("INSERT INTO payment_transactions
                (availed_service_id, seeker_id, provider_id, amount, payment_type,
                 payment_method, transaction_id, status, created_at)
                VALUES (:aid,:sid,:pid,:amt,:ptype,'paymongo_checkout',:txn,'pending',NOW())")
              ->execute([
                ':aid'   => $booking_id,
                ':sid'   => $user_id,
                ':pid'   => $booking['pid'],
                ':amt'   => $pay_now,
                ':ptype' => $is_remaining_payment ? 'remaining' : (($booking['payment_method'] === 'downpayment') ? 'downpayment' : 'full'),
                ':txn'   => $new_sid,
            ]);
        } catch (Exception $e) {}
    }
}

// Redirect to PayMongo if we have a URL
if ($checkout_url) {
    header("Location: $checkout_url"); exit;
}

// Fallback — show page with booking details if PayMongo unavailable
$pay_now = $is_remaining_payment
           ? (float)$booking['remaining_amount']
           : (($booking['payment_method'] === 'downpayment')
               ? (float)$booking['downpayment_amount']
               : (float)$booking['total_amount']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pay for Booking · Pestify</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,600;9..40,700;9..40,800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:linear-gradient(135deg,#f0fdf4,#dcfce7);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:24px;padding:44px 40px;max-width:480px;width:100%;text-align:center;box-shadow:0 12px 48px rgba(0,0,0,.12)}
.icon-wrap{width:80px;height:80px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 22px;font-size:34px;color:#fff}
h1{font-size:22px;font-weight:800;color:#14532d;margin-bottom:8px}
.sub{font-size:14px;color:#6b7280;line-height:1.7;margin-bottom:24px}
.details{background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:18px 20px;margin-bottom:28px;text-align:left}
.dr{display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:7px 0;border-bottom:1px solid #dcfce7}
.dr:last-child{border-bottom:none;padding-top:12px;margin-top:4px}
.dr .lbl{color:#6b7280} .dr .val{font-weight:600;color:#1a2744}
.dr.total .val{font-size:17px;font-weight:800;color:#16a34a}
.err-note{background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:14px 16px;font-size:13px;color:#991b1b;margin-bottom:20px;text-align:left}
.btn{display:inline-flex;align-items:center;gap:7px;padding:13px 24px;border-radius:10px;font-size:14px;font-weight:700;font-family:inherit;text-decoration:none;transition:all .2s;border:none;cursor:pointer}
.btn-green{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff}
.btn-ghost{background:#f1f5f9;color:#475569}
</style>
</head>
<body>
<div class="card">
    <div class="icon-wrap"><i class="fas fa-credit-card"></i></div>
    <h1>Complete Your Payment</h1>
    <p class="sub">Your service request has been <strong>accepted</strong>! Complete payment to confirm your booking.</p>
    <div class="details">
        <div class="dr"><span class="lbl">Booking #</span><span class="val">#<?= $booking_id ?></span></div>
        <div class="dr"><span class="lbl">Provider</span><span class="val"><?= htmlspecialchars($booking['company_name']) ?></span></div>
        <div class="dr"><span class="lbl">Service</span><span class="val"><?= htmlspecialchars($booking['service_name'] ?? '—') ?></span></div>
        <div class="dr"><span class="lbl">Date</span><span class="val"><?= date('M j, Y', strtotime($booking['preferred_date'])) ?></span></div>
        <div class="dr"><span class="lbl">Time</span><span class="val"><?= date('h:i A', strtotime($booking['preferred_time'])) ?></span></div>
        <div class="dr total"><span class="lbl">Amount Due</span><span class="val">₱<?= number_format($pay_now, 2) ?></span></div>
    </div>
    <div class="err-note"><i class="fas fa-exclamation-circle"></i> Payment gateway is temporarily unavailable. Please contact the provider directly or try again later.</div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a href="<?= htmlspecialchars($providerDetailsUrl . '?id=' . (int)$booking['pid']) ?>" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Provider</a>
        <a href="<?= $_SERVER['REQUEST_URI'] ?>" class="btn btn-green"><i class="fas fa-redo"></i> Try Again</a>
    </div>
</div>
</body></html>
