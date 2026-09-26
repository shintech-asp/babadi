<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user       = require_seeker();
$booking_id = (int)req_inp('booking_id', 'Booking ID');

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT av.*, p.user_id AS provider_user_id
     FROM availed_services av
     JOIN providers p ON av.provider_id = p.id
     WHERE av.id = :id AND (av.seeker_user_id = :uid OR av.user_id = :uid2)
     LIMIT 1'
);
$stmt->execute([':id' => $booking_id, ':uid' => $user['id'], ':uid2' => $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

// 'waiting_remaining_payment' (no "for") is the legacy web spelling for the
// same state — seeker/my-requests.php:346 still writes it.
if (!in_array($booking['status'], ['waiting_for_remaining_payment', 'waiting_remaining_payment'], true)) {
    fail('This booking does not require a remaining payment at this time. Current status: ' . $booking['status'], 422);
}

if (strtolower(trim((string)$booking['payment_status'])) === 'paid') {
    fail('This booking\'s balance is already paid.', 422);
}

$remaining = (float)$booking['remaining_amount'];
if ($remaining <= 0) {
    fail('No remaining balance to pay for this booking.', 422);
}

$centavos     = (int)round($remaining * 100);
$service_name = $booking['service_name'] ?? 'Pest Control Service';
$label        = "Remaining Balance – Booking #$booking_id";
$success_url  = SITE_URL . "/payment-success.php?booking_id=$booking_id";
$cancel_url   = SITE_URL . "/payment-cancel.php?booking_id=$booking_id";

$full_name      = $booking['full_name'] ?? trim($user['first_name'] . ' ' . $user['last_name']);
$contact_number = $booking['contact_number'] ?? $user['phone'] ?? '';

$billing = ['name' => $full_name];
if (!empty($user['email']))  $billing['email'] = $user['email'];
if ($contact_number)         $billing['phone'] = $contact_number;

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
        'name'     => 'Remaining Balance – ' . $service_name,
        'quantity' => 1,
    ]],
    'payment_method_types' => ['gcash', 'card', 'paymaya'],
    'metadata'             => [
        'booking_id'     => $booking_id,
        'provider_id'    => (int)$booking['provider_id'],
        'payment_method' => 'remaining',
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
    error_log("[remaining-payment.php] PayMongo error for Booking #$booking_id HTTP $pm_http_code: $pm_err | $pm_response");
    fail('Could not initiate payment: ' . $pm_err, 502);
}

$checkout_url = $pm_result['data']['attributes']['checkout_url'];
$session_id   = $pm_result['data']['id'];

// Log transaction as type 'remaining' — the webhook uses this to detect remaining-balance payments.
// Void any stale pending transactions from earlier attempts first, so
// confirm-payment.php's blanket "status='pending'" completion update can't
// mark more than one row completed for a single real payment.
try {
    $pdo->beginTransaction();
    $pdo->prepare(
        "UPDATE payment_transactions SET status = 'failed', updated_at = NOW()
         WHERE availed_service_id = :aid AND status = 'pending'"
    )->execute([':aid' => $booking_id]);

    $pdo->prepare(
        'INSERT INTO payment_transactions
            (availed_service_id, seeker_id, provider_id, amount, payment_type,
             payment_method, transaction_id, status, created_at)
         VALUES (:aid, :sid, :pid, :amt, \'remaining\', \'paymongo_checkout\', :txn, \'pending\', NOW())'
    )->execute([
        ':aid' => $booking_id,
        ':sid' => $user['id'],
        ':pid' => (int)$booking['provider_id'],
        ':amt' => $remaining,
        ':txn' => $session_id,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[remaining-payment.php] Transaction record failed for booking #' . $booking_id . ': ' . $e->getMessage());
    fail('Could not start the payment. Please try again.', 500);
}

ok([
    'data' => [
        'booking_id'   => $booking_id,
        'amount'       => $remaining,
        'checkout_url' => $checkout_url,
    ],
]);
