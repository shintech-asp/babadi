<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$staff = require_portal_role('owner', 'crm');
portal_require_pro($staff['provider_id']);

$pid = (int)$staff['provider_id'];

$title        = req_inp('title', 'Title');
$description  = req_inp('description', 'Description');
$price        = req_inp('price', 'Price');
$pricing_type = req_inp('pricing_type', 'Pricing type');
$category_id  = req_inp('category_id', 'Category');
$is_emergency = (bool)inp('is_emergency', false);

if (!is_numeric($price) || (float)$price <= 0) {
    fail('Price must be a positive number.');
}

$allowed_pricing = ['fixed', 'per_sqft', 'hourly', 'custom'];
if (!in_array($pricing_type, $allowed_pricing, true)) {
    fail('Pricing type must be one of: ' . implode(', ', $allowed_pricing) . '.');
}

// Non-fixed pricing can't be charged upfront — force requires_inspection on,
// mirroring provider/services.php's add handler and api/v1/provider/listings/
// store.php exactly (this endpoint was missing the rule entirely, so an
// Hourly/Custom service created via the CRM portal skipped the mandatory
// Inspection -> Agreement flow and went straight to payment at a meaningless
// placeholder price).
$requires_inspection = ($pricing_type !== 'fixed') ? true : (bool) inp('requires_inspection', false);

$pdo = db();

// Validate category
$catStmt = $pdo->prepare('SELECT id FROM service_categories WHERE id = ? LIMIT 1');
$catStmt->execute([(int)$category_id]);
if (!$catStmt->fetch()) {
    fail('Invalid category_id.');
}

// service_listings was merged into services (see CLAUDE.md's "Recent Work
// Log") — a service created here is now visible and bookable through the
// website too.
$stmt = $pdo->prepare(
    'INSERT INTO services
        (provider_id, service_name, description, price, pricing_type, category_id,
         is_emergency_available, requires_inspection, status, created_at)
     VALUES
        (:provider_id, :service_name, :description, :price, :pricing_type, :category_id,
         :is_emergency, :requires_inspection, :status, NOW())'
);

$stmt->execute([
    ':provider_id' => $pid,
    ':service_name'=> trim($title),
    ':description' => trim($description),
    ':price'       => (float)$price,
    ':pricing_type'=> $pricing_type,
    ':category_id' => (int)$category_id,
    ':is_emergency'=> $is_emergency ? 1 : 0,
    ':requires_inspection' => $requires_inspection ? 1 : 0,
    ':status'      => 'active',
]);

$newId = (int)$pdo->lastInsertId();

ok(['data' => ['id' => $newId]], 201);
