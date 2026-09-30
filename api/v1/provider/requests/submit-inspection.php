<?php
// POST api/v1/provider/requests/submit-inspection.php
// Mobile counterpart of provider/service-requests.php's "Submit Inspection
// Report" action — see CLAUDE.md's "Recent Work Log" for the full design.
// Moves an 'accepted'/'revising' booking (for a listing with
// requires_inspection=1) to 'awaiting_agreement' with a photo, findings,
// a final price, and a proposed working date for the seeker to review.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';
require_once 'config/send_email.php';

allow('POST');

$p   = current_provider();
$pdo = db();

$avail_id       = (int) req_inp('avail_id', 'Booking ID');
$notes          = trim((string) req_inp('notes', 'Inspection notes'));
$proposed_price = (float) req_inp('proposed_price', 'Proposed price');
$proposed_date  = req_inp('proposed_working_date', 'Proposed working date');
$staff_id       = (int) inp('staff_id', 0);

if ($proposed_price <= 0) {
    fail('Proposed price must be greater than 0.');
}

$date_obj = DateTime::createFromFormat('Y-m-d', $proposed_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $proposed_date || $proposed_date < date('Y-m-d')) {
    fail('proposed_working_date must be a valid Y-m-d date, today or later.');
}

if ($staff_id > 0) {
    $st = $pdo->prepare("SELECT id FROM employees WHERE id = :id AND provider_id = :pid AND staff_type = 'field' LIMIT 1");
    $st->execute([':id' => $staff_id, ':pid' => $p['id']]);
    if (!$st->fetch()) {
        $staff_id = 0;
    }
}

$stmt = $pdo->prepare(
    'SELECT status, seeker_user_id, user_id
     FROM availed_services WHERE id = :id AND provider_id = :pid LIMIT 1'
);
$stmt->execute([':id' => $avail_id, ':pid' => $p['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    fail('Booking not found.', 404);
}
if (!in_array($booking['status'], ['accepted', 'revising'], true)) {
    fail('This booking is not awaiting an inspection report.', 422);
}

$image_path = uploadInspectionReportImage('image', (int)$p['id'], $avail_id);
if ($image_path === false) {
    fail('The inspection photo could not be uploaded. Use a JPG, PNG, or WEBP image under 8MB.', 422);
}
if ($image_path === null) {
    fail('Please attach a photo from the inspection.', 422);
}

$updSql = 'UPDATE availed_services
           SET inspection_report_notes = :notes,
               inspection_report_image = :img,
               inspection_proposed_price = :price,
               inspection_proposed_working_date = :wdate,
               inspection_submitted_at = NOW(),
               inspection_round = inspection_round + 1,
               inspection_change_notes = NULL,
               status = \'awaiting_agreement\',
               is_read = 0';
$params = [
    ':notes' => $notes,
    ':img'   => $image_path,
    ':price' => $proposed_price,
    ':wdate' => $proposed_date,
    ':id'    => $avail_id,
    ':pid'   => $p['id'],
];
if ($staff_id > 0) {
    $updSql .= ', inspection_submitted_by = :staff, assigned_employee_id = :staff2';
    $params[':staff'] = $staff_id;
    $params[':staff2'] = $staff_id;
}
$updSql .= ' WHERE id = :id AND provider_id = :pid';
$pdo->prepare($updSql)->execute($params);

try {
    $pdo->prepare(
        "INSERT INTO availed_service_status_history
            (availed_id, old_status, new_status, changed_by, changed_by_role, notes, created_at)
         VALUES (:aid, :old, 'awaiting_agreement', :uid, 'provider', 'Inspection report submitted', NOW())"
    )->execute([':aid' => $avail_id, ':old' => $booking['status'], ':uid' => $p['user_id'] ?? null]);
} catch (Exception $e) {}

$seeker_user_id = (int)($booking['seeker_user_id'] ?: $booking['user_id']);
try {
    // notifications.type is a strict ENUM('request','message','review','payment',
    // 'system','promotion') — no 'inspection' value exists, so this INSERT was
    // silently failing under STRICT_TRANS_TABLES on every submission (same bug
    // class already found and fixed in submitProviderInspectionReport(), see
    // CLAUDE.md's "Field technicians can now actually process..." log entry —
    // this mobile-only endpoint has its own separate copy of the insert and
    // was missed by that fix). Using 'request', the closest valid value.
    $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, action_url, is_read, created_at)
         VALUES (:uid, 'request', :title, :msg, :rid, 'availed_service', :url, 0, NOW())"
    )->execute([
        ':uid'   => $seeker_user_id,
        ':title' => 'Inspection Report Ready — #' . $avail_id,
        ':msg'   => 'Your provider submitted an inspection report with a proposed working date and price. Please review and respond.',
        ':rid'   => $avail_id,
        ':url'   => appUrl('my-requests.php'),
    ]);
} catch (Exception $e) {}

try {
    $seekerStmt = $pdo->prepare('SELECT email, first_name FROM users WHERE id = :id LIMIT 1');
    $seekerStmt->execute([':id' => $seeker_user_id]);
    $seekerRow = $seekerStmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($seekerRow['email'])) {
        $mailer = new EmailSender();
        $mailer->sendCustomEmail(
            $seekerRow['email'],
            $seekerRow['first_name'] ?? '',
            'Inspection Report Ready — Booking #' . $avail_id,
            "Your inspection is complete.\n\nProposed working date: " . date('F j, Y', strtotime($proposed_date)) .
            "\nProposed price: PHP " . number_format($proposed_price, 2) .
            "\n\nNotes: " . $notes .
            "\n\nPlease log in to review the photo and either agree or request changes."
        );
    }
} catch (Exception $e) {}

ok(['data' => ['message' => 'Inspection report submitted.', 'status' => 'awaiting_agreement']]);
