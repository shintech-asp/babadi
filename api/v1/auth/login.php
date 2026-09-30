<?php
// api/v1/auth/login.php — centralized login for ALL roles.
//
// Mirrors the web's `auth/login.php` shared login page instead of each role
// family needing its own login screen: checked in the SAME order the web
// uses — admin_users → provider_staff (portal staff) → employees (plain,
// non-promoted self-service accounts) → users (seeker / provider owner /
// legacy users.user_type='admin' rows). The request field is still named
// 'email' for backward compatibility, but — matching the web — every tier
// except the final 'users' one accepts a username (or, for employees, their
// employee_id code) too.
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

// ── 2. provider_staff (portal staff) and employees (plain self-service) ────
// Shared with api/v1/portal/auth/login.php via resolve_portal_login() —
// see api/v1/_bootstrap.php — so the two logins can't drift on credential
// checks or JWT claims.
$portalMatch = resolve_portal_login($identifier, $password);
if ($portalMatch) {
    $token = jwt_issue($portalMatch['token_claims']);
    ok([
        'token'                => $token,
        'must_change_password' => $portalMatch['must_change_password'],
        'account_type'         => $portalMatch['account_type'],
        'staff'                => $portalMatch['staff'],
    ]);
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
