<?php
// api/v1/auth/login.php — centralized login for ALL roles.
//
// Mirrors the web's `auth/login.php` shared login page instead of each role
// family needing its own login screen: checked in the SAME order the web
// uses — admin_users → provider_staff (portal staff) → users (seeker /
// provider owner / legacy users.user_type='admin' rows). The request field
// is still named 'email' for backward compatibility, but — matching the web
// — every tier except the final 'users' one accepts a username too.
//
// A row matching the identifier but failing the password check falls
// through to the next tier rather than failing immediately, exactly like
// the web version — only the final tier's failure produces the generic
// "Invalid credentials" error, so a wrong password never reveals which
// table (if any) the identifier matched.
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$identifier = req_inp('email');
$password   = req_inp('password');

$pdo = db();

// ── 1. admin_users ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT * FROM admin_users WHERE (username = :id OR email = :id) AND status = 'active' LIMIT 1"
);
$stmt->execute([':id' => $identifier]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    $hashOk = !empty($admin['password_hash']) && password_verify($password, $admin['password_hash']);
    $tempOk = !$hashOk && !empty($admin['temp_password']) && hash_equals((string)$admin['temp_password'], $password);

    if ($hashOk || $tempOk) {
        $mustChange = $tempOk ? 1 : (int)($admin['must_change_password'] ?? 0);

        if ($tempOk) {
            try {
                $pdo->prepare("UPDATE admin_users SET temp_password = NULL WHERE id = :id")
                    ->execute([':id' => $admin['id']]);
            } catch (Throwable $e) {
                error_log('[auth/login] Failed to clear admin temp_password: ' . $e->getMessage());
            }
        }

        $token = jwt_issue([
            'sub'       => (int)$admin['id'],
            'user_type' => 'admin',
            'role'      => $admin['role'],
        ]);

        ok([
            'token'                => $token,
            'must_change_password' => (bool)$mustChange,
            'admin'                => [
                'id'         => (int)$admin['id'],
                'username'   => $admin['username'] ?? '',
                'email'      => $admin['email'],
                'full_name'  => $admin['full_name'] ?? null,
                'role'       => $admin['role'],
                'department' => $admin['department'] ?? null,
            ],
        ]);
    }
}

// ── 2. provider_staff (portal staff) ────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT ps.*, p.company_name
     FROM provider_staff ps
     JOIN providers p ON p.id = ps.provider_id
     WHERE (ps.username = :id OR ps.email = :id) AND ps.status = 'active'
     LIMIT 1"
);
$stmt->execute([':id' => $identifier]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if ($staff) {
    $hashOk = !empty($staff['password_hash']) && password_verify($password, $staff['password_hash']);
    $tempOk = !$hashOk && !empty($staff['temp_password']) && hash_equals((string)$staff['temp_password'], $password);

    if ($hashOk || $tempOk) {
        $mustChange = $tempOk ? 1 : (int)($staff['must_change_password'] ?? 0);

        try {
            if ($tempOk) {
                $pdo->prepare("UPDATE provider_staff SET must_change_password = 1, last_login = NOW() WHERE id = :id")
                    ->execute([':id' => $staff['id']]);
            } else {
                $pdo->prepare("UPDATE provider_staff SET last_login = NOW() WHERE id = :id")
                    ->execute([':id' => $staff['id']]);
            }
        } catch (Throwable $e) {
            error_log('[auth/login] Failed to update provider_staff on login: ' . $e->getMessage());
        }

        $token = jwt_issue([
            'sub'         => (int)$staff['id'],
            'user_type'   => 'portal_staff',
            'role'        => $staff['role'],
            'provider_id' => (int)$staff['provider_id'],
        ]);

        ok([
            'token'                => $token,
            'must_change_password' => (bool)$mustChange,
            'staff'                => [
                'id'           => (int)$staff['id'],
                'username'     => $staff['username'],
                'email'        => $staff['email'],
                'role'         => $staff['role'],
                'provider_id'  => (int)$staff['provider_id'],
                'company_name' => $staff['company_name'],
            ],
        ]);
    }
}

// ── 3. users (seeker / provider owner / legacy users.user_type='admin') ────
$stmt = $pdo->prepare(
    "SELECT * FROM users WHERE email = :id AND status != 'banned' LIMIT 1"
);
$stmt->execute([':id' => $identifier]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    fail('Invalid credentials', 401);
}

if ((int)$user['email_verified'] === 0) {
    fail('Please verify your email first', 403);
}

$token = jwt_issue(['sub' => $user['id'], 'user_type' => $user['user_type']]);

ok(['token' => $token, 'user' => user_payload($user)]);
