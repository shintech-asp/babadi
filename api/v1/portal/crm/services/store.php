<?php
require_once dirname(__DIR__, 3) . '/_bootstrap.php';

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

$pdo = db();

// Validate category
$catStmt = $pdo->prepare('SELECT id FROM service_categories WHERE id = ? LIMIT 1');
$catStmt->execute([(int)$category_id]);
if (!$catStmt->fetch()) {
    fail('Invalid category_id.');
}

$stmt = $pdo->prepare(
    'INSERT INTO service_listings
        (provider_id, title, description, price, pricing_type, category_id,
         is_emergency_available, status, created_at)
     VALUES
        (:provider_id, :title, :description, :price, :pricing_type, :category_id,
         :is_emergency, :status, NOW())'
);

$stmt->execute([
    ':provider_id' => $pid,
    ':title'       => trim($title),
    ':description' => trim($description),
    ':price'       => (float)$price,
    ':pricing_type'=> $pricing_type,
    ':category_id' => (int)$category_id,
    ':is_emergency'=> $is_emergency ? 1 : 0,
    ':status'      => 'active',
]);

$newId = (int)$pdo->lastInsertId();

ok(['data' => ['id' => $newId]], 201);
