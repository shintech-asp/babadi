<?php
// GET /api/v1/portal/hr/recruitment/
// List job postings for this provider, with inline applicant counts.
// Optionally include the applicant list for a specific job posting.
// Access: owner, hr   |   Tier: Pro required
//
// Query params:
//   status    (open|closed|on_hold, optional) — filter job postings by status
//   job_id    (int, optional) — when supplied, also returns applicants for that job
//   page      (default 1)
//   limit     (default 20, max 100)  — applies to job postings list

require_once dirname(__DIR__, 3) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

['page' => $page, 'limit' => $limit, 'offset' => $offset] = paginate();

$status = inp('status');
$job_id = inp('job_id') !== null && inp('job_id') !== '' ? (int)inp('job_id') : null;

// TABLE: recruitment — confirmed from provider-portal/recruitment.php
// TABLE: applicants  — confirmed from provider-portal/recruitment.php
$where  = ['r.provider_id = :provider_id'];
$params = [':provider_id' => $pid];

if ($status !== null && $status !== '') {
    $where[]          = 'r.status = :status';
    $params[':status'] = $status;
}

$whereStr = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM recruitment r WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "
    SELECT
        r.id,
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
        (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id = r.id)                    AS total_applicants,
        (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id = r.id AND a.status = 'pending')  AS pending_applicants,
        (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id = r.id AND a.status = 'hired')    AS hired_applicants
    FROM recruitment r
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

$jobs = array_map(function (array $r): array {
    return [
        'id'              => (int)$r['id'],
        'job_title'       => $r['job_title'],
        'department'      => $r['department'] ?? null,
        'job_description' => $r['job_description'] ?? null,
        'requirements'    => $r['requirements'] ?? null,
        'salary_range_min'=> $r['salary_range_min'] !== null ? (float)$r['salary_range_min'] : null,
        'salary_range_max'=> $r['salary_range_max'] !== null ? (float)$r['salary_range_max'] : null,
        'employment_type' => $r['employment_type'] ?? null,
        'location'        => $r['location'] ?? null,
        'posted_date'     => $r['posted_date'] ?? null,
        'closing_date'    => $r['closing_date'] ?? null,
        'status'          => $r['status'],  // open | closed | on_hold
        'created_at'      => $r['created_at'],
        'applicant_counts' => [
            'total'   => (int)$r['total_applicants'],
            'pending' => (int)$r['pending_applicants'],
            'hired'   => (int)$r['hired_applicants'],
        ],
    ];
}, $rows);

$response = [
    'data' => $jobs,
    'meta' => [
        'total' => $total,
        'page'  => $page,
        'pages' => (int)ceil($total / $limit),
    ],
];

// Optionally fetch applicants for a specific job belonging to this provider
if ($job_id !== null) {
    $jobCheckStmt = db()->prepare(
        "SELECT id FROM recruitment WHERE id = :jid AND provider_id = :pid LIMIT 1"
    );
    $jobCheckStmt->execute([':jid' => $job_id, ':pid' => $pid]);
    if (!$jobCheckStmt->fetch()) {
        fail('Job posting not found', 404);
    }

    $appStmt = db()->prepare(
        "SELECT id, first_name, last_name, email, phone, status, notes,
                application_date, interview_date, resume_path
         FROM applicants
         WHERE recruitment_id = :jid
         ORDER BY application_date DESC"
    );
    $appStmt->execute([':jid' => $job_id]);
    $applicants = $appStmt->fetchAll(PDO::FETCH_ASSOC);

    $response['applicants'] = array_map(function (array $a): array {
        return [
            'id'               => (int)$a['id'],
            'first_name'       => $a['first_name'],
            'last_name'        => $a['last_name'],
            'email'            => $a['email'],
            'phone'            => $a['phone'] ?? null,
            'status'           => $a['status'],  // pending|reviewed|interviewed|hired|rejected
            'notes'            => $a['notes'] ?? null,
            'application_date' => $a['application_date'] ?? null,
            'interview_date'   => $a['interview_date'] ?? null,
            'resume_path'      => $a['resume_path'] ?? null,
        ];
    }, $applicants);

    $response['applicants_for_job_id'] = $job_id;
}

ok($response);
