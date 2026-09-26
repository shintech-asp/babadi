<?php
// GET api/v1/portal/me/attendance
// The authenticated employee's own timekeeping history.
//
// Query params: month (YYYY-MM, optional — defaults to current month), page, limit
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$actor = require_portal_actor();
if (!$actor['employee_id']) {
    fail('No linked employee record for this account.', 403);
}

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$month = inp('month');
if ($month === null || $month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$where = "provider_id = :pid AND employee_id = :eid AND work_date LIKE :month";
$params = [':pid' => $actor['provider_id'], ':eid' => $actor['employee_id'], ':month' => $month . '-%'];

$pdo = db();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM timekeeping WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT id, work_date, time_in, time_out, late_minutes, hours_worked, overtime_hours, regular_hours, notes
     FROM timekeeping
     WHERE $where
     ORDER BY work_date DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$todayStmt = $pdo->prepare("SELECT id, time_in, time_out FROM timekeeping WHERE provider_id=:pid AND employee_id=:eid AND work_date=:d LIMIT 1");
$todayStmt->execute([':pid' => $actor['provider_id'], ':eid' => $actor['employee_id'], ':d' => date('Y-m-d')]);
$today = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: null;

ok([
    'data' => $rows,
    'today' => $today ? [
        'time_in'  => $today['time_in'],
        'time_out' => $today['time_out'],
        'clocked_in'  => !empty($today['time_in']),
        'clocked_out' => !empty($today['time_out']),
    ] : ['time_in' => null, 'time_out' => null, 'clocked_in' => false, 'clocked_out' => false],
    'meta' => [
        'month' => $month,
        'page'  => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
    ],
]);
