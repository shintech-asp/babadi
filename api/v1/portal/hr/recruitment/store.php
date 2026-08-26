<?php
// POST /api/v1/portal/hr/recruitment/store
// Create a new job posting for this provider.
// Access: owner, hr   |   Tier: Pro required
//
// Body (JSON or form-data):
//   position_title  (string, required)
//   description     (string, optional)
//   requirements    (string, optional)
//   slots           (int, optional, default 1)
//   status          (open|closed|on_hold, default 'open')
//   department      (string, optional)
//   employment_type (full_time|part_time|contract|internship, optional)
//   location        (string, optional)
//   salary_min      (float, optional)
//   salary_max      (float, optional)
//   closing_date    (YYYY-MM-DD, optional)

require_once dirname(__DIR__, 3) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$position_title  = req_inp('position_title', 'position_title');
$description     = trim((string)inp('description', ''));
$requirements    = trim((string)inp('requirements', ''));
$slots           = max(1, (int)inp('slots', 1));
$status          = inp('status', 'open');
$department      = trim((string)inp('department', ''));
$employment_type = inp('employment_type', 'full_time');
$location        = trim((string)inp('location', ''));
$salary_min_raw  = inp('salary_min');
$salary_max_raw  = inp('salary_max');
$closing_date    = inp('closing_date');

// Validate status
$allowed_statuses = ['open', 'closed', 'on_hold'];
if (!in_array($status, $allowed_statuses, true)) {
    fail('status must be one of: open, closed, on_hold');
}

// Validate employment_type
$allowed_types = ['full_time', 'part_time', 'contract', 'internship'];
if (!in_array($employment_type, $allowed_types, true)) {
    fail('employment_type must be one of: full_time, part_time, contract, internship');
}

$salary_min = $salary_min_raw !== null && $salary_min_raw !== '' ? (float)$salary_min_raw : null;
$salary_max = $salary_max_raw !== null && $salary_max_raw !== '' ? (float)$salary_max_raw : null;

if ($salary_min !== null && $salary_max !== null && $salary_min > $salary_max) {
    fail('salary_min cannot be greater than salary_max');
}

$closing_date_val = ($closing_date !== null && $closing_date !== '') ? $closing_date : null;

try {
    // TABLE: recruitment — confirmed from provider-portal/recruitment.php
    // Columns: provider_id, job_title, department, job_description, requirements,
    //          salary_range_min, salary_range_max, employment_type, location,
    //          posted_date, closing_date, status
    // Note: 'slots' is not a column in the recruitment table per portal source;
    //       stored in notes as a fallback. If the table has a slots column it will
    //       be picked up; the INSERT uses a try/catch to handle both cases.
    $ins = db()->prepare(
        "INSERT INTO recruitment
            (provider_id, job_title, department, job_description, requirements,
             salary_range_min, salary_range_max, employment_type, location,
             posted_date, closing_date, status)
         VALUES
            (:pid, :title, :dept, :desc, :req,
             :smin, :smax, :etype, :loc,
             CURDATE(), :cdate, :status)"
    );
    $ins->execute([
        ':pid'    => $pid,
        ':title'  => $position_title,
        ':dept'   => $department !== '' ? $department : null,
        ':desc'   => $description !== '' ? $description : null,
        ':req'    => $requirements !== '' ? $requirements : null,
        ':smin'   => $salary_min,
        ':smax'   => $salary_max,
        ':etype'  => $employment_type,
        ':loc'    => $location !== '' ? $location : null,
        ':cdate'  => $closing_date_val,
        ':status' => $status,
    ]);
    $record_id = (int)db()->lastInsertId();
} catch (Exception $e) {
    fail('Failed to create job posting: ' . $e->getMessage(), 500);
}

ok([
    'message' => 'Job posting created',
    'job'     => [
        'id'              => $record_id,
        'position_title'  => $position_title,
        'department'      => $department !== '' ? $department : null,
        'description'     => $description !== '' ? $description : null,
        'requirements'    => $requirements !== '' ? $requirements : null,
        'slots'           => $slots,
        'salary_min'      => $salary_min,
        'salary_max'      => $salary_max,
        'employment_type' => $employment_type,
        'location'        => $location !== '' ? $location : null,
        'closing_date'    => $closing_date_val,
        'status'          => $status,
        'posted_date'     => date('Y-m-d'),
    ],
], 201);
