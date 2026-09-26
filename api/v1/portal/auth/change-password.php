<?php
// api/v1/portal/auth/change-password.php
// Authenticated portal staff OR employee changes their own password.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$actor = require_portal_actor();

$current_password = req_inp('current_password', 'current_password');
$new_password     = req_inp('new_password',     'new_password');

if (strlen($new_password) < 8) {
    fail('new_password must be at least 8 characters');
}

if ($actor['account_type'] === 'staff') {
    $stmt = db()->prepare("SELECT * FROM provider_staff WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute([':id' => $actor['staff_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) fail('Staff account not found', 401);
    if (!password_verify($current_password, $row['password_hash'])) fail('Current password is incorrect', 401);
    if (password_verify($new_password, $row['password_hash'])) fail('New password must differ from the current password');

    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
    db()->prepare("UPDATE provider_staff SET password_hash = :h, must_change_password = 0, temp_password = NULL WHERE id = :id")
        ->execute([':h' => $new_hash, ':id' => (int)$row['id']]);
} else {
    $stmt = db()->prepare("SELECT * FROM employees WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute([':id' => $actor['employee_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) fail('Employee account not found', 401);
    if (empty($row['password_hash']) || !password_verify($current_password, $row['password_hash'])) fail('Current password is incorrect', 401);
    if (password_verify($new_password, $row['password_hash'])) fail('New password must differ from the current password');

    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
    db()->prepare("UPDATE employees SET password_hash = :h, must_change_pwd = 0, temp_password = NULL WHERE id = :id")
        ->execute([':h' => $new_hash, ':id' => (int)$row['id']]);
}

ok(['message' => 'Password updated successfully']);
