<?php
// POST /api/v1/admin/subscription-plans/update.php
// Update an existing subscription plan.
// Access: super_admin only.
// Body: { id, monthly_price, yearly_price [, name] }

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
require_admin_role('super_admin');

$id           = (int)req_inp('id', 'Plan id');
$monthlyPrice = inp('monthly_price');
$yearlyPrice  = inp('yearly_price');

if ($id <= 0) fail('id must be a positive integer');
if ($monthlyPrice === null || $monthlyPrice === '') fail('monthly_price is required');
if ($yearlyPrice  === null || $yearlyPrice  === '') fail('yearly_price is required');

$monthlyPrice = (float)$monthlyPrice;
$yearlyPrice  = (float)$yearlyPrice;

if ($monthlyPrice < 0) fail('monthly_price must be non-negative');
if ($yearlyPrice  < 0) fail('yearly_price must be non-negative');

// Verify plan exists.
$check = db()->prepare("SELECT id, name FROM subscription_plans WHERE id = :id LIMIT 1");
$check->execute([':id' => $id]);
$plan = $check->fetch(PDO::FETCH_ASSOC);
if (!$plan) fail('Subscription plan not found', 404);

// name is optional — keep existing if not provided.
$name = inp('name');
$newName = ($name !== null && trim((string)$name) !== '') ? trim((string)$name) : $plan['name'];

$stmt = db()->prepare(
    "UPDATE subscription_plans
     SET name = :name, monthly_price = :monthly_price, yearly_price = :yearly_price
     WHERE id = :id"
);
$stmt->execute([
    ':name'          => $newName,
    ':monthly_price' => $monthlyPrice,
    ':yearly_price'  => $yearlyPrice,
    ':id'            => $id,
]);

ok([
    'data' => [
        'id'            => $id,
        'name'          => $newName,
        'monthly_price' => $monthlyPrice,
        'yearly_price'  => $yearlyPrice,
    ],
]);
