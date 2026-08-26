<?php
// POST /api/v1/admin/subscription-plans/store.php
// Create a new subscription plan.
// Access: super_admin only.
// Body: { name, monthly_price, yearly_price }

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');
require_admin_role('super_admin');

$name          = req_inp('name', 'Plan name');
$monthlyPrice  = inp('monthly_price');
$yearlyPrice   = inp('yearly_price');

if ($monthlyPrice === null || $monthlyPrice === '') fail('monthly_price is required');
if ($yearlyPrice  === null || $yearlyPrice  === '') fail('yearly_price is required');

$monthlyPrice = (float)$monthlyPrice;
$yearlyPrice  = (float)$yearlyPrice;

if ($monthlyPrice < 0) fail('monthly_price must be non-negative');
if ($yearlyPrice  < 0) fail('yearly_price must be non-negative');

$stmt = db()->prepare(
    "INSERT INTO subscription_plans (name, monthly_price, yearly_price)
     VALUES (:name, :monthly_price, :yearly_price)"
);
$stmt->execute([
    ':name'          => $name,
    ':monthly_price' => $monthlyPrice,
    ':yearly_price'  => $yearlyPrice,
]);

$id = (int)db()->lastInsertId();

ok([
    'data' => [
        'id'            => $id,
        'name'          => $name,
        'monthly_price' => $monthlyPrice,
        'yearly_price'  => $yearlyPrice,
    ],
], 201);
