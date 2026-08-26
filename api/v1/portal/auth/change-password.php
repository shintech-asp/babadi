<?php
// api/v1/portal/auth/change-password.php
// Authenticated portal staff change their own password.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$staff = require_portal();

$current_password = req_inp('current_password', 'current_password');
$new_password     = req_inp('new_password',     'new_password');

if (strlen($new_password) < 8) {
    fail('new_password must be at least 8 characters');
}

// Re-fetch row to get latest password_hash (payload from JWT may be stale)
$stmt = db()->prepare("SELECT * FROM provider_staff WHERE id = :id AND status = 'active' LIMIT 1");
$stmt->execute([':id' => (int)$staff['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail('Staff account not found', 401);
}

if (!password_verify($current_password, $row['password_hash'])) {
    fail('Current password is incorrect', 401);
}

if (password_verify($new_password, $row['password_hash'])) {
    fail('New password must differ from the current password');
}

$new_hash = password_hash($new_password, PASSWORD_BCRYPT);

$upd = db()->prepare(
    "UPDATE provider_staff
     SET password_hash = :h, must_change_password = 0, temp_password = NULL
     WHERE id = :id"
);
$upd->execute([':h' => $new_hash, ':id' => (int)$row['id']]);

ok(['message' => 'Password updated successfully']);
