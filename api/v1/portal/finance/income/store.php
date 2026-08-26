<?php
// POST /api/v1/portal/finance/income/store
// Create a new income record for this provider.
// Access: finance role (owner, finance) + Pro tier
//
// Body (JSON or form): amount, source, description, date
//   amount      — required, positive numeric
//   source      — required, income type / source label (stored as income_type)
//   description — required
//   date        — required, YYYY-MM-DD (stored as income_date)

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
portal_require_pro((int)$staff['provider_id']);

$provider_id = (int)$staff['provider_id'];
$staff_id    = (int)$staff['id'];

$amount      = inp('amount');
$source      = inp('source');
$description = inp('description');
$date        = inp('date');

// Validate required fields
if ($amount === null || $amount === '')      fail('amount is required', 422);
if ($source === null || trim($source) === '') fail('source is required', 422);
if ($description === null || trim($description) === '') fail('description is required', 422);
if ($date === null || trim($date) === '')   fail('date is required', 422);

$amount = (float)$amount;
if ($amount <= 0) fail('amount must be a positive number', 422);

// Basic date format validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('date must be in YYYY-MM-DD format', 422);

$reference_number = 'INC-' . strtoupper(substr(uniqid(), 0, 8));

$stmt = db()->prepare("
    INSERT INTO income_records
        (provider_id, income_type, amount, income_date, description, reference_number, created_at)
    VALUES
        (:provider_id, :income_type, :amount, :income_date, :description, :reference_number, NOW())
");

$stmt->execute([
    ':provider_id'     => $provider_id,
    ':income_type'     => trim($source),
    ':amount'          => $amount,
    ':income_date'     => $date,
    ':description'     => trim($description),
    ':reference_number' => $reference_number,
]);

$new_id = (int)db()->lastInsertId();

ok([
    'data' => [
        'id'               => $new_id,
        'provider_id'      => $provider_id,
        'income_type'      => trim($source),
        'amount'           => $amount,
        'income_date'      => $date,
        'description'      => trim($description),
        'reference_number' => $reference_number,
        'recorded_by'      => $staff_id,
    ],
    'message' => 'Income record created.',
], 201);
