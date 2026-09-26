<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$staff = require_portal_role('owner', 'crm');
portal_require_pro($staff['provider_id']);

$pid = (int)$staff['provider_id'];
$pdo = db();

$id = (int)req_inp('id', 'Listing ID');

// Verify ownership. service_listings was merged into services (see
// CLAUDE.md's "Recent Work Log").
$stmt = $pdo->prepare('SELECT id FROM services WHERE id = :id AND provider_id = :pid LIMIT 1');
$stmt->execute([':id' => $id, ':pid' => $pid]);
if (!$stmt->fetch()) {
    fail('Listing not found.', 404);
}

$fields = [];
$params = [':id' => $id, ':pid' => $pid];

$title = inp('title');
if ($title !== null) {
    $fields[] = 'service_name = :service_name';
    $params[':service_name'] = trim($title);
}

$description = inp('description');
if ($description !== null) {
    $fields[] = 'description = :description';
    $params[':description'] = trim($description);
}

$price = inp('price');
if ($price !== null) {
    if (!is_numeric($price) || (float)$price <= 0) {
        fail('Price must be a positive number.');
    }
    $fields[] = 'price = :price';
    $params[':price'] = (float)$price;
}

$pricing_type = inp('pricing_type');
if ($pricing_type !== null) {
    $allowed_pricing = ['fixed', 'per_sqft', 'hourly', 'custom'];
    if (!in_array($pricing_type, $allowed_pricing, true)) {
        fail('Pricing type must be one of: ' . implode(', ', $allowed_pricing) . '.');
    }
    $fields[] = 'pricing_type = :pricing_type';
    $params[':pricing_type'] = $pricing_type;
}

$status = inp('status');
if ($status !== null) {
    if (!in_array($status, ['active', 'inactive'], true)) {
        fail('Status must be active or inactive.');
    }
    $fields[] = 'status = :status';
    $params[':status'] = $status;
}

$is_emergency = inp('is_emergency');
if ($is_emergency !== null) {
    $fields[] = 'is_emergency_available = :is_emergency';
    $params[':is_emergency'] = $is_emergency ? 1 : 0;
}

if (empty($fields)) {
    fail('No fields provided to update.');
}

$fields[] = 'updated_at = NOW()';

$sql = 'UPDATE services SET ' . implode(', ', $fields)
     . ' WHERE id = :id AND provider_id = :pid';

$pdo->prepare($sql)->execute($params);

ok(['message' => 'Listing updated.']);
