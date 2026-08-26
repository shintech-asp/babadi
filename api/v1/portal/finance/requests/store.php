<?php
// POST /api/v1/portal/finance/requests/store
// Submit a new finance (budget) request for this provider.
// Access: finance role (owner, finance) + Pro tier
//
// Body (JSON or form):
//   request_type      — required, e.g. "budget" | "reimbursement" | "purchase" | etc.
//   amount            — required, positive numeric
//   description       — required
//   supporting_notes  — optional, stored as purpose
//
// Inserted with status='pending'; requested_by = staff.id

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
portal_require_pro((int)$staff['provider_id']);

$provider_id = (int)$staff['provider_id'];
$staff_id    = (int)$staff['id'];

$request_type     = inp('request_type');
$amount           = inp('amount');
$description      = inp('description');
$supporting_notes = inp('supporting_notes');  // optional

// Validate required fields
if ($request_type === null || trim($request_type) === '') fail('request_type is required', 422);
if ($amount === null || $amount === '')                   fail('amount is required', 422);
if ($description === null || trim($description) === '')  fail('description is required', 422);

$amount = (float)$amount;
if ($amount <= 0) fail('amount must be a positive number', 422);

// Build a short auto-title from request_type and date for display convenience
$title = ucfirst(str_replace(['_', '-'], ' ', trim($request_type))) . ' Request';

// Combine description and optional supporting_notes into the purpose column.
$purpose_value = trim($description);
if ($supporting_notes !== null && trim($supporting_notes) !== '') {
    $purpose_value .= "\n" . trim($supporting_notes);
}

$stmt = db()->prepare("
    INSERT INTO budget_requests
        (provider_id, purpose, amount, status, requested_by, request_date, created_at, updated_at)
    VALUES
        (:provider_id, :purpose, :amount, 'pending', :requested_by, CURDATE(), NOW(), NOW())
");

$stmt->execute([
    ':provider_id'  => $provider_id,
    ':purpose'      => $purpose_value,
    ':amount'       => $amount,
    ':requested_by' => $staff_id,
]);

$new_id = (int)db()->lastInsertId();

ok([
    'data' => [
        'id'           => $new_id,
        'provider_id'  => $provider_id,
        'purpose'      => $purpose_value,
        'amount'       => $amount,
        'status'       => 'pending',
        'requested_by' => $staff_id,
    ],
    'message' => 'Finance request submitted.',
], 201);
