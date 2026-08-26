<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$first_name = req_inp('first_name', 'First name');
$last_name  = req_inp('last_name', 'Last name');
$email      = req_inp('email', 'Email');
$password   = req_inp('password', 'Password');
$user_type  = req_inp('user_type', 'User type');
$phone      = inp('phone');

if (!in_array($user_type, ['seeker', 'provider'], true)) {
    fail('user_type must be seeker or provider');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Invalid email address');
}

if (strlen($password) < 8) {
    fail('Password must be at least 8 characters');
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    fail('Email already registered');
}

$hashed_password = password_hash($password, PASSWORD_DEFAULT);
$otp_code        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$stmt = $pdo->prepare(
    'INSERT INTO users
        (first_name, last_name, email, password, user_type, phone, email_verified, otp_code, otp_expires, last_otp_sent, created_at)
     VALUES
        (?, ?, ?, ?, ?, ?, 0, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW(), NOW())'
);
$stmt->execute([$first_name, $last_name, $email, $hashed_password, $user_type, $phone, $otp_code]);

$user_id = $pdo->lastInsertId();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$token = jwt_issue(['sub' => $user_id, 'user_type' => $user_type, 'email' => $email]);

try {
    require_once dirname(__DIR__, 3) . '/config/send_email.php';
    $mailer = new EmailSender();
    $mailer->sendOTPEmail($email, $first_name . ' ' . $last_name, $otp_code);
} catch (Throwable $e) {
    error_log('Register OTP email failed: ' . $e->getMessage());
}

ok(['token' => $token, 'user' => user_payload($user), 'message' => 'Verification code sent to your email'], 201);
