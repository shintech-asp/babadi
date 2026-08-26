<?php
// api/v1/admin/logs/index.php
// GET — paginated admin activity log
// Access: super_admin, admin
//
// Table assumed: admin_logs
// Confirmed columns: id, admin_id, action, details, ip_address, created_at

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin');

$pdo = db();

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// ── Filters ───────────────────────────────────────────────────────────────────
$admin_id  = inp('admin_id');
$action    = inp('action');
$date_from = inp('date_from');
$date_to   = inp('date_to');

$conditions = [];
$params     = [];

if ($admin_id !== null && $admin_id !== '') {
    $conditions[]           = 'al.admin_id = :admin_id';
    $params[':admin_id']    = (int) $admin_id;
}

if ($action !== null && $action !== '') {
    // Partial, case-insensitive match so callers can filter by keyword ("LOGIN", "DELETE", etc.)
    $conditions[]       = 'al.action LIKE :action';
    $params[':action']  = '%' . $action . '%';
}

if ($date_from !== null && $date_from !== '') {
    $conditions[]              = 'al.created_at >= :date_from';
    $params[':date_from']      = $date_from . ' 00:00:00';
}

if ($date_to !== null && $date_to !== '') {
    $conditions[]              = 'al.created_at <= :date_to';
    $params[':date_to']        = $date_to . ' 23:59:59';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── Count ─────────────────────────────────────────────────────────────────────
$countSql  = "SELECT COUNT(*) FROM admin_logs al {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// ── List ──────────────────────────────────────────────────────────────────────
// Join admin_users for the actor's display name / role.
$sql = "SELECT
            al.id,
            al.admin_id,
            au.username       AS admin_username,
            au.full_name      AS admin_full_name,
            au.role           AS admin_role,
            al.action,
            al.details,
            al.ip_address,
            al.created_at
        FROM admin_logs al
        LEFT JOIN admin_users au ON au.id = al.admin_id
        {$where}
        ORDER BY al.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['id']       = (int) $row['id'];
    $row['admin_id'] = (int) $row['admin_id'];
}
unset($row);

ok([
    'data' => $rows,
    'meta' => [
        'total' => $total,
        'page'  => $page,
        'pages' => (int) ceil($total / $limit),
    ],
]);
