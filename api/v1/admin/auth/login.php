<?php
// api/v1/admin/auth/login.php
// POST /api/v1/admin/auth/login
// Public — no auth guard. Authenticates an admin_user and returns a JWT.

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$identifier = req_inp('username_or_email', 'username_or_email');
$password   = req_inp('password', 'password');

$stmt = db()->prepare(
    "SELECT * FROM admin_users
     WHERE (username = :u OR email = :u) AND status = 'active'
     LIMIT 1"
);
$stmt->execute([':u' => $identifier]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$hashOk = !empty($row['password_hash']) && password_verify($password, $row['password_hash']);
$tempOk = !$hashOk && !empty($row['temp_password']) && hash_equals((string)$row['temp_password'], $password);

if (!$row || (!$hashOk && !$tempOk)) {
    fail('Invalid credentials', 401);
}

$mustChange = (int)($row['must_change_password'] ?? 0);
if ($tempOk) {
    $mustChange = 1;
}

$jwt = jwt_issue([
    'sub'       => (int)$row['id'],
    'user_type' => 'admin',
    'role'      => $row['role'],
]);

ok([
    'token'                => $jwt,
    'must_change_password' => (bool)$mustChange,
    'admin'                => [
        'id'         => (int)$row['id'],
        'username'   => $row['username'] ?? '',
        'email'      => $row['email'],
        'full_name'  => $row['full_name'] ?? null,
        'role'       => $row['role'],
        'department' => $row['department'] ?? null,
    ],
]);
