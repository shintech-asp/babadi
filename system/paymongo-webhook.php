<?php
$appRoot = dirname(__DIR__);
chdir($appRoot);
/**
 * paymongo-webhook.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Pestify – PayMongo Webhook Handler (with Dual Control Number generation)
 *
 * PLACEMENT:  /pestify/paymongo-webhook.php   (public, no session needed)
 * REGISTER:   Add this URL in PayMongo Dashboard → Webhooks
 * EVENTS:     payment.paid  (and optionally checkout_session.payment.paid)
 *
 * WHAT THIS FILE DOES:
 *  1. Validates PayMongo webhook signature (HMAC-SHA256)
 *  2. Extracts the booking ID from the payment description / reference
 *  3. Marks the booking as `preparing` and payment_status as paid/partial
 *  4. Calls ControlNumberService::generateAndDistribute() to:
 *       - Generate PCF (seeker) + PCP (provider) codes
 *       - Send CROSS-SHARED notifications:
 *           Seeker  → receives PCP code
 *           Provider → receives PCF code
 *  5. Returns HTTP 200 so PayMongo stops retrying
 *
 * ENVIRONMENT VARIABLES (set in config.php):
 *   PAYMONGO_WEBHOOK_SECRET   – your webhook signing secret
 *   PAYMONGO_SECRET_KEY       – your API secret key
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once $appRoot . '/config/config.php';
require_once $appRoot . '/config/database.php';
require_once $appRoot . '/includes/ControlNumberService.php';
require_once $appRoot . '/includes/payment_receipt_helper.php';

// Silence any accidental output that would corrupt the HTTP body
ob_start();

// ── Read raw body & headers ───────────────────────────────────────────────────
$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// ── Signature Validation ──────────────────────────────────────────────────────
if (!validatePaymongoSignature($rawBody, $signature, PAYMONGO_WEBHOOK_SECRET)) {
    http_response_code(401);
    exit('Unauthorized');
}

// ── Parse event ───────────────────────────────────────────────────────────────
$event = json_decode($rawBody, true);
if (!$event || !isset($event['data']['attributes'])) {
    http_response_code(400);
    exit('Bad payload');
}

$eventType = $event['data']['attributes']['type'] ?? '';

// We only process payment success events
$paymentSuccessEvents = ['payment.paid', 'checkout_session.payment.paid', 'link.payment.paid'];
if (!in_array($eventType, $paymentSuccessEvents, true)) {
    http_response_code(200);
    exit('Ignored event type');
}

// ── Extract booking ID ────────────────────────────────────────────────────────
// When we create the PayMongo link, we store "BOOKING-{id}" as the reference.
$attrs       = $event['data']['attributes']['data']['attributes'] ?? [];
$description = $attrs['description'] ?? '';
$referenceId = $attrs['reference_number'] ?? $attrs['remarks'] ?? '';

$bookingId = 0;
// Try reference_number first ("BOOKING-12")
if (preg_match('/BOOKING-(\d+)/i', $referenceId, $m)) {
    $bookingId = (int)$m[1];
} elseif (preg_match('/Booking\s*#?(\d+)/i', $description, $m)) {
    $bookingId = (int)$m[1];
}

if (!$bookingId) {
    // Could not determine booking – log and acknowledge
    error_log("[Webhook] Could not extract booking ID. Ref: $referenceId | Desc: $description");
    http_response_code(200);
    exit('Could not map payment to booking');
}

// ── Database setup ────────────────────────────────────────────────────────────
$database = new Database();
$db       = $database->getConnection();

// ── Load booking ──────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT a.id, a.provider_id, a.seeker_user_id, a.service_name,
            a.payment_method, a.total_amount, a.downpayment_amount,
            a.payment_status, a.status,
            a.control_number, a.provider_control_number,
            p.user_id AS provider_user_id
     FROM availed_services a
     JOIN providers p ON a.provider_id = p.id
     WHERE a.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    error_log("[Webhook] Booking #$bookingId not found.");
    http_response_code(200);
    exit('Booking not found');
}

// Detect if this webhook corresponds to a remaining-balance payment.
$pendingPaymentType = '';
try {
    $pt = $db->prepare(
        "SELECT payment_type
         FROM payment_transactions
         WHERE availed_service_id = :id
           AND status = 'pending'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $pt->execute([':id' => $bookingId]);
    $pendingPaymentType = strtolower(trim((string)($pt->fetchColumn() ?: '')));
} catch (Exception $e) {
    $pendingPaymentType = '';
}
$currentBookingStatus = strtolower(trim((string)($booking['status'] ?? '')));
$isRemainingPaymentWebhook = ($pendingPaymentType === 'remaining')
    || ($currentBookingStatus === 'waiting_for_remaining_payment');

// Idempotency: if already marked paid (or already partial with no remaining pending txn), do nothing.
if (!$isRemainingPaymentWebhook && in_array($booking['payment_status'], ['paid', 'partial'], true)) {
    http_response_code(200);
    exit('Already processed');
}

// ── Mark payment as complete ───────────────────────────────────────────────────
try {
    $db->beginTransaction();

    $isDownpayment = ($booking['payment_method'] === 'downpayment');
    $newPayStatus  = $isRemainingPaymentWebhook
        ? 'paid'
        : ($isDownpayment ? 'partial' : 'paid');
    $paidAmount    = $isRemainingPaymentWebhook
        ? (float)$booking['total_amount']
        : ($isDownpayment
            ? (float)$booking['downpayment_amount']
            : (float)$booking['total_amount']);
    $nextStatus    = $isRemainingPaymentWebhook
        ? 'waiting_for_provider_confirmation'
        : 'preparing';

    // Update availed_services
    $db->prepare(
        "UPDATE availed_services
         SET payment_status = :ps,
             paid_amount    = :pa,
             status         = CASE
                                WHEN status IN ('completed', 'cancelled') THEN status
                                ELSE :st
                              END,
             updated_at     = NOW()
         WHERE id = :id
           AND payment_status IN ('unpaid', 'partial')"
    )->execute([':ps' => $newPayStatus, ':pa' => $paidAmount, ':st' => $nextStatus, ':id' => $bookingId]);

    // Update payment_transactions
    $db->prepare(
        "UPDATE payment_transactions
         SET status = 'completed', updated_at = NOW()
         WHERE availed_service_id = :id AND status = 'pending'"
    )->execute([':id' => $bookingId]);

    // Status history
    $db->prepare(
        "INSERT INTO availed_service_status_history
            (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
         VALUES (:aid, :old, :new, NULL, 'system', :notes, NOW())"
    )->execute([
        ':aid'   => $bookingId,
        ':old'   => $booking['status'],
        ':new'   => $nextStatus,
        ':notes' => "Payment confirmed via PayMongo webhook. Status: $newPayStatus.",
    ]);

    $db->commit();

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("[Webhook] Payment DB update failed for Booking #$bookingId: " . $e->getMessage());
    http_response_code(500);
    exit('DB error');
}

syncCompletedReceiptsForBooking($db, $bookingId);

// ── Generate & distribute dual control numbers ────────────────────────────────
$cns    = new ControlNumberService($db);
$result = $cns->generateAndDistribute(
    availedId       : $bookingId,
    seekerUserId    : (int)$booking['seeker_user_id'],
    providerUserId  : (int)$booking['provider_user_id']
);

if (!$result['success']) {
    // Non-fatal – payment already recorded; log the CN failure
    error_log("[Webhook] CN generation failed for Booking #$bookingId: " . $result['error']);
}

// ── Acknowledge ───────────────────────────────────────────────────────────────
ob_end_clean();
http_response_code(200);
echo json_encode(['received' => true, 'booking_id' => $bookingId]);
exit;


// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Validate PayMongo webhook signature.
 * PayMongo signs with HMAC-SHA256; the header contains three timestamps and
 * a signature: "t=1234567890,te=...,li=...,wh=<hex-digest>"
 *
 * Docs: https://developers.paymongo.com/docs/webhook-signature
 */
function validatePaymongoSignature(string $body, string $header, string $secret): bool
{
    if (!$header || !$secret) return false;

    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k] = $v;
    }

    $timestamp = $parts['t'] ?? '';
    $wh        = $parts['wh'] ?? '';  // webhook signature

    if (!$timestamp || !$wh) return false;

    // Signed payload = timestamp + "." + body
    $signed   = $timestamp . '.' . $body;
    $expected = hash_hmac('sha256', $signed, $secret);

    return hash_equals($expected, $wh);
}
