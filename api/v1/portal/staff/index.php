<?php
// api/v1/portal/staff/index.php
// GET — list all staff for the provider (owner only).

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner');
$pid   = (int)$staff['provider_id'];

$stmt = db()->prepare(
    "SELECT
         id,
         username,
         email,
         role,
         department,
         status,
         must_change_password,
         created_at
     FROM provider_staff
     WHERE provider_id = :pid
       AND id != :self_id
     ORDER BY created_at DESC"
);
$stmt->execute([
    ':pid'     => $pid,
    ':self_id' => (int)$staff['id'],
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$members = array_map(function (array $r): array {
    return [
        'id'                  => (int)$r['id'],
        'username'            => $r['username'],
        'email'               => $r['email'],
        'role'                => $r['role'],
        'department'          => $r['department'],
        'status'              => $r['status'],
        'must_change_password'=> (bool)$r['must_change_password'],
        'created_at'          => $r['created_at'],
    ];
}, $rows);

ok(['data' => $members]);
