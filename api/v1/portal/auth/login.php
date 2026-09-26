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

$staffPasswordValid = false;
$staffUsedTemp      = false;
if ($row) {
    if (!empty($row['password_hash']) && password_verify($password, $row['password_hash'])) {
        $staffPasswordValid = true;
    } elseif (!empty($row['temp_password']) && hash_equals((string)$row['temp_password'], $password)) {
        $staffPasswordValid = true;
        $staffUsedTemp      = true;
    }
}

if ($staffPasswordValid) {
    // A promoted staff member may also have a matching HR employee record
    // (auto-created by staff.php, or linked by email) — carry that over in
    // the JWT so they can also use self-service endpoints (api/v1/portal/me/*)
    // under their own staff login. Mirrors auth/login.php's web behavior.
    $linkedEmp = null;
    try {
        $linkStmt = db()->prepare("SELECT id, staff_type FROM employees WHERE provider_id = :pid AND email = :email LIMIT 1");
        $linkStmt->execute([':pid' => (int)$row['provider_id'], ':email' => $row['email']]);
        $linkedEmp = $linkStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $token = jwt_issue([
        'sub'         => (int)$row['id'],
        'user_type'   => 'portal_staff',
        'role'        => $row['role'],
        'provider_id' => (int)$row['provider_id'],
        'employee_id' => $linkedEmp ? (int)$linkedEmp['id'] : 0,
        'staff_type'  => $linkedEmp['staff_type'] ?? 'office',
    ]);

    $must_change = $staffUsedTemp ? true : ((int)$row['must_change_password'] === 1);

    $upd = db()->prepare("UPDATE provider_staff SET last_login = NOW() WHERE id = :id");
    $upd->execute([':id' => (int)$row['id']]);
    if ($staffUsedTemp && !$row['must_change_password']) {
        db()->prepare("UPDATE provider_staff SET must_change_password = 1 WHERE id = :id")->execute([':id' => (int)$row['id']]);
    }

    $staff = [
        'id'          => (int)$row['id'],
        'username'    => $row['username'],
        'email'       => $row['email'],
        'role'        => $row['role'],
        'provider_id' => (int)$row['provider_id'],
        'employee_id' => $linkedEmp ? (int)$linkedEmp['id'] : 0,
    ];

    ok([
        'account_type'          => 'staff',
        'token'                 => $token,
        'must_change_password'  => $must_change,
        'staff'                 => $staff,
    ]);
}

// Employee self-service login (not promoted to portal staff) — accepts
// email or employee_id code (e.g. "EMP-XXX-1234"). Mirrors auth/login.php's
// web employee tier.
$empStmt = db()->prepare(
    "SELECT e.*, p.company_name FROM employees e
     JOIN providers p ON p.id = e.provider_id
     WHERE (e.email = :u OR e.employee_id = :u) AND e.status = 'active'
     LIMIT 1"
);
$empStmt->execute([':u' => $username_or_email]);
$emp = $empStmt->fetch(PDO::FETCH_ASSOC);

$empPasswordValid = false;
$empUsedTemp      = false;
if ($emp) {
    if (!empty($emp['password_hash']) && password_verify($password, $emp['password_hash'])) {
        $empPasswordValid = true;
    } elseif (!empty($emp['temp_password']) && hash_equals((string)$emp['temp_password'], $password)) {
        $empPasswordValid = true;
        $empUsedTemp      = true;
    }
}

if ($empPasswordValid) {
    $must_change = $empUsedTemp ? true : ((int)($emp['must_change_pwd'] ?? 0) === 1);
    if ($empUsedTemp && !$emp['must_change_pwd']) {
        db()->prepare("UPDATE employees SET must_change_pwd = 1 WHERE id = :id")->execute([':id' => (int)$emp['id']]);
    }

    $token = jwt_issue([
        'sub'         => (int)$emp['id'],
        'user_type'   => 'portal_employee',
        'provider_id' => (int)$emp['provider_id'],
        'staff_type'  => $emp['staff_type'] ?? 'office',
    ]);

    ok([
        'account_type'         => 'employee',
        'token'                => $token,
        'must_change_password' => $must_change,
        'staff'                => [
            'id'          => 0,
            'employee_id' => (int)$emp['id'],
            'employee_code' => $emp['employee_id'],
            'full_name'   => trim($emp['first_name'] . ' ' . $emp['last_name']),
            'email'       => $emp['email'],
            'role'        => 'employee',
            'department'  => $emp['department'],
            'staff_type'  => $emp['staff_type'] ?? 'office',
            'provider_id' => (int)$emp['provider_id'],
            'company_name'=> $emp['company_name'],
        ],
    ]);
}

fail('Invalid credentials', 401);
