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
$stmt = $pdo->prepare('SELECT * FROM services WHERE id = :id AND provider_id = :pid LIMIT 1');
$stmt->execute([':id' => $id, ':pid' => $pid]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$listing) {
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

$equipment_notes = inp('equipment_notes');
if ($equipment_notes !== null) {
    $fields[] = 'equipment_notes = :equipment_notes';
    $params[':equipment_notes'] = trim($equipment_notes) !== '' ? trim($equipment_notes) : null;
}

$duration = inp('duration');
if ($duration !== null) {
    $duration = trim((string)$duration);
    if ($duration === '' || !ctype_digit($duration) || (int)$duration <= 0) {
        fail('A valid duration is required.');
    }
    $fields[] = 'duration = :duration';
    $params[':duration'] = (int)$duration;
}

$duration_unit = inp('duration_unit');
if ($duration_unit !== null) {
    $duration_unit = trim($duration_unit);
    if (!in_array($duration_unit, ['minute', 'hour', 'day'], true)) {
        fail('duration_unit must be minute, hour, or day.');
    }
    $fields[] = 'duration_unit = :duration_unit';
    $params[':duration_unit'] = $duration_unit;
}

// Non-fixed pricing can't be charged upfront — force requires_inspection on
// regardless of what was posted, using whichever pricing_type is in effect
// after this update (the new value if provided, else the listing's current
// one). Mirrors provider/services.php's edit handler and api/v1/provider/
// listings/update.php exactly (this endpoint was missing the rule entirely).
$effectivePricingType = $pricing_type ?? $listing['pricing_type'];
$requires_inspection = inp('requires_inspection');
if ($effectivePricingType !== 'fixed') {
    $fields[] = 'requires_inspection = :requires_inspection';
    $params[':requires_inspection'] = 1;
} elseif ($requires_inspection !== null) {
    $fields[] = 'requires_inspection = :requires_inspection';
    $params[':requires_inspection'] = $requires_inspection ? 1 : 0;
}

if (empty($fields)) {
    fail('No fields provided to update.');
}

$fields[] = 'updated_at = NOW()';

$sql = 'UPDATE services SET ' . implode(', ', $fields)
     . ' WHERE id = :id AND provider_id = :pid';

$pdo->prepare($sql)->execute($params);

ok(['data' => ['message' => 'Listing updated.']]);
