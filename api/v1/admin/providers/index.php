<?php
// api/v1/admin/providers/index.php
// GET — list providers with owner info, booking count, and optional filters.
// Access: super_admin, admin

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$status = inp('status', 'all');
$search = trim((string)inp('search', ''));

// Canonical verification status expression (mirrors the web admin logic)
$verExpr = "COALESCE(NULLIF(p.verification_status, ''), CASE
    WHEN p.status = 'active'   THEN 'approved'
    WHEN p.status = 'rejected' THEN 'rejected'
    ELSE 'pending'
END)";

$where  = '1=1';
$params = [];

if ($status !== 'all' && in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $where .= " AND {$verExpr} = :status";
    $params[':status'] = $status;
}

if ($search !== '') {
    $where .= " AND (p.company_name LIKE :search
                  OR u.email        LIKE :search
                  OR u.first_name   LIKE :search
                  OR u.last_name    LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Total count
$countSql = "SELECT COUNT(*)
             FROM providers p
             JOIN users u ON p.user_id = u.id
             WHERE {$where}";
$cStmt = db()->prepare($countSql);
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();

// Paginated list
$sql = "SELECT
            p.id,
            p.company_name,
            p.city,
            p.status                                    AS provider_status,
            ({$verExpr})                                AS verification_status,
            p.created_at,
            u.id                                        AS owner_id,
            u.first_name,
            u.last_name,
            u.email                                     AS owner_email,
            (SELECT COUNT(*)
               FROM availed_services ab
               WHERE ab.provider_id = p.id)             AS booking_count
        FROM providers p
        JOIN users u ON p.user_id = u.id
        WHERE {$where}
        ORDER BY p.created_at DESC
        LIMIT  :limit
        OFFSET :offset";

$stmt = db()->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r): array {
    return [
        'id'                  => (int)$r['id'],
        'company_name'        => $r['company_name'],
        'city'                => $r['city'],
        'status'              => $r['provider_status'],
        'verification_status' => $r['verification_status'],
        'created_at'          => $r['created_at'],
        'owner'               => [
            'id'         => (int)$r['owner_id'],
            'first_name' => $r['first_name'],
            'last_name'  => $r['last_name'],
            'email'      => $r['owner_email'],
        ],
        'booking_count' => (int)$r['booking_count'],
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
