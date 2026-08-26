<?php
require_once dirname(__DIR__, 3) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';

allow('POST');

$staff = require_portal_role('owner', 'crm');
portal_require_pro($staff['provider_id']);

// Strip dashes and any non-alphanumeric chars, uppercase
$token = strtoupper(preg_replace('/[^A-Z0-9]/i', '', req_inp('token', 'Token')));

if (strlen($token) !== 6) {
    fail('Token must be exactly 6 characters (dashes are stripped automatically).');
}

// Resolve the QR token globally, then enforce tenant ownership before proceeding.
$booking = validateQrToken(db(), $token);
if (!$booking || (int)$booking['provider_id'] !== (int)$staff['provider_id']) {
    fail('Invalid or expired QR code.', 422);
}

// Stamp scan time and transition to on_going.
db()->prepare("UPDATE availed_services SET qr_scanned_at = NOW() WHERE id = ?")
   ->execute([$booking['id']]);

$ok = transitionBookingStatus(db(), $booking['id'], BK_ONGOING, (int)$staff['id'], 'provider', 'QR scanned on-site');
if (!$ok) {
    fail('Could not update booking status.', 422);
}

ok(['message' => 'Service started!']);
