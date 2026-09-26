<?php
// POST api/v1/seeker/bookings/reschedule-respond.php
// Seeker accepts or rejects a reschedule proposal made by the provider.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$user     = require_seeker();
$uid      = (int)$user['id'];
$id       = (int)req_inp('avail_id', 'Booking ID');
$decision = strtolower(trim(req_inp('decision', 'Decision')));

if (!in_array($decision, ['accept', 'reject'], true)) {
    fail('decision must be "accept" or "reject".');
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT a.id, a.status, a.provider_id, a.reschedule_request_status,
            a.reschedule_proposed_date, a.reschedule_proposed_time,
            p.user_id AS provider_user_id
     FROM availed_services a
     JOIN providers p ON p.id = a.provider_id
     WHERE a.id = :id AND (a.seeker_user_id = :uid OR a.user_id = :uid2)
     LIMIT 1'
);
$stmt->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}

if (strtolower(trim((string)$booking['reschedule_request_status'])) !== 'pending'
    || empty($booking['reschedule_proposed_date'])
    || empty($booking['reschedule_proposed_time'])) {
    fail('There is no pending reschedule request for this booking.', 422);
}

if (in_array($booking['status'], ['completed', 'cancelled'], true)) {
    fail('This booking is already ' . $booking['status'] . '.', 422);
}

if ($decision === 'accept') {
    $upd = $pdo->prepare(
        'UPDATE availed_services
         SET preferred_date = :new_date,
             preferred_time = :new_time,
             reschedule_request_status = NULL,
             reschedule_requested_at = NULL,
             reschedule_proposed_date = NULL,
             reschedule_proposed_time = NULL,
             reschedule_reason = NULL,
             reschedule_responded_at = NOW(),
             provider_verified_at = NULL,
             seeker_verified_at = NULL,
             dual_verified_at = NULL,
             is_read = 0,
             updated_at = NOW()
         WHERE id = :id
           AND reschedule_request_status = \'pending\'
           AND status NOT IN (\'completed\', \'cancelled\')'
    );
    $upd->execute([
        ':new_date' => $booking['reschedule_proposed_date'],
        ':new_time' => $booking['reschedule_proposed_time'],
        ':id'       => $id,
    ]);
    $notifTitle   = 'Reschedule Accepted';
    $notifMessage = "Booking #$id reschedule accepted by the seeker. New schedule: "
        . date('M j, Y', strtotime((string)$booking['reschedule_proposed_date']))
        . ' at ' . date('g:i A', strtotime((string)$booking['reschedule_proposed_time'])) . '.';
    $historyNote = 'Seeker accepted the provider reschedule request.';
} else {
    $upd = $pdo->prepare(
        "UPDATE availed_services
         SET reschedule_request_status = 'rejected',
             reschedule_responded_at = NOW(),
             is_read = 0,
             updated_at = NOW()
         WHERE id = :id
           AND reschedule_request_status = 'pending'
           AND status NOT IN ('completed', 'cancelled')"
    );
    $upd->execute([':id' => $id]);
    $notifTitle   = 'Reschedule Declined';
    $notifMessage = "Booking #$id reschedule request was declined by the seeker.";
    $historyNote  = 'Seeker declined the provider reschedule request.';
}

if ($upd->rowCount() === 0) {
    fail('There is no pending reschedule request for this booking.', 422);
}

try {
    $pdo->prepare(
        'INSERT INTO availed_service_status_history
            (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
         VALUES (:aid, :status, :status, :uid, \'seeker\', :notes, NOW())'
    )->execute([
        ':aid'    => $id,
        ':status' => $booking['status'],
        ':uid'    => $uid,
        ':notes'  => $historyNote,
    ]);
} catch (Exception $e) { /* non-fatal */ }

try {
    if (!empty($booking['provider_user_id'])) {
        $pdo->prepare(
            "INSERT INTO notifications
                (user_id, type, title, message, related_id, related_type, is_read, created_at)
             VALUES (:uid, 'request', :title, :message, :related_id, 'availed_service', 0, NOW())"
        )->execute([
            ':uid'        => (int)$booking['provider_user_id'],
            ':title'      => $notifTitle,
            ':message'    => $notifMessage,
            ':related_id' => $id,
        ]);
    }
} catch (Exception $e) { /* non-fatal */ }

ok(['data' => ['id' => $id, 'decision' => $decision]]);
