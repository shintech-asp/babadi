<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

function require_portal(): array {
    $payload = auth();
    if (($payload['user_type'] ?? '') !== 'portal_staff') fail('Portal staff access required', 403);
    $stmt = db()->prepare("SELECT * FROM provider_staff WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute([':id' => (int)($payload['sub'] ?? 0)]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) fail('Staff account not found or inactive', 401);
    return $staff;
}

function require_portal_role(string ...$roles): array {
    $staff = require_portal();
    if (!in_array($staff['role'], $roles, true)) fail('Insufficient portal role', 403);
    return $staff;
}

function current_portal_staff(): array { return require_portal(); }

// Unified identity for self-service endpoints (api/v1/portal/me/*): accepts
// EITHER a promoted provider_staff login OR a plain employees-table login
// (see api/v1/portal/auth/login.php) — mirrors the web's portal_account_type
// 'staff'/'employee' split (auth/login.php, provider-portal/includes/
// portal-auth.php) so mobile self-service works for both account kinds.
// Returns a normalized shape:
//   ['account_type'=>'staff'|'employee', 'staff_id'=>int (0 for pure employees),
//    'employee_id'=>int (0 if no linked employee record), 'provider_id'=>int,
//    'role'=>string, 'department'=>?string, 'full_name'=>string, 'staff_type'=>string]
function require_portal_actor(): array {
    $payload = auth();
    $type = $payload['user_type'] ?? '';

    if ($type === 'portal_staff') {
        $stmt = db()->prepare("SELECT * FROM provider_staff WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => (int)($payload['sub'] ?? 0)]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) fail('Staff account not found or inactive', 401);

        return [
            'account_type' => 'staff',
            'staff_id'     => (int)$staff['id'],
            'employee_id'  => (int)($payload['employee_id'] ?? 0),
            'provider_id'  => (int)$staff['provider_id'],
            'role'         => $staff['role'],
            'department'   => $staff['department'],
            'full_name'    => $staff['full_name'],
            'staff_type'   => $payload['staff_type'] ?? 'office',
        ];
    }

    if ($type === 'portal_employee') {
        $stmt = db()->prepare("SELECT * FROM employees WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => (int)($payload['sub'] ?? 0)]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) fail('Employee account not found or inactive', 401);

        return [
            'account_type' => 'employee',
            'staff_id'     => 0,
            'employee_id'  => (int)$emp['id'],
            'provider_id'  => (int)$emp['provider_id'],
            'role'         => 'employee',
            'department'   => $emp['department'],
            'full_name'    => trim($emp['first_name'] . ' ' . $emp['last_name']),
            'staff_type'   => $emp['staff_type'] ?? 'office',
        ];
    }

    fail('Portal access required', 403);
}

function portal_require_pro(int $provider_id): void {
    $stmt = db()->prepare(
        "SELECT id FROM provider_subscriptions WHERE provider_id = ? AND status IN ('active','grace') LIMIT 1"
    );
    $stmt->execute([$provider_id]);
    if (!$stmt->fetch()) fail('Pro subscription required to access this feature.', 403);
}
