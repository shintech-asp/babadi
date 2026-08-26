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
