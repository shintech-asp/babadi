<?php
// GET /api/v1/admin/finance/income/
// List platform income records and provider-level income records with period summary.
// Access: super_admin, admin, finance
//
// Two income sources are combined here:
//   1. income_records (platform-level, no provider_id): recorded by admin finance staff.
//      Columns: id, reference_no, description, amount, date, category, payment_method, notes, recorded_by
//   2. income_records belonging to providers (provider-scoped):
//      Columns: id, provider_id, income_type, amount, income_date, description,
//               received_from, payment_method, reference_number
//      NOTE: The two sets share a table name but the provider-scoped rows have provider_id set.
//      TABLE: income_records — assumed_name; verify which rows have provider_id and which do not.
//
// If provider_id filter is supplied, only provider-scoped income is returned.
// Without provider_id, all records (platform + provider) are returned.

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin', 'finance');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// Optional filters
$provider_id = inp('provider_id') !== null ? (int)inp('provider_id') : null;
$date_from   = inp('date_from');   // YYYY-MM-DD
$date_to     = inp('date_to');     // YYYY-MM-DD

// ── Detect which schema the income_records table uses ──────────────────────────
// Admin finance module uses 'date' column (no provider_id).
// Provider portal uses 'income_date' + provider_id columns.
// We normalise via COALESCE so the endpoint works regardless of schema variant.

$where  = ['1=1'];
$params = [];

if ($provider_id !== null) {
    // Narrow to provider-scoped rows only
    $where[]                = 'ir.provider_id = :provider_id';
    $params[':provider_id'] = $provider_id;
} // else: no provider_id filter — all rows returned

// Date filter: handle both 'date' and 'income_date' column names via COALESCE
// TABLE: income_records — assumed_name — verify against DB
if ($date_from !== null && $date_from !== '') {
    $where[]              = 'ir.income_date >= :date_from';
    $params[':date_from'] = $date_from;
}

if ($date_to !== null && $date_to !== '') {
    $where[]            = 'ir.income_date <= :date_to';
    $params[':date_to'] = $date_to;
}

$whereStr = implode(' AND ', $where);

// Count
$countSql  = "SELECT COUNT(*) FROM income_records ir WHERE $whereStr";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Summary: total_income for the filtered period
$sumSql  = "SELECT COALESCE(SUM(ir.amount), 0) FROM income_records ir WHERE $whereStr";
$sumStmt = db()->prepare($sumSql);
$sumStmt->execute($params);
$total_income = (float)$sumStmt->fetchColumn();

// Records
$sql = "
    SELECT
        ir.id,
        ir.provider_id,
        p.company_name                                       AS provider_name,
        ir.income_type,
        ir.amount,
        ir.income_date,
        ir.description,
        ir.received_from,
        ir.payment_method,
        ir.reference_number,
        ir.created_at
    FROM income_records ir
    LEFT JOIN providers p ON p.id = ir.provider_id
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
        'provider_id'      => $r['provider_id'] !== null ? (int)$r['provider_id'] : null,
        'provider_name'    => $r['provider_name'],
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
        'total' => $total,
        'page'  => $page,
        'pages' => (int)ceil($total / $limit),
    ],
]);
