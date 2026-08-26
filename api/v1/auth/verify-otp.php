<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$user = auth();
$otp = req_inp('otp');

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT id FROM users WHERE id = :id AND otp_code = :otp AND otp_expires > NOW() AND email_verified = 0'
);
$stmt->execute([':id' => $user['id'], ':otp' => $otp]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail('Invalid or expired OTP code', 422);
}

$update = $pdo->prepare(
    'UPDATE users SET email_verified = 1, email_verified_at = NOW(), otp_code = NULL, otp_expires = NULL WHERE id = :id'
);
$update->execute([':id' => $user['id']]);

ok(['message' => 'Email verified successfully']);
