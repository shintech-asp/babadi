<?php
// api/v1/admin/users/update.php
// POST /api/v1/admin/users/update.php
// Updates a user's status (active | banned | suspended).
// Access: super_admin, admin

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
require_admin_role('super_admin', 'admin');

$id     = (int)inp('id');
$status = trim((string)inp('status', ''));

if ($id <= 0)    fail('id is required', 400);
if ($status === '') fail('status is required', 400);

$allowed = ['active', 'banned', 'suspended'];
if (!in_array($status, $allowed, true)) {
    fail('status must be one of: ' . implode(', ', $allowed), 422);
}

// ── Verify user exists ────────────────────────────────────────────────────────
$checkStmt = db()->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
$checkStmt->execute([':id' => $id]);
if (!$checkStmt->fetch()) fail('User not found', 404);

// ── Apply update ──────────────────────────────────────────────────────────────
$upStmt = db()->prepare(
    "UPDATE users SET status = :status WHERE id = :id"
);
$upStmt->execute([':status' => $status, ':id' => $id]);

ok([
    'data' => [
        'id'     => $id,
        'status' => $status,
    ],
]);
