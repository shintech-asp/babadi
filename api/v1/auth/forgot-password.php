<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$email = req_inp('email', 'Email');

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $upd = $pdo->prepare('UPDATE users SET otp_code = ?, otp_expires = ? WHERE id = ?');
    $upd->execute([$token, $expires, $user['id']]);

    try {
        $to = $email;
        $subject = 'Password Reset Request';
        $body = "You requested a password reset.\n\nUse the following token to reset your password:\n\n{$token}\n\nThis token expires in 1 hour.\n\nIf you did not request this, please ignore this email.";
        $headers = 'From: no-reply@pestify.com' . "\r\n" .
                   'Content-Type: text/plain; charset=UTF-8';
        mail($to, $subject, $body, $headers);
    } catch (\Throwable $e) {
        // silently swallow — do not leak errors
    }
}

ok(['message' => 'If that email is registered you will receive a reset link']);
