<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

// Mobile self-registration is seeker-only by design. Provider sign-up needs
// business documents, Cavite geofencing, and admin review (see the web's
// auth/register.php + provider/provider-setup.php) — duplicating that in
// Flutter would be exactly the "two independently-drifting implementations"
// bug class this codebase keeps hitting (see CLAUDE.md's Recent Work Log).
// Providers register on the website; this endpoint rejects the attempt with
// a clear message instead of silently/partially handling it.

$first_name = req_inp('first_name', 'First name');
$last_name  = req_inp('last_name', 'Last name');
$suffix     = trim((string) inp('suffix', ''));
$email      = req_inp('email', 'Email');
$password   = req_inp('password', 'Password');
$user_type  = req_inp('user_type', 'User type');
$phone      = req_inp('phone', 'Phone number');

if ($user_type === 'provider') {
    fail('Provider accounts are registered on our website. Please visit pestify.site to sign up as a provider, then log in here with that account.');
}

if ($user_type !== 'seeker') {
    fail('user_type must be seeker');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Invalid email address');
}

// Same enforcement as the web's auth/register.php: PH mobile numbers are
// 11 digits starting with 09. Previously unvalidated here.
if (!preg_match('/^09\d{9}$/', $phone)) {
    fail('Please enter a valid 11-digit mobile number starting with 09 (e.g. 09171234567)');
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
        (first_name, last_name, suffix, email, password, user_type, phone, email_verified, otp_code, otp_expires, last_otp_sent, created_at)
     VALUES
        (?, ?, ?, ?, ?, ?, ?, 0, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW(), NOW())'
);
$stmt->execute([$first_name, $last_name, $suffix !== '' ? $suffix : null, $email, $hashed_password, $user_type, $phone, $otp_code]);

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
