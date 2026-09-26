<?php
// api/v1/admin/bookings/index.php
// GET — paginated booking list with optional filters
// Access: super_admin, admin

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin');

$pdo = db();

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// ── Filters ───────────────────────────────────────────────────────────────────
$status      = inp('status');
$provider_id = inp('provider_id');
$seeker_id   = inp('seeker_id');
$date_from   = inp('date_from');
$date_to     = inp('date_to');

$conditions = [];
$params     = [];

if ($status !== null && $status !== '') {
    $allowed_statuses = [
        'pending', 'accepted', 'declined', 'cancelled',
        'starting', 'on_going', 'completed', 'archived',
    ];
    if (!in_array($status, $allowed_statuses, true)) {
        error_response(422, 'Invalid status value.');
    }
    $conditions[]           = 'av.status = :status';
    $params[':status']      = $status;
}

if ($provider_id !== null && $provider_id !== '') {
    $conditions[]              = 'av.provider_id = :provider_id';
    $params[':provider_id']    = (int) $provider_id;
}

if ($seeker_id !== null && $seeker_id !== '') {
    // seeker_user_id is the canonical FK; user_id is a legacy alias kept for
    // older rows inserted before the column rename
    $conditions[]           = '(av.seeker_user_id = :seeker_id OR av.user_id = :seeker_id2)';
    $params[':seeker_id']   = (int) $seeker_id;
    $params[':seeker_id2']  = (int) $seeker_id;
}

if ($date_from !== null && $date_from !== '') {
    $conditions[]              = 'av.created_at >= :date_from';
    $params[':date_from']      = $date_from . ' 00:00:00';
}

if ($date_to !== null && $date_to !== '') {
    $conditions[]              = 'av.created_at <= :date_to';
    $params[':date_to']        = $date_to . ' 23:59:59';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── Count ─────────────────────────────────────────────────────────────────────
$countSql  = "SELECT COUNT(*)
              FROM availed_services av
              {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// ── List ──────────────────────────────────────────────────────────────────────
$sql = "SELECT
            av.id,
            av.status,
            av.preferred_date,
            av.preferred_time,
            av.total_amount,
            av.downpayment_amount,
            av.payment_method,
            av.payment_status,
            av.provider_control_number,
            av.created_at,
            av.updated_at,
            -- Seeker
            COALESCE(av.seeker_user_id, av.user_id)  AS seeker_user_id,
            u_s.first_name   AS seeker_first_name,
            u_s.last_name    AS seeker_last_name,
            u_s.email        AS seeker_email,
            u_s.phone        AS seeker_phone,
            -- Provider
            av.provider_id,
            p.company_name   AS provider_company_name,
            p.logo_url       AS provider_logo_url,
            u_p.first_name   AS provider_first_name,
            u_p.last_name    AS provider_last_name,
            u_p.email        AS provider_email,
            -- Listing
            av.service_id,
            sl.service_name  AS listing_title
        FROM availed_services av
        JOIN users u_s     ON u_s.id = COALESCE(av.seeker_user_id, av.user_id)
        JOIN providers p   ON p.id   = av.provider_id
        JOIN users u_p     ON u_p.id = p.user_id
        LEFT JOIN services sl ON sl.id = av.service_id
        {$where}
        ORDER BY av.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cast numeric IDs and amounts
foreach ($rows as &$row) {
    $row['id']               = (int)  $row['id'];
    $row['seeker_user_id']   = (int)  $row['seeker_user_id'];
    $row['provider_id']      = (int)  $row['provider_id'];
    $row['service_id']       = $row['service_id'] !== null ? (int) $row['service_id'] : null;
    $row['total_amount']     = $row['total_amount']       !== null ? (float) $row['total_amount']       : null;
    $row['downpayment_amount'] = $row['downpayment_amount'] !== null ? (float) $row['downpayment_amount'] : null;
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
