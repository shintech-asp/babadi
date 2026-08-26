<?php
// api/v1/portal/auth/login.php
// Portal staff login — no auth guard required.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$username_or_email = req_inp('username_or_email', 'username_or_email');
$password          = req_inp('password');

$stmt = db()->prepare(
    "SELECT * FROM provider_staff
     WHERE (username = :u OR email = :u) AND status = 'active'
     LIMIT 1"
);
$stmt->execute([':u' => $username_or_email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || !password_verify($password, $row['password_hash'])) {
    fail('Invalid credentials', 401);
}

$token = jwt_issue([
    'sub'         => (int)$row['id'],
    'user_type'   => 'portal_staff',
    'role'        => $row['role'],
    'provider_id' => (int)$row['provider_id'],
]);

// Update last_login timestamp
$upd = db()->prepare("UPDATE provider_staff SET last_login = NOW() WHERE id = :id");
$upd->execute([':id' => (int)$row['id']]);

$staff = [
    'id'          => (int)$row['id'],
    'username'    => $row['username'],
    'email'       => $row['email'],
    'role'        => $row['role'],
    'provider_id' => (int)$row['provider_id'],
];

if ((int)$row['must_change_password'] === 1) {
    ok([
        'must_change_password' => true,
        'token'                => $token,
        'staff'                => $staff,
    ]);
}

ok([
    'token'                => $token,
    'must_change_password' => false,
    'staff'                => $staff,
]);
