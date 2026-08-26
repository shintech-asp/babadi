<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$user_payload = auth();
$user_id = $user_payload['sub'];

$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fail('User not found', 404);
}

if ((int)$user['email_verified'] === 1) {
    fail('Email already verified', 422);
}

if ($user['last_otp_sent'] !== null) {
    $last_sent = strtotime($user['last_otp_sent']);
    $now = time();
    if (($now - $last_sent) < 60) {
        fail('Please wait before requesting another code', 429);
    }
}

$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$stmt = $pdo->prepare(
    'UPDATE users SET otp_code = :otp, otp_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE), last_otp_sent = NOW() WHERE id = :id'
);
$stmt->execute([':otp' => $otp, ':id' => $user_id]);

try {
    $to = $user['email'];
    $name = $user['first_name'] . ' ' . $user['last_name'];
    $subject = 'Your Verification Code';
    $body = "Hello {$name},\n\nYour new verification code is: {$otp}\n\nThis code expires in 15 minutes.\n\nIf you did not request this, please ignore this email.";
    $headers = 'From: no-reply@pestify.com' . "\r\n" .
               'Content-Type: text/plain; charset=UTF-8';
    mail($to, $subject, $body, $headers);
} catch (Throwable $e) {
    // Email sending failure is non-fatal; OTP has been saved
}

ok(['message' => 'Verification code resent']);
