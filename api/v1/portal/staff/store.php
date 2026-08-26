<?php
// api/v1/portal/staff/store.php
// POST — create a new staff member (owner only).

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner');
$pid   = (int)$staff['provider_id'];
$pdo   = db();

// ── Input ─────────────────────────────────────────────────────────────────────

$username   = req_inp('username', 'Username');
$email      = req_inp('email', 'Email');
$role       = req_inp('role', 'Role');
$department = req_inp('department', 'Department');

// ── Validate role / department ────────────────────────────────────────────────

$valid_roles = ['hr', 'finance', 'crm'];
if (!in_array($role, $valid_roles, true)) {
    fail('role must be one of: ' . implode(', ', $valid_roles));
}

$valid_depts = ['hr', 'finance', 'crm', 'all'];
if (!in_array($department, $valid_depts, true)) {
    fail('department must be one of: ' . implode(', ', $valid_depts));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Invalid email address');
}

// ── Uniqueness check (within this provider) ───────────────────────────────────

$chk = $pdo->prepare(
    "SELECT id FROM provider_staff WHERE provider_id = ? AND (username = ? OR email = ?) LIMIT 1"
);
$chk->execute([$pid, $username, $email]);
if ($chk->fetch()) {
    fail('Username or email already exists for this provider', 409);
}

// ── Generate temp password ────────────────────────────────────────────────────

$charset  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
$temp_pwd = '';
for ($i = 0; $i < 10; $i++) {
    $temp_pwd .= $charset[random_int(0, strlen($charset) - 1)];
}
$password_hash = password_hash($temp_pwd, PASSWORD_BCRYPT);

// ── Insert ────────────────────────────────────────────────────────────────────

$ins = $pdo->prepare(
    "INSERT INTO provider_staff
         (provider_id, username, email, role, department,
          password_hash, temp_password, must_change_password, status, created_at)
     VALUES
         (:provider_id, :username, :email, :role, :department,
          :password_hash, :temp_password, 1, 'active', NOW())"
);
$ins->execute([
    ':provider_id'   => $pid,
    ':username'      => $username,
    ':email'         => $email,
    ':role'          => $role,
    ':department'    => $department,
    ':password_hash' => $password_hash,
    ':temp_password' => $temp_pwd,
]);

$new_id = (int)$pdo->lastInsertId();

// ── Return new row (no passwords) ────────────────────────────────────────────

$sel = $pdo->prepare(
    "SELECT id, username, email, role, department, status, must_change_password, created_at
     FROM provider_staff WHERE id = ?"
);
$sel->execute([$new_id]);
$row = $sel->fetch(PDO::FETCH_ASSOC);

ok([
    'data' => [
        'id'                   => (int)$row['id'],
        'username'             => $row['username'],
        'email'                => $row['email'],
        'role'                 => $row['role'],
        'department'           => $row['department'],
        'status'               => $row['status'],
        'must_change_password' => (bool)$row['must_change_password'],
        'created_at'           => $row['created_at'],
        'temp_password'        => $temp_pwd, // returned once so owner can share it
    ],
], 201);
