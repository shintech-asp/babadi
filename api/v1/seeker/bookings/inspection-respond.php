<?php
// POST api/v1/seeker/bookings/inspection-respond.php
// Seeker agrees to the technician's proposed working date + final price
// (locks them in, re-enters the normal accepted -> preparing pipeline) or
// requests changes (-> 'revising', looping back to the provider for a new
// report). Mobile counterpart of seeker/my-requests.php's Agree & Schedule /
// Request Changes buttons — see CLAUDE.md's "Recent Work Log".
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/availed_booking_helper.php';
require_once 'config/send_email.php';

allow('POST');

$user     = require_seeker();
$uid      = (int)$user['id'];
$id       = (int)req_inp('avail_id', 'Booking ID');
$decision = strtolower(trim(req_inp('decision', 'Decision')));

if (!in_array($decision, ['agree', 'request_changes'], true)) {
    fail('decision must be "agree" or "request_changes".');
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT a.id, a.status, a.provider_id, a.payment_method, a.downpayment_amount,
            a.inspection_proposed_price, a.inspection_proposed_working_date,
            p.user_id AS provider_user_id, pu.email AS provider_email, pu.first_name AS provider_first_name
     FROM availed_services a
     JOIN providers p ON p.id = a.provider_id
     LEFT JOIN users pu ON pu.id = p.user_id
     WHERE a.id = :id AND (a.seeker_user_id = :uid OR a.user_id = :uid2)
     LIMIT 1'
);
$stmt->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}
if ($booking['status'] !== 'awaiting_agreement') {
    fail('This booking is not currently awaiting your inspection decision.', 422);
}

if ($decision === 'agree') {
    if (empty($booking['inspection_proposed_working_date']) || (float)$booking['inspection_proposed_price'] <= 0) {
        fail('The inspection report is missing required details.', 422);
    }

    $finalPrice    = (float)$booking['inspection_proposed_price'];
    $isDownpayment = ($booking['payment_method'] === 'downpayment');
    $dp = $isDownpayment ? min((float)$booking['downpayment_amount'], $finalPrice) : 0;
    if ($isDownpayment && $dp <= 0) { $dp = round($finalPrice * 0.5, 2); }
    $remaining = $isDownpayment ? round($finalPrice - $dp, 2) : 0;

    $upd = $pdo->prepare(
        'UPDATE availed_services
         SET working_date = :wdate,
             preferred_date = :wdate2,
             total_amount = :amt,
             downpayment_amount = :dp,
             remaining_amount = :rem,
             inspection_agreed_at = NOW(),
             status = \'accepted\',
             is_read = 0,
             updated_at = NOW()
         WHERE id = :id AND status = \'awaiting_agreement\''
    );
    $upd->execute([
        ':wdate'  => $booking['inspection_proposed_working_date'],
        ':wdate2' => $booking['inspection_proposed_working_date'],
        ':amt'    => $finalPrice,
        ':dp'     => $dp,
        ':rem'    => $remaining,
        ':id'     => $id,
    ]);

    if ($upd->rowCount() === 0) {
        fail('This booking is not currently awaiting your inspection decision.', 422);
    }

    appendAvailedStatusHistory($pdo, $id, 'awaiting_agreement', 'accepted', $uid, 'seeker', 'Seeker agreed to the proposed working date and price.');

    if (!empty($booking['provider_user_id'])) {
        try {
            $pdo->prepare(
                "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
                 VALUES (:uid, 'request', :title, :message, :rid, 'availed_service', :url, 0, NOW())"
            )->execute([
                ':uid'     => (int)$booking['provider_user_id'],
                ':title'   => 'Working Date Agreed — #' . $id,
                ':message' => 'Seeker agreed to the proposed working date (' . date('M j, Y', strtotime((string)$booking['inspection_proposed_working_date'])) . ') and price. You can now prepare the booking.',
                ':rid'     => $id,
                ':url'     => '/pestify/provider/service-requests.php',
            ]);
        } catch (Exception $e) {}
    }
    if (!empty($booking['provider_email'])) {
        try {
            $mailer = new EmailSender();
            $mailer->sendCustomEmail(
                $booking['provider_email'],
                $booking['provider_first_name'] ?? '',
                'Working Date Agreed — Booking #' . $id,
                "The seeker agreed to the proposed working date and price.\n\nWorking date: " . date('F j, Y', strtotime((string)$booking['inspection_proposed_working_date'])) .
                "\nFinal price: PHP " . number_format($finalPrice, 2) .
                "\n\nYou can now prepare the booking from your dashboard."
            );
        } catch (Exception $e) {}
    }

    ok(['data' => ['id' => $id, 'decision' => 'agree', 'status' => 'accepted']]);
}

// decision === 'request_changes'
$changeNotes = trim((string) req_inp('notes', 'Change notes'));
if ($changeNotes === '') {
    fail('Please describe what changes you would like.');
}

$upd = $pdo->prepare(
    "UPDATE availed_services
     SET inspection_change_notes = :notes,
         status = 'revising',
         is_read = 0,
         updated_at = NOW()
     WHERE id = :id AND status = 'awaiting_agreement'"
);
$upd->execute([':notes' => $changeNotes, ':id' => $id]);

if ($upd->rowCount() === 0) {
    fail('This booking is not currently awaiting your inspection decision.', 422);
}

appendAvailedStatusHistory($pdo, $id, 'awaiting_agreement', 'revising', $uid, 'seeker', 'Seeker requested changes: ' . $changeNotes);

if (!empty($booking['provider_user_id'])) {
    try {
        $pdo->prepare(
            "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
             VALUES (:uid, 'request', :title, :message, :rid, 'availed_service', :url, 0, NOW())"
        )->execute([
            ':uid'     => (int)$booking['provider_user_id'],
            ':title'   => 'Seeker Requested Changes — #' . $id,
            ':message' => 'Seeker requested changes to the inspection report: ' . $changeNotes,
            ':rid'     => $id,
            ':url'     => '/pestify/provider/service-requests.php',
        ]);
    } catch (Exception $e) {}
}
if (!empty($booking['provider_email'])) {
    try {
        $mailer = new EmailSender();
        $mailer->sendCustomEmail(
            $booking['provider_email'],
            $booking['provider_first_name'] ?? '',
            'Seeker Requested Changes — Booking #' . $id,
            "The seeker requested changes to your inspection report:\n\n" . $changeNotes .
            "\n\nPlease submit a revised report from your dashboard."
        );
    } catch (Exception $e) {}
}

ok(['data' => ['id' => $id, 'decision' => 'request_changes', 'status' => 'revising']]);
