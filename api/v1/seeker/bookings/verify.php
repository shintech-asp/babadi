<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/ControlNumberService.php';

allow('POST');

$seeker        = require_seeker();
$avail_id      = (int)req_inp('avail_id', 'Booking ID');
$control_number = req_inp('control_number', 'Control Number');

$cns    = new ControlNumberService(db());
$result = $cns->verifySeekerCode(
    availedId    : $avail_id,
    seekerUserId : $seeker['id'],
    entered      : $control_number,
    ip           : $_SERVER['REMOTE_ADDR'] ?? ''
);

if (!$result['success']) {
    fail($result['error'], 422);
}

// Fetch the real current status after ControlNumberService updated it
$row = db()->prepare('SELECT status FROM availed_services WHERE id = :id LIMIT 1');
$row->execute([':id' => $avail_id]);
$current_status = $row->fetchColumn();

$dual = $result['state'] === 'dual_verified';

ok([
    'message'      => $dual
        ? 'Service started — both parties verified'
        : 'Verification recorded, waiting for technician to verify their side',
    'dual_verified' => $dual,
    'status'        => $current_status,
]);
