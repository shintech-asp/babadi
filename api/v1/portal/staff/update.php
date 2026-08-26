<?php
// api/v1/portal/staff/update.php
// POST — update role, department, or status of a staff member (owner only).

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner');
$pid   = (int)$staff['provider_id'];
$pdo   = db();

// ── Input ─────────────────────────────────────────────────────────────────────

$target_id = (int)req_inp('id', 'id');

// Prevent modifying own account
if ($target_id === (int)$staff['id']) {
    fail('You cannot update your own account', 403);
}

// Verify target belongs to this provider
$chk = $pdo->prepare(
    "SELECT id FROM provider_staff WHERE id = ? AND provider_id = ? LIMIT 1"
);
$chk->execute([$target_id, $pid]);
if (!$chk->fetch()) {
    fail('Staff member not found', 404);
}

// ── Build update set ──────────────────────────────────────────────────────────

$valid_roles   = ['owner', 'hr', 'finance', 'crm'];
$valid_depts   = ['hr', 'finance', 'crm', 'all'];
$valid_statuses = ['active', 'inactive'];

$fields = [];
$params = [];

$role = inp('role');
if ($role !== null) {
    $role = trim((string)$role);
    if (!in_array($role, $valid_roles, true)) {
        fail('role must be one of: ' . implode(', ', $valid_roles));
    }
    $fields[] = 'role = :role';
    $params[':role'] = $role;
}

$department = inp('department');
if ($department !== null) {
    $department = trim((string)$department);
    if (!in_array($department, $valid_depts, true)) {
        fail('department must be one of: ' . implode(', ', $valid_depts));
    }
    $fields[] = 'department = :department';
    $params[':department'] = $department;
}

$status = inp('status');
if ($status !== null) {
    $status = trim((string)$status);
    if (!in_array($status, $valid_statuses, true)) {
        fail('status must be one of: ' . implode(', ', $valid_statuses));
    }
    $fields[] = 'status = :status';
    $params[':status'] = $status;
}

if (empty($fields)) {
    fail('No updatable fields provided (role, department, status)');
}

// ── Execute update ────────────────────────────────────────────────────────────

$params[':id']  = $target_id;
$params[':pid'] = $pid;

$sql = "UPDATE provider_staff SET " . implode(', ', $fields) . "
        WHERE id = :id AND provider_id = :pid";

$pdo->prepare($sql)->execute($params);

// ── Return updated row ────────────────────────────────────────────────────────

$sel = $pdo->prepare(
    "SELECT id, username, email, role, department, status, must_change_password, created_at
     FROM provider_staff WHERE id = ?"
);
$sel->execute([$target_id]);
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
    ],
]);
