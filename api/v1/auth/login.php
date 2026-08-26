<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$email    = req_inp('email');
$password = req_inp('password');

$stmt = db()->prepare(
    "SELECT * FROM users WHERE email = :id AND status != 'banned' LIMIT 1"
);
$stmt->execute([':id' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    fail('Invalid credentials', 401);
}

if ((int)$user['email_verified'] === 0) {
    fail('Please verify your email first', 403);
}

$token = jwt_issue(['sub' => $user['id'], 'user_type' => $user['user_type']]);

ok(['token' => $token, 'user' => user_payload($user)]);
