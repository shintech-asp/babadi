<?php
// POST /api/v1/portal/finance/expenses/store
// Create a new expense record for this provider.
// Access: finance role (owner, finance) + Pro tier
//
// Body (JSON or form):
//   amount      — required, positive numeric
//   category    — required, expense category string
//   description — required
//   date        — required, YYYY-MM-DD (stored as expense_date)
//   receipt_url — optional, URL or reference string for receipt

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
portal_require_pro((int)$staff['provider_id']);

$provider_id = (int)$staff['provider_id'];
$staff_id    = (int)$staff['id'];

$amount      = inp('amount');
$category    = inp('category');
$description = inp('description');
$date        = inp('date');
$receipt_url = inp('receipt_url');   // optional

// Validate required fields
if ($amount === null || $amount === '')        fail('amount is required', 422);
if ($category === null || trim($category) === '') fail('category is required', 422);
if ($description === null || trim($description) === '') fail('description is required', 422);
if ($date === null || trim($date) === '')     fail('date is required', 422);

$amount = (float)$amount;
if ($amount <= 0) fail('amount must be a positive number', 422);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !DateTime::createFromFormat('Y-m-d', $date)) {
    fail('date must be a valid YYYY-MM-DD date', 422);
}

// receipt_number is varchar(100) — a real URL would exceed that and 500
// under strict mode instead of failing cleanly.
if ($receipt_url !== null && strlen($receipt_url) > 100) {
    fail('receipt_url must be 100 characters or fewer', 422);
}

$reference_number = 'EXP-' . strtoupper(substr(uniqid(), 0, 8));

// expense_records.expense_type is varchar(100) NOT NULL with no default (see
// provider-portal/expenses.php's own required "Expense Type *" field) — this
// INSERT never included it, so every call 500'd under STRICT_TRANS_TABLES
// (same bug class as the requests/store.php `department` fix elsewhere in
// this session). This endpoint only ever collected one free-text field
// (called `category` here), so it now fills both columns from it rather
// than adding a second mobile-only field the web doesn't ask for either.
$stmt = db()->prepare("
    INSERT INTO expense_records
        (provider_id, expense_type, category, amount, expense_date, description, receipt_number, created_at)
    VALUES
        (:provider_id, :expense_type, :category, :amount, :expense_date, :description, :receipt_number, NOW())
");

$stmt->execute([
    ':provider_id'     => $provider_id,
    ':expense_type'    => trim($category),
    ':category'        => trim($category),
    ':amount'          => $amount,
    ':expense_date'    => $date,
    ':description'     => trim($description),
    ':receipt_number'  => $receipt_url !== null ? trim($receipt_url) : null,
]);

$new_id = (int)db()->lastInsertId();

ok([
    'data' => [
        'id'             => $new_id,
        'provider_id'    => $provider_id,
        'category'       => trim($category),
        'amount'         => $amount,
        'expense_date'   => $date,
        'description'    => trim($description),
        'receipt_number' => $receipt_url !== null ? trim($receipt_url) : null,
        'recorded_by'    => $staff_id,
    ],
    'message' => 'Expense record created.',
], 201);
