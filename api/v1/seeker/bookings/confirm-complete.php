<?php
// POST api/v1/seeker/bookings/confirm-complete.php
// Seeker confirms the service was completed satisfactorily.
//
// Status literal note: the canonical mobile FSM (includes/booking_workflow_helper.php)
// uses 'waiting_for_seeker_confirmation' for the full-payment seeker-confirm step.
// The web app (seeker/my-requests.php) normalizes several legacy spellings —
// 'waiting_seeker_information', 'waiting_seeker_confirmation', and a fully-paid
// 'waiting_remaining_payment' row — into 'waiting_provider_confirmation' (note:
// NO "for", distinct from the canonical 'waiting_for_provider_confirmation') and
// finalizes seeker confirmation from there. Since the web app is currently the
// only thing driving bookings through this stage, we must accept both families.
//
// We deliberately do NOT accept the canonical 'waiting_for_provider_confirmation'
// (WITH "for") — that value is written by confirm-payment.php after a downpayment's
// remaining balance is paid, and per the FSM it means the PROVIDER must confirm,
// not the seeker.
//
// A THIRD, unrelated meaning: the provider-portal CRM (provider-portal/crm-bookings.php)
// writes the exact same 'waiting_provider_confirmation' string to mean "just
// accepted a pending request" — an early, pre-service state, not "ready to
// finalize". To avoid a seeker completing a booking the moment a portal staffer
// accepts it, we additionally require dual_verified_at to be set for the
// ambiguous legacy spellings — that column is only ever stamped when a
// booking reaches 'starting' via the seeker+provider control-number
// handshake, which a freshly-accepted booking could not have done yet.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user = require_seeker();
$uid  = (int)$user['id'];
$id   = (int)req_inp('avail_id', 'Booking ID');

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT id, provider_id, status, payment_status, remaining_amount, dual_verified_at
     FROM availed_services
     WHERE id = :id AND (seeker_user_id = :uid OR user_id = :uid2)'
);
$stmt->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

$status  = strtolower(trim((string)$booking['status']));
$payStat = strtolower(trim((string)$booking['payment_status']));
// MySQL can store the zero-date sentinel instead of NULL on older rows —
// same check as admin/service-requests.php's $isRealTimestamp().
$dualVerifiedAt = trim((string)($booking['dual_verified_at'] ?? ''));
$hasStarted = $dualVerifiedAt !== '' && $dualVerifiedAt !== '0000-00-00 00:00:00';

$ambiguousSeekerConfirmStatuses = [
    'waiting_provider_confirmation', // legacy web spelling — ALSO used by the portal CRM to mean "just accepted"
    'waiting_seeker_information',
    'waiting_seeker_confirmation',
];
$canFinalize = $status === 'waiting_for_seeker_confirmation' // canonical (mobile FSM) — unambiguous
    || (in_array($status, $ambiguousSeekerConfirmStatuses, true) && $hasStarted)
    || (in_array($status, ['waiting_remaining_payment', 'waiting_for_remaining_payment'], true) && $payStat === 'paid');

if ($status === 'completed') {
    fail('This booking has already been completed.', 422);
}

if (!$canFinalize) {
    fail('This booking is not awaiting your confirmation.', 422);
}

$remaining = (float)($booking['remaining_amount'] ?? 0);
if ($payStat === 'partial' && $remaining > 0.009) {
    fail('Please settle the remaining balance before confirming completion.', 422);
}

$upd = $pdo->prepare(
    "UPDATE availed_services
     SET status = 'completed',
         payment_status = CASE WHEN payment_status IN ('paid', 'partial') THEN 'paid' ELSE payment_status END,
         seeker_confirmed_at = NOW(),
         seeker_satisfaction_confirmed_at = NOW(),
         is_read = 0,
         updated_at = NOW()
     WHERE id = :id AND status NOT IN ('completed', 'cancelled')"
);
$upd->execute([':id' => $id]);

if ($upd->rowCount() === 0) {
    fail('This booking could not be completed — it may have just changed status.', 409);
}

try {
    $pdo->prepare(
        'INSERT INTO availed_service_status_history
            (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
         VALUES (:aid, :old, :new, :uid, \'seeker\', :notes, NOW())'
    )->execute([
        ':aid'   => $id,
        ':old'   => $booking['status'],
        ':new'   => 'completed',
        ':uid'   => $uid,
        ':notes' => 'Seeker confirmed service satisfactory.',
    ]);
} catch (Exception $e) { /* non-fatal */ }

try {
    $providerUserStmt = $pdo->prepare('SELECT user_id FROM providers WHERE id = :pid LIMIT 1');
    $providerUserStmt->execute([':pid' => $booking['provider_id']]);
    $providerUserId = (int)($providerUserStmt->fetchColumn() ?: 0);
    if ($providerUserId) {
        $pdo->prepare(
            "INSERT INTO notifications
                (user_id, type, title, message, related_id, related_type, is_read, created_at)
             VALUES (:uid, 'request', 'Booking Completed', :message, :related_id, 'availed_service', 0, NOW())"
        )->execute([
            ':uid'        => $providerUserId,
            ':message'    => "Booking #$id was confirmed complete by the seeker.",
            ':related_id' => $id,
        ]);
    }
} catch (Exception $e) { /* non-fatal */ }

ok(['data' => ['id' => $id, 'status' => 'completed']]);
