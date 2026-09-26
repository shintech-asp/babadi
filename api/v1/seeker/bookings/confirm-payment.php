<?php
// POST api/v1/seeker/bookings/confirm-payment.php
// Called by Flutter after the PayMongo WebView closes (success redirect detected).
// Verifies the payment with PayMongo, updates the booking status, and generates
// control numbers — the same work that payment-success-result.php does on the web,
// but authenticated via JWT instead of a PHP session.
//
// Safe to call alongside the web flow: generateAndDistribute() is idempotent,
// and the DB updates are guarded by status checks.

require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/ControlNumberService.php';

allow('GET', 'POST');

$user       = require_seeker();
$booking_id = (int)inp('booking_id');
if ($booking_id <= 0) fail('booking_id is required.');

$pdo = db();
$uid = (int)$user['id'];

// 1. Fetch the booking — must belong to this seeker
$bStmt = $pdo->prepare(
    "SELECT a.*, p.user_id AS provider_user_id
     FROM availed_services a
     JOIN providers p ON a.provider_id = p.id
     WHERE a.id = :bid AND (a.seeker_user_id = :uid OR a.user_id = :uid2)
     LIMIT 1"
);
$bStmt->execute([':bid' => $booking_id, ':uid' => $uid, ':uid2' => $uid]);
$booking = $bStmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) fail('Booking not found.', 404);

// 2. Fetch the most recent payment transaction for this booking
$ptStmt = $pdo->prepare(
    "SELECT * FROM payment_transactions
     WHERE availed_service_id = :bid AND seeker_id = :uid
     ORDER BY created_at DESC LIMIT 1"
);
$ptStmt->execute([':bid' => $booking_id, ':uid' => $uid]);
$ptData = $ptStmt->fetch(PDO::FETCH_ASSOC);

if (!$ptData || empty($ptData['transaction_id'])) {
    ok(['data' => ['verified' => false, 'booking' => ['id' => $booking_id, 'status' => $booking['status']]]]);
}

// 3. Verify payment status with PayMongo
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
    CURLOPT_TIMEOUT => 10,
]);
$pmRaw  = curl_exec($ch);
curl_close($ch);
$pmData = json_decode($pmRaw, true);

$pmStatus = $pmData['data']['attributes']['payment_intent']['attributes']['status']
         ?? $pmData['data']['attributes']['status']
         ?? '';

$verified = in_array($pmStatus, ['succeeded', 'paid', 'active'], true);

if (!$verified) {
    ok(['data' => ['verified' => false, 'booking' => ['id' => $booking_id, 'status' => $booking['status']]]]);
}

// 4. Update booking + transaction records (idempotent — guards prevent double-write)
$ptType    = strtolower(trim((string)($ptData['payment_type'] ?? '')));
$curStatus = strtolower(trim((string)($booking['status'] ?? '')));

$isRemaining      = ($curStatus === 'waiting_for_remaining_payment') || ($ptType === 'remaining');
$alreadyProcessed = ($ptData['status'] === 'completed')
    && !($isRemaining && $curStatus === 'waiting_for_remaining_payment');

if (!$alreadyProcessed) {
    $isDP     = ($booking['payment_method'] === 'downpayment');
    $newPStat = $isRemaining ? 'paid' : ($isDP ? 'partial' : 'paid');
    $paidAmt  = $isRemaining
        ? (float)$booking['total_amount']
        : ($isDP ? (float)$booking['downpayment_amount'] : (float)$booking['total_amount']);
    $nextStat = $isRemaining ? 'waiting_for_provider_confirmation' : 'preparing';

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE availed_services
             SET payment_status = :ps,
                 paid_amount    = :pa,
                 status         = CASE WHEN status IN ('completed','cancelled') THEN status ELSE :st END,
                 updated_at     = NOW()
             WHERE id = :bid AND (seeker_user_id = :uid OR user_id = :uid2)"
        )->execute([
            ':ps' => $newPStat, ':pa' => $paidAmt, ':st' => $nextStat,
            ':bid' => $booking_id, ':uid' => $uid, ':uid2' => $uid,
        ]);

        $pdo->prepare(
            "UPDATE payment_transactions
             SET status = 'completed', updated_at = NOW()
             WHERE availed_service_id = :bid AND seeker_id = :uid AND status = 'pending'"
        )->execute([':bid' => $booking_id, ':uid' => $uid]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('[confirm-payment] DB update failed: ' . $e->getMessage());
        fail('Payment verified but record update failed. Please contact support.', 500);
    }
}

// 5. Generate / fetch control numbers (idempotent — returns existing codes if already generated)
$cnResult = (new ControlNumberService($pdo))->generateAndDistribute(
    $booking_id,
    $uid,
    (int)($booking['provider_user_id'] ?? 0)
);

// 6. Re-fetch booking so status + CNs reflect the updated state
$bStmt->execute([':bid' => $booking_id, ':uid' => $uid, ':uid2' => $uid]);
$fresh = $bStmt->fetch(PDO::FETCH_ASSOC);

ok([
    'data' => [
        'verified' => true,
        'booking'  => [
            'id'                      => (int)$fresh['id'],
            'status'                  => $fresh['status'],
            'payment_status'          => $fresh['payment_status'],
            'paid_amount'             => (float)$fresh['paid_amount'],
            // Cross-share: seeker keeps provider_control_number to enter on service day
            'control_number'          => $fresh['control_number'],           // PCF-… seeker's own code
            'provider_control_number' => $fresh['provider_control_number'],  // PCP-… seeker must enter this
        ],
    ],
]);
