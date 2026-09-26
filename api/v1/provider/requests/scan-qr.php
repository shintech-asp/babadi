<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/booking_workflow_helper.php';

allow('POST');

$provider = current_provider();
$token    = strtoupper(preg_replace('/[^A-Z0-9]/i', '', req_inp('token', 'Token')));

if (strlen($token) !== 6) {
    fail('Token must be exactly 6 characters (dashes are stripped automatically).');
}

$result = scanQrAndStartService(db(), $token, (int) $provider['user_id']);

if (!$result['success']) {
    fail($result['message'], 422);
}

ok(['data' => ['message' => $result['message']]]);
