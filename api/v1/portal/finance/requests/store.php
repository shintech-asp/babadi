<?php
// POST /api/v1/portal/finance/requests/store
// Submit a new finance (budget) request for this provider.
// Access: finance role (owner, finance) + Pro tier
//
// Body (JSON or form):
//   department        — required — budget_requests.department is NOT NULL
//                        with no default; this was missing from the INSERT
//                        entirely, so every mobile submission 500'd under
//                        this DB's STRICT_TRANS_TABLES mode (never caught
//                        before because nothing had exercised this endpoint
//                        against real strict-mode constraints until now).
//   amount            — required, positive numeric
//   description       — required
//   needed_date       — optional, YYYY-MM-DD
//   supporting_notes  — optional, stored as purpose
//
// Inserted with status='pending'; requested_by = staff.id

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
portal_require_pro((int)$staff['provider_id']);

$provider_id = (int)$staff['provider_id'];
$staff_id    = (int)$staff['id'];

$department       = inp('department');
$amount           = inp('amount');
$description      = inp('description');
$needed_date      = inp('needed_date');       // optional, YYYY-MM-DD
$supporting_notes = inp('supporting_notes');  // optional

// Validate required fields
if ($department === null || trim($department) === '')    fail('department is required', 422);
if ($amount === null || $amount === '')                   fail('amount is required', 422);
if ($description === null || trim($description) === '')  fail('description is required', 422);
if ($needed_date !== null && $needed_date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $needed_date) || !DateTime::createFromFormat('Y-m-d', $needed_date))) {
    fail('needed_date must be a valid YYYY-MM-DD date', 422);
}

$amount = (float)$amount;
if ($amount <= 0) fail('amount must be a positive number', 422);

// Combine description and optional supporting_notes into the purpose column.
$purpose_value = trim($description);
if ($supporting_notes !== null && trim($supporting_notes) !== '') {
    $purpose_value .= "\n" . trim($supporting_notes);
}

$stmt = db()->prepare("
    INSERT INTO budget_requests
        (provider_id, department, purpose, amount, status, requested_by, request_date, needed_date, created_at, updated_at)
    VALUES
        (:provider_id, :department, :purpose, :amount, 'pending', :requested_by, CURDATE(), :needed_date, NOW(), NOW())
");

$stmt->execute([
    ':provider_id'  => $provider_id,
    ':department'   => trim($department),
    ':purpose'      => $purpose_value,
    ':amount'       => $amount,
    ':requested_by' => $staff_id,
    ':needed_date'  => ($needed_date !== null && $needed_date !== '') ? $needed_date : null,
]);

$new_id = (int)db()->lastInsertId();

ok([
    'data' => [
        'id'           => $new_id,
        'provider_id'  => $provider_id,
        'department'   => trim($department),
        'purpose'      => $purpose_value,
        'amount'       => $amount,
        'status'       => 'pending',
        'requested_by' => $staff_id,
        'needed_date'  => ($needed_date !== null && $needed_date !== '') ? $needed_date : null,
    ],
    'message' => 'Finance request submitted.',
], 201);
