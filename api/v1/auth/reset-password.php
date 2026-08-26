<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$email    = req_inp('email');
$token    = req_inp('token');
$password = req_inp('password');

if (strlen($password) < 8) {
    fail('Password must be at least 8 characters', 400);
}

$pdo  = db();
$stmt = $pdo->prepare(
    'SELECT id FROM users WHERE email = :email AND otp_code = :token AND otp_expires > NOW()'
);
$stmt->execute([':email' => $email, ':token' => $token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fail('Invalid or expired reset token', 422);
}

$hash   = password_hash($password, PASSWORD_BCRYPT);
$update = $pdo->prepare(
    'UPDATE users SET password = :password, otp_code = NULL, otp_expires = NULL WHERE id = :id'
);
$update->execute([':password' => $hash, ':id' => $user['id']]);

ok(['message' => 'Password reset successfully']);
