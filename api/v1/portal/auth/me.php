<?php
// api/v1/portal/auth/me.php
// Returns the authenticated portal actor's profile + provider company name —
// works for both a promoted provider_staff login and a plain employee login.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');

$actor = require_portal_actor();

if ($actor['account_type'] === 'staff') {
    $stmt = db()->prepare(
        "SELECT ps.id, ps.provider_id, ps.full_name, ps.username, ps.email,
                ps.role, ps.department, ps.must_change_password, ps.status, ps.last_login,
                p.company_name
         FROM provider_staff ps
         JOIN providers p ON p.id = ps.provider_id
         WHERE ps.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $actor['staff_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) fail('Staff record not found', 404);

    ok([
        'account_type' => 'staff',
        'staff' => [
            'id'                   => (int)$row['id'],
            'provider_id'          => (int)$row['provider_id'],
            'employee_id'          => $actor['employee_id'],
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
}

$stmt = db()->prepare(
    "SELECT e.id, e.provider_id, e.employee_id AS employee_code, e.first_name, e.last_name,
            e.email, e.department, e.position, e.staff_type, e.must_change_pwd, e.status,
            p.company_name
     FROM employees e
     JOIN providers p ON p.id = e.provider_id
     WHERE e.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $actor['employee_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) fail('Employee record not found', 404);

ok([
    'account_type' => 'employee',
    'staff' => [
        'id'                   => 0,
        'employee_id'          => (int)$row['id'],
        'employee_code'        => $row['employee_code'],
        'provider_id'          => (int)$row['provider_id'],
        'full_name'            => trim($row['first_name'] . ' ' . $row['last_name']),
        'email'                => $row['email'],
        'role'                 => 'employee',
        'department'           => $row['department'],
        'position'             => $row['position'],
        'staff_type'           => $row['staff_type'],
        'must_change_password' => (bool)$row['must_change_pwd'],
        'status'               => $row['status'],
        'company_name'         => $row['company_name'],
    ],
]);
