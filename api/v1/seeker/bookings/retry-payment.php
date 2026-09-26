<?php
// POST api/v1/seeker/bookings/retry-payment.php
// Re-opens a PayMongo checkout session for a booking whose initial payment
// never completed (e.g. the checkout session failed to create at store.php
// time, or the seeker cancelled the PayMongo page). Mirrors remaining-payment.php.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user       = require_seeker();
$booking_id = (int)req_inp('booking_id', 'Booking ID');

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT av.*, p.user_id AS provider_user_id, COALESCE(sv.requires_inspection, 0) AS requires_inspection
     FROM availed_services av
     JOIN providers p ON av.provider_id = p.id
     LEFT JOIN services sv ON sv.id = av.service_id
     WHERE av.id = :id AND (av.seeker_user_id = :uid OR av.user_id = :uid2)
     LIMIT 1'
);
$stmt->execute([':id' => $booking_id, ':uid' => $user['id'], ':uid2' => $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

if (!in_array($booking['status'], ['pending', 'accepted'], true) || $booking['payment_status'] !== 'unpaid') {
    fail('This booking does not need an initial payment retry. Current status: ' . $booking['status'], 422);
}

// An inspection-required service's 'accepted' status covers both the
// pre-inspection estimate and the post-agreement final price — only the
// latter (inspection_agreed_at set) is payable. Same gate as the web's
// seeker/payment-redirect.php, enforced here server-side so this endpoint
// can't be called directly to start a real checkout for an estimate amount.
if (!empty($booking['requires_inspection']) && empty($booking['inspection_agreed_at'])) {
    fail('This booking is still awaiting an on-site inspection before a final price is set.', 422);
}

$isDownpayment = $booking['payment_method'] === 'downpayment';
$payNow        = $isDownpayment ? (float)$booking['downpayment_amount'] : (float)$booking['total_amount'];
if ($payNow <= 0) {
    $payNow = (float)$booking['total_amount'];
}
if ($payNow <= 0) {
    fail('Invalid total amount for this booking.', 422);
}

$centavos     = (int)round($payNow * 100);
$service_name = $booking['service_name'] ?? 'Pest Control Service';
$label        = "Booking #$booking_id — $service_name";
$success_url  = SITE_URL . "/payment-success.php?booking_id=$booking_id";
$cancel_url   = SITE_URL . "/payment-cancel.php?booking_id=$booking_id";

$full_name      = $booking['full_name'] ?? trim($user['first_name'] . ' ' . $user['last_name']);
$contact_number = $booking['contact_number'] ?? $user['phone'] ?? '';

$billing = ['name' => $full_name];
if (!empty($user['email'])) $billing['email'] = $user['email'];
if ($contact_number)        $billing['phone'] = $contact_number;

$pm_payload = ['data' => ['attributes' => [
    'billing'              => $billing,
    'send_email_receipt'   => false,
    'show_description'     => true,
    'show_line_items'      => true,
    'cancel_url'           => $cancel_url,
    'success_url'          => $success_url,
    'description'          => $label,
    'line_items'           => [[
        'currency' => 'PHP',
        'amount'   => $centavos,
        'name'     => $service_name,
        'quantity' => 1,
    ]],
    'payment_method_types' => ['gcash', 'card', 'paymaya'],
    'metadata'             => [
        'booking_id'     => $booking_id,
        'provider_id'    => (int)$booking['provider_id'],
        'payment_method' => $booking['payment_method'],
    ],
]]];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($pm_payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ],
    CURLOPT_TIMEOUT        => 15,
]);
$pm_response  = curl_exec($ch);
$pm_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$pm_result = json_decode($pm_response, true);

if ($pm_http_code !== 200 || empty($pm_result['data']['attributes']['checkout_url'])) {
    $pm_err = $pm_result['errors'][0]['detail']
           ?? $pm_result['errors'][0]['code']
           ?? 'PayMongo unavailable';
    error_log("[retry-payment.php] PayMongo error for Booking #$booking_id HTTP $pm_http_code: $pm_err | $pm_response");
    fail('Could not initiate payment: ' . $pm_err, 502);
}

$checkout_url = $pm_result['data']['attributes']['checkout_url'];
$session_id   = $pm_result['data']['id'];

try {
    // Void any stale pending transactions from earlier attempts on this booking
    // so confirm-payment.php's blanket "status='pending'" completion update
    // can't mark more than one row completed for a single real payment.
    $pdo->beginTransaction();
    $pdo->prepare(
        "UPDATE payment_transactions SET status = 'failed', updated_at = NOW()
         WHERE availed_service_id = :aid AND status = 'pending'"
    )->execute([':aid' => $booking_id]);

    $pdo->prepare(
        'INSERT INTO payment_transactions
            (availed_service_id, seeker_id, provider_id, amount, payment_type,
             payment_method, transaction_id, status, created_at)
         VALUES (:aid, :sid, :pid, :amt, :ptype, \'paymongo_checkout\', :txn, \'pending\', NOW())'
    )->execute([
        ':aid'   => $booking_id,
        ':sid'   => $user['id'],
        ':pid'   => (int)$booking['provider_id'],
        ':amt'   => $payNow,
        ':ptype' => $isDownpayment ? 'downpayment' : 'full',
        ':txn'   => $session_id,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[retry-payment.php] Transaction record failed for booking #' . $booking_id . ': ' . $e->getMessage());
    fail('Could not start the payment. Please try again.', 500);
}

ok([
    'data' => [
        'booking_id'   => $booking_id,
        'amount'       => $payNow,
        'checkout_url' => $checkout_url,
    ],
]);
