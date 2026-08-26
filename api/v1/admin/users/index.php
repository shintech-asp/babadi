<?php
// api/v1/admin/users/index.php
// GET  /api/v1/admin/users/
// Returns a paginated, filterable list of users (no passwords).
// Access: super_admin, admin

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim((string)inp('search', ''));
$user_type = trim((string)inp('user_type', ''));
$status    = trim((string)inp('status', ''));

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]              = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search)";
    $params[':search']    = '%' . $search . '%';
}

if (in_array($user_type, ['seeker', 'provider'], true)) {
    $where[]              = "u.user_type = :user_type";
    $params[':user_type'] = $user_type;
}

if (in_array($status, ['active', 'banned', 'suspended'], true)) {
    $where[]              = "u.status = :status";
    $params[':status']    = $status;
}

$whereSQL = implode(' AND ', $where);

// ── Count ─────────────────────────────────────────────────────────────────────
$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM users u WHERE {$whereSQL}"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// ── Rows ──────────────────────────────────────────────────────────────────────
$rowStmt = db()->prepare(
    "SELECT
         u.id,
         u.first_name,
         u.last_name,
         u.email,
         u.user_type,
         u.status,
         u.phone,
         u.city,
         u.email_verified,
         u.created_at,
         p.company_name
     FROM users u
     LEFT JOIN providers p ON p.user_id = u.id
     WHERE {$whereSQL}
     ORDER BY u.created_at DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $k => $v) {
    $rowStmt->bindValue($k, $v);
}
$rowStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$rowStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$rowStmt->execute();
$rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $u): array {
    return [
        'id'             => (int)$u['id'],
        'first_name'     => $u['first_name'],
        'last_name'      => $u['last_name'],
        'email'          => $u['email'],
        'user_type'      => $u['user_type'],
        'status'         => $u['status'],
        'phone'          => $u['phone'],
        'city'           => $u['city'],
        'email_verified' => (bool)$u['email_verified'],
        'company_name'   => $u['company_name'],   // null for seekers
        'created_at'     => $u['created_at'],
    ];
}, $rows);

ok([
    'data' => $data,
    'meta' => [
        'total' => $total,
        'page'  => $page,
        'pages' => (int)ceil($total / $limit),
    ],
]);
