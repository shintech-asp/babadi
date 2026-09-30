<?php
// api/v1/_bootstrap.php
// Shared foundation for every v1 mobile API endpoint.
// Usage: require_once __DIR__ . '/../../_bootstrap.php'; (adjust depth per folder)

chdir(dirname(__DIR__, 2));          // always resolve from project root
require_once 'config/config.php';
require_once 'config/database.php';

// ── CORS ─────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── JWT (HS256, zero external deps) ──────────────────────────────────────────
define('JWT_SECRET', 'pestify_jwt_s3cr3t_CHANGE_IN_PRODUCTION');
define('JWT_TTL',    60 * 60 * 24 * 30);   // 30-day tokens

function _b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function _b64u_d(string $s): string { return base64_decode(strtr($s, '-_', '+/')); }

function jwt_issue(array $claims): string {
    $claims += ['iat' => time(), 'exp' => time() + JWT_TTL];
    $h = _b64u(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $p = _b64u(json_encode($claims));
    return "$h.$p." . _b64u(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
}

function jwt_parse(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    if (!hash_equals(_b64u(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), $s)) return null;
    $payload = json_decode(_b64u_d($p), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    return $payload;
}

// ── Responses ─────────────────────────────────────────────────────────────────
function ok(array $data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
function input(): array {
    static $c = null;
    if ($c !== null) return $c;
    $d = $_POST;
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $j = json_decode(file_get_contents('php://input'), true);
        if (is_array($j)) $d = array_merge($d, $j);
    }
    return $c = $d;
}
function inp(string $k, $default = null) { return input()[$k] ?? $_GET[$k] ?? $default; }
function req_inp(string $k, string $label = null): string {
    $v = trim((string)inp($k));
    if ($v === '') fail(($label ?? $k) . ' is required');
    return $v;
}

// ── Database ──────────────────────────────────────────────────────────────────
function db(): PDO {
    static $conn = null;
    if ($conn) return $conn;
    $d = new Database();
    $conn = $d->getConnection();
    if (!$conn) fail('Database unavailable', 503);
    return $conn;
}

// ── Auth guards ───────────────────────────────────────────────────────────────
function bearer(): ?string {
    // HTTP_AUTHORIZATION may be absent on some Apache/XAMPP configs; try all sources.
    $h = $_SERVER['HTTP_AUTHORIZATION']
      ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
      ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '')
      ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $h, $m)) return trim($m[1]);
    return isset($_GET['token']) ? trim($_GET['token']) : null;
}

function auth(): array {
    $t = bearer();
    if (!$t) fail('Unauthenticated', 401);
    $p = jwt_parse($t);
    if (!$p || empty($p['sub'])) fail('Token invalid or expired', 401);
    return $p;
}

function current_user(): array {
    $p = auth();
    $s = db()->prepare("SELECT id,first_name,last_name,email,user_type,profile_image,phone,
                               address,city,state,zip_code,status,email_verified
                        FROM users WHERE id=:id AND status!='banned' LIMIT 1");
    $s->execute([':id' => (int)$p['sub']]);
    $u = $s->fetch(PDO::FETCH_ASSOC);
    if (!$u) fail('User not found', 401);
    return $u;
}

function require_seeker(): array {
    $u = current_user();
    if ($u['user_type'] !== 'seeker') fail('Seeker access required', 403);
    return $u;
}

function require_provider(): array {
    $u = current_user();
    if ($u['user_type'] !== 'provider') fail('Provider access required', 403);
    return $u;
}

function allow(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true))
        fail('Method not allowed', 405);
}

// ── Pagination helper ─────────────────────────────────────────────────────────
function paginate(): array {
    $page  = max(1, (int)inp('page', 1));
    $limit = min(100, max(1, (int)inp('limit', 20)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

// ── Provider row helper (gets provider record for current user) ───────────────
function current_provider(): array {
    $u = require_provider();
    $s = db()->prepare("SELECT * FROM providers WHERE user_id=:uid LIMIT 1");
    $s->execute([':uid' => $u['id']]);
    $p = $s->fetch(PDO::FETCH_ASSOC);
    if (!$p) fail('Provider profile not set up', 403);
    return $p;
}

// ── User shape returned to clients ────────────────────────────────────────────
function user_payload(array $u): array {
    return [
        'id'            => (int)$u['id'],
        'first_name'    => $u['first_name'],
        'last_name'     => $u['last_name'],
        'email'         => $u['email'],
        'user_type'     => $u['user_type'],
        'profile_image' => $u['profile_image'] ?? null,
        'phone'         => $u['phone'] ?? null,
        'email_verified'=> (bool)($u['email_verified'] ?? false),
    ];
}

// ── Portal login resolution (provider_staff + employees tiers) ────────────────
// Shared by api/v1/auth/login.php (the centralized login every mobile screen
// actually calls) and api/v1/portal/auth/login.php (a standalone endpoint
// that still works on its own) — previously each had its own independent
// copy of this credential/JWT-claim logic, exactly the kind of drifting
// duplicate this codebase has been bitten by before (see CLAUDE.md's
// "Recent Work Log"). $identifier matches username/email for provider_staff,
// or email/employee_id code for employees. Returns null if neither tier
// matches; otherwise ['account_type', 'must_change_password', 'token_claims',
// 'staff'] — caller calls jwt_issue(token_claims) itself so each endpoint's
// own response envelope shape is unaffected.
function resolve_portal_login(string $identifier, string $password): ?array {
    $pdo = db();

    // Tier: provider_staff (promoted portal staff — owner/hr/finance/crm)
    $stmt = $pdo->prepare(
        "SELECT ps.*, p.company_name
         FROM provider_staff ps
         JOIN providers p ON p.id = ps.provider_id
         WHERE (ps.username = :u OR ps.email = :u) AND ps.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([':u' => $identifier]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($staff) {
        $passwordValid = false;
        $usedTemp = false;
        if (!empty($staff['password_hash']) && password_verify($password, $staff['password_hash'])) {
            $passwordValid = true;
        } elseif (!empty($staff['temp_password']) && hash_equals((string)$staff['temp_password'], $password)) {
            $passwordValid = true;
            $usedTemp = true;
        }

        if ($passwordValid) {
            // A promoted staff member may also have a matching HR employee
            // record (auto-created by staff.php, or linked by email) —
            // carry that over in the JWT so they can also use self-service
            // endpoints (api/v1/portal/me/*) under their own staff login.
            $linkedEmp = null;
            try {
                $linkStmt = $pdo->prepare("SELECT id, staff_type FROM employees WHERE provider_id = :pid AND email = :email LIMIT 1");
                $linkStmt->execute([':pid' => (int)$staff['provider_id'], ':email' => $staff['email']]);
                $linkedEmp = $linkStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            $mustChange = $usedTemp ? true : ((int)$staff['must_change_password'] === 1);

            $pdo->prepare("UPDATE provider_staff SET last_login = NOW() WHERE id = :id")->execute([':id' => (int)$staff['id']]);
            if ($usedTemp && !$staff['must_change_password']) {
                $pdo->prepare("UPDATE provider_staff SET must_change_password = 1 WHERE id = :id")->execute([':id' => (int)$staff['id']]);
            }
            // A successful real-password login means temp_password is stale —
            // clear it so it can't keep working as a permanent alternate
            // credential (change-password.php already clears it on an
            // explicit change, but a staff member whose password_hash was
            // updated some other way could otherwise still log in with the
            // old temp password forever).
            if (!$usedTemp && !empty($staff['temp_password'])) {
                $pdo->prepare("UPDATE provider_staff SET temp_password = NULL WHERE id = :id")->execute([':id' => (int)$staff['id']]);
            }

            return [
                'account_type' => 'staff',
                'must_change_password' => $mustChange,
                'token_claims' => [
                    'sub'         => (int)$staff['id'],
                    'user_type'   => 'portal_staff',
                    'role'        => $staff['role'],
                    'provider_id' => (int)$staff['provider_id'],
                    'employee_id' => $linkedEmp ? (int)$linkedEmp['id'] : 0,
                    'staff_type'  => $linkedEmp['staff_type'] ?? 'office',
                ],
                'staff' => [
                    'id'           => (int)$staff['id'],
                    'username'     => $staff['username'],
                    'email'        => $staff['email'],
                    'role'         => $staff['role'],
                    'provider_id'  => (int)$staff['provider_id'],
                    'employee_id'  => $linkedEmp ? (int)$linkedEmp['id'] : 0,
                    'company_name' => $staff['company_name'],
                ],
            ];
        }
    }

    // Tier: employees (plain self-service, not promoted) — accepts email or
    // employee_id code (e.g. "EMP-XXX-1234"). Only checked when $staff was
    // never found above — a provider_staff row matching this identifier
    // means it IS a promoted portal-staff account, so a wrong password
    // there must fail outright rather than silently retrying against the
    // employees table. Without this guard, a promoted manager entering
    // their OLD employee password (instead of the brand-new username+temp
    // password the promotion generated) would quietly log in via their
    // still-active employees row with plain self-service only — the
    // "promoted to HR Manager but the app only shows Time In/Out, Leave,
    // Payslips" bug. Mirrors the same fix in auth/login.php.
    $emp = null;
    if (!$staff) {
        $empStmt = $pdo->prepare(
            "SELECT e.*, p.company_name FROM employees e
             JOIN providers p ON p.id = e.provider_id
             WHERE (e.email = :u OR e.employee_id = :u) AND e.status = 'active'
             LIMIT 1"
        );
        $empStmt->execute([':u' => $identifier]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($emp) {
        $passwordValid = false;
        $usedTemp = false;
        if (!empty($emp['password_hash']) && password_verify($password, $emp['password_hash'])) {
            $passwordValid = true;
        } elseif (!empty($emp['temp_password']) && hash_equals((string)$emp['temp_password'], $password)) {
            $passwordValid = true;
            $usedTemp = true;
        }

        if ($passwordValid) {
            $mustChange = $usedTemp ? true : ((int)($emp['must_change_pwd'] ?? 0) === 1);
            if ($usedTemp && !$emp['must_change_pwd']) {
                $pdo->prepare("UPDATE employees SET must_change_pwd = 1 WHERE id = :id")->execute([':id' => (int)$emp['id']]);
            }
            // See the matching comment in the provider_staff tier above.
            if (!$usedTemp && !empty($emp['temp_password'])) {
                $pdo->prepare("UPDATE employees SET temp_password = NULL WHERE id = :id")->execute([':id' => (int)$emp['id']]);
            }

            return [
                'account_type' => 'employee',
                'must_change_password' => $mustChange,
                'token_claims' => [
                    'sub'         => (int)$emp['id'],
                    'user_type'   => 'portal_employee',
                    'provider_id' => (int)$emp['provider_id'],
                    'staff_type'  => $emp['staff_type'] ?? 'office',
                ],
                'staff' => [
                    'id'            => 0,
                    'employee_id'   => (int)$emp['id'],
                    'employee_code' => $emp['employee_id'],
                    'full_name'     => trim($emp['first_name'] . ' ' . $emp['last_name']),
                    'email'         => $emp['email'],
                    'role'          => 'employee',
                    'department'    => $emp['department'],
                    'staff_type'    => $emp['staff_type'] ?? 'office',
                    'provider_id'   => (int)$emp['provider_id'],
                    'company_name'  => $emp['company_name'],
                ],
            ];
        }
    }

    return null;
}
