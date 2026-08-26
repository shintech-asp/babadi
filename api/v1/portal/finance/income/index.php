<?php
// GET /api/v1/portal/finance/income/
// List income records for the authenticated staff's provider.
// Access: finance role (owner, finance) + Pro tier
//
// TABLE: income_records
//   Columns used: id, provider_id, income_type, amount, income_date,
//   description, received_from, payment_method, reference_number, created_at
//
// Query params: date_from, date_to, page, limit
// Meta includes period total_income.

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'finance');
portal_require_pro((int)$staff['provider_id']);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$provider_id = (int)$staff['provider_id'];
$date_from   = inp('date_from');  // YYYY-MM-DD, optional
$date_to     = inp('date_to');    // YYYY-MM-DD, optional

$where  = ['ir.provider_id = :provider_id'];
$params = [':provider_id' => $provider_id];

if ($date_from !== null && $date_from !== '') {
    $where[]              = 'ir.income_date >= :date_from';
    $params[':date_from'] = $date_from;
}

if ($date_to !== null && $date_to !== '') {
    $where[]            = 'ir.income_date <= :date_to';
    $params[':date_to'] = $date_to;
}

$whereStr = implode(' AND ', $where);

// Total count
$countStmt = db()->prepare("SELECT COUNT(*) FROM income_records ir WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Period total
$sumStmt = db()->prepare("SELECT COALESCE(SUM(ir.amount), 0) FROM income_records ir WHERE $whereStr");
$sumStmt->execute($params);
$total_income = (float)$sumStmt->fetchColumn();

// Records
$sql = "
    SELECT
        ir.id,
        ir.income_type,
        ir.amount,
        ir.income_date,
        ir.description,
        ir.received_from,
        ir.payment_method,
        ir.reference_number,
        ir.created_at
    FROM income_records ir
    WHERE $whereStr
    ORDER BY ir.income_date DESC
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
        'id'               => (int)$r['id'],
        'income_type'      => $r['income_type'] ?? null,
        'amount'           => (float)$r['amount'],
        'income_date'      => $r['income_date'],
        'description'      => $r['description'] ?? null,
        'received_from'    => $r['received_from'] ?? null,
        'payment_method'   => $r['payment_method'] ?? null,
        'reference_number' => $r['reference_number'] ?? null,
        'created_at'       => $r['created_at'] ?? null,
    ];
}, $rows);

ok([
    'data'    => $records,
    'summary' => [
        'total_income' => $total_income,
    ],
    'meta'    => [
        'total'  => $total,
        'page'   => $page,
        'pages'  => (int)ceil($total / $limit),
        'limit'  => $limit,
    ],
]);
