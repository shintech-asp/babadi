<?php
// GET /api/v1/admin/hr/recruitment/
// List job postings and applicants across all providers with optional filters.
// Access: super_admin, admin, hr

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin', 'admin', 'hr');

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

// Optional filters
$provider_id = inp('provider_id') !== null ? (int)inp('provider_id') : null;
$status      = inp('status');   // open | closed | on_hold

$where  = ['1=1'];
$params = [];

if ($provider_id !== null) {
    $where[]                = 'r.provider_id = :provider_id';
    $params[':provider_id'] = $provider_id;
}

if ($status !== null && $status !== '') {
    $where[]         = 'r.status = :status';
    $params[':status'] = $status;
}

$whereStr = implode(' AND ', $where);

// TABLE: recruitment — confirmed from provider-portal/recruitment.php
// TABLE: applicants  — confirmed from provider-portal/recruitment.php
$countSql  = "SELECT COUNT(*) FROM recruitment r WHERE $whereStr";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT
        r.id,
        r.provider_id,
        p.company_name                                           AS provider_name,
        r.job_title,
        r.department,
        r.job_description,
        r.requirements,
        r.salary_range_min,
        r.salary_range_max,
        r.employment_type,
        r.location,
        r.posted_date,
        r.closing_date,
        r.status,
        r.created_at,
        (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id = r.id)          AS total_applicants,
        (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id = r.id
                                             AND a.status = 'pending')             AS pending_applicants,
        (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id = r.id
                                             AND a.status = 'hired')               AS hired_applicants
    FROM recruitment r
    LEFT JOIN providers p ON p.id = r.provider_id
    WHERE $whereStr
    ORDER BY r.created_at DESC
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
        'id'                  => (int)$r['id'],
        'provider_id'         => (int)$r['provider_id'],
        'provider_name'       => $r['provider_name'],
        'job_title'           => $r['job_title'],
        'department'          => $r['department'] ?? null,
        'job_description'     => $r['job_description'] ?? null,
        'requirements'        => $r['requirements'] ?? null,
        'salary_range_min'    => $r['salary_range_min'] !== null ? (float)$r['salary_range_min'] : null,
        'salary_range_max'    => $r['salary_range_max'] !== null ? (float)$r['salary_range_max'] : null,
        'employment_type'     => $r['employment_type'] ?? null,
        'location'            => $r['location'] ?? null,
        'posted_date'         => $r['posted_date'] ?? null,
        'closing_date'        => $r['closing_date'] ?? null,
        'status'              => $r['status'],   // open | closed | on_hold
        'created_at'          => $r['created_at'],
        'applicant_counts'    => [
            'total'   => (int)$r['total_applicants'],
            'pending' => (int)$r['pending_applicants'],
            'hired'   => (int)$r['hired_applicants'],
        ],
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
