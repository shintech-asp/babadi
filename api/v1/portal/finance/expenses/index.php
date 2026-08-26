<?php
// GET /api/v1/portal/finance/expenses/
// List expense records for the authenticated staff's provider.
// Access: finance role (owner, finance) + Pro tier
//
// TABLE: expense_records
//   Provider-scoped columns: id, provider_id, expense_type, category, amount,
//   expense_date, description, paid_to, payment_method, receipt_number, created_at
//
// Query params: category, date_from, date_to, page, limit
// Meta includes period total_expenses.

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'finance');
portal_require_pro((int)$staff['provider_id']);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$provider_id = (int)$staff['provider_id'];
$category    = inp('category');    // optional category filter
$date_from   = inp('date_from');   // YYYY-MM-DD, optional
$date_to     = inp('date_to');     // YYYY-MM-DD, optional

$where  = ['er.provider_id = :provider_id'];
$params = [':provider_id' => $provider_id];

if ($category !== null && $category !== '') {
    $where[]          = 'er.category = :category';
    $params[':category'] = $category;
}

if ($date_from !== null && $date_from !== '') {
    $where[]              = 'er.expense_date >= :date_from';
    $params[':date_from'] = $date_from;
}

if ($date_to !== null && $date_to !== '') {
    $where[]            = 'er.expense_date <= :date_to';
    $params[':date_to'] = $date_to;
}

$whereStr = implode(' AND ', $where);

// Total count
$countStmt = db()->prepare("SELECT COUNT(*) FROM expense_records er WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Period total
$sumStmt = db()->prepare("SELECT COALESCE(SUM(er.amount), 0) FROM expense_records er WHERE $whereStr");
$sumStmt->execute($params);
$total_expenses = (float)$sumStmt->fetchColumn();

// Records
$sql = "
    SELECT
        er.id,
        er.expense_type,
        er.category,
        er.amount,
        er.expense_date,
        er.description,
        er.paid_to,
        er.payment_method,
        er.receipt_number,
        er.created_at
    FROM expense_records er
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
        'expense_type'   => $r['expense_type'] ?? null,
        'category'       => $r['category'] ?? null,
        'amount'         => (float)$r['amount'],
        'expense_date'   => $r['expense_date'],
        'description'    => $r['description'] ?? null,
        'paid_to'        => $r['paid_to'] ?? null,
        'payment_method' => $r['payment_method'] ?? null,
        'receipt_number' => $r['receipt_number'] ?? null,
        'created_at'     => $r['created_at'] ?? null,
    ];
}, $rows);

ok([
    'data'    => $records,
    'summary' => [
        'total_expenses' => $total_expenses,
    ],
    'meta'    => [
        'total'  => $total,
        'page'   => $page,
        'pages'  => (int)ceil($total / $limit),
        'limit'  => $limit,
    ],
]);
