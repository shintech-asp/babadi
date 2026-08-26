<?php
// GET /api/v1/admin/finance/expenses/
// List expense records across all providers with optional filters.
// Access: super_admin, admin, finance

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin', 'finance');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// Optional filters
$provider_id = inp('provider_id') !== null ? (int)inp('provider_id') : null;
$date_from   = inp('date_from');   // YYYY-MM-DD
$date_to     = inp('date_to');     // YYYY-MM-DD

$where  = ['1=1'];
$params = [];

if ($provider_id !== null) {
    $where[]                = 'er.provider_id = :provider_id';
    $params[':provider_id'] = $provider_id;
}

// TABLE: expense_records — confirmed from provider-portal/expenses.php
// expense_date column confirmed from provider-portal/expenses.php
if ($date_from !== null && $date_from !== '') {
    $where[]              = 'er.expense_date >= :date_from';
    $params[':date_from'] = $date_from;
}

if ($date_to !== null && $date_to !== '') {
    $where[]            = 'er.expense_date <= :date_to';
    $params[':date_to'] = $date_to;
}

$whereStr = implode(' AND ', $where);

// Count
$countSql  = "SELECT COUNT(*) FROM expense_records er WHERE $whereStr";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Records
$sql = "
    SELECT
        er.id,
        er.provider_id,
        p.company_name       AS provider_name,
        er.expense_type,
        er.amount,
        er.expense_date,
        er.description,
        er.paid_to,
        er.payment_method,
        er.receipt_number,
        er.category,
        er.created_at
    FROM expense_records er
    LEFT JOIN providers p ON p.id = er.provider_id
    WHERE $whereStr
    ORDER BY er.expense_date DESC
    LIMIT :limit OFFSET :offset
";

$stmt = db()->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$records = array_map(function (array $r): array {
    return [
        'id'             => (int)$r['id'],
        'provider_id'    => $r['provider_id'] !== null ? (int)$r['provider_id'] : null,
        'provider_name'  => $r['provider_name'],
        'expense_type'   => $r['expense_type'] ?? null,
        'amount'         => (float)$r['amount'],
        'expense_date'   => $r['expense_date'],
        'description'    => $r['description'] ?? null,
        'paid_to'        => $r['paid_to'] ?? null,
        'payment_method' => $r['payment_method'] ?? null,
        'receipt_number' => $r['receipt_number'] ?? null,
        'category'       => $r['category'] ?? null,
        'created_at'     => $r['created_at'] ?? null,
    ];
}, $rows);

ok([
    'data' => $records,
    'meta' => [
        'total' => $total,
        'page'  => $page,
        'pages' => (int)ceil($total / $limit),
    ],
]);
