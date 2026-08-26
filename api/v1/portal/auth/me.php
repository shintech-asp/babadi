<?php
// api/v1/portal/auth/me.php
// Returns the authenticated portal staff member's profile + provider company name.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');

$staff = require_portal();

$stmt = db()->prepare(
    "SELECT ps.id, ps.provider_id, ps.full_name, ps.username, ps.email,
            ps.role, ps.department, ps.must_change_password, ps.status, ps.last_login,
            p.company_name
     FROM provider_staff ps
     JOIN providers p ON p.id = ps.provider_id
     WHERE ps.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => (int)$staff['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail('Staff record not found', 404);
}

ok([
    'staff' => [
        'id'                   => (int)$row['id'],
        'provider_id'          => (int)$row['provider_id'],
        'full_name'            => $row['full_name'],
        'username'             => $row['username'],
        'email'                => $row['email'],
        'role'                 => $row['role'],
        'department'           => $row['department'],
        'must_change_password' => (bool)$row['must_change_password'],
        'status'               => $row['status'],
        'last_login'           => $row['last_login'],
        'company_name'         => $row['company_name'],
    ],
]);
