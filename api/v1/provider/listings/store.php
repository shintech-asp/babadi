<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$p = current_provider();

$title       = req_inp('title', 'Title');
$description = req_inp('description', 'Description');
$price       = req_inp('price', 'Price');
$pricing_type = req_inp('pricing_type', 'Pricing type');
$category_id = req_inp('category_id', 'Category');

if (!is_numeric($price) || (float)$price <= 0) {
    fail('Price must be a positive number.');
}

// Must match services.pricing_type's actual ENUM (confirmed via DESCRIBE) —
// 'per_sqm' was copied from a different schema version and isn't a real
// column value; inserting it 500s under strict SQL mode.
$allowed_pricing = ['fixed', 'per_sqft', 'hourly', 'custom'];
if (!in_array($pricing_type, $allowed_pricing, true)) {
    fail('Pricing type must be one of: fixed, per_sqft, hourly, custom.');
}

$pdo = db();
$cat_stmt = $pdo->prepare('SELECT id FROM service_categories WHERE id = ? LIMIT 1');
$cat_stmt->execute([(int)$category_id]);
if (!$cat_stmt->fetch()) {
    fail('Invalid category_id.');
}

$is_emergency_available = (bool) inp('is_emergency_available', false);
$is_eco_friendly = (bool) inp('is_eco_friendly', false);
// Non-fixed pricing can't be charged upfront — the final price is only
// knowable after an on-site inspection, so it forces requires_inspection
// on regardless of what was posted. Mirrors the same rule in
// provider/services.php's add/edit handlers (see CLAUDE.md's "Recent
// Work Log").
$requires_inspection = ($pricing_type !== 'fixed') ? true : (bool) inp('requires_inspection', false);

// Handle image uploads
$image_paths = [];
$upload_dir = __DIR__ . '/../../../../uploads/services/';

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$allowed_mime = ['image/jpeg', 'image/jpg', 'image/png'];
$allowed_ext  = ['jpg', 'jpeg', 'png'];
$max_size     = 5 * 1024 * 1024; // 5MB

// Normalize to a multi-file structure
$files_raw = null;
if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
    $files_raw = $_FILES['images'];
} elseif (!empty($_FILES['images']['name']) && is_string($_FILES['images']['name'])) {
    // Single file uploaded under 'images'
    $files_raw = [
        'name'     => [$_FILES['images']['name']],
        'type'     => [$_FILES['images']['type']],
        'tmp_name' => [$_FILES['images']['tmp_name']],
        'error'    => [$_FILES['images']['error']],
        'size'     => [$_FILES['images']['size']],
    ];
} elseif (!empty($_FILES['image']['name'])) {
    // Single file uploaded under 'image'
    $files_raw = [
        'name'     => [$_FILES['image']['name']],
        'type'     => [$_FILES['image']['type']],
        'tmp_name' => [$_FILES['image']['tmp_name']],
        'error'    => [$_FILES['image']['error']],
        'size'     => [$_FILES['image']['size']],
    ];
}

if ($files_raw !== null) {
    $count = count($files_raw['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files_raw['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $original_name = $files_raw['name'][$i];
        $tmp_name      = $files_raw['tmp_name'][$i];
        $size          = $files_raw['size'][$i];

        if ($size > $max_size) {
            fail('Each image must be 5MB or smaller.');
        }

        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            fail('Only jpg, jpeg, and png images are allowed.');
        }

        $mime = mime_content_type($tmp_name);
        if (!in_array($mime, $allowed_mime, true)) {
            fail('Invalid image file type detected.');
        }

        $filename  = uniqid('svc_', true) . '.' . $ext;
        $dest_path = $upload_dir . $filename;

        if (!move_uploaded_file($tmp_name, $dest_path)) {
            fail('Failed to save uploaded image.');
        }

        $image_paths[] = 'uploads/services/' . $filename;
    }
}

$images_json = json_encode($image_paths);

// service_listings was merged into services (the same table the web app's
// own provider/services.php and the seeker booking flow use) so a service
// created here is now visible and bookable through the website too — see
// CLAUDE.md's "Recent Work Log" for the full centralization writeup.
$stmt = $pdo->prepare(
    'INSERT INTO services
        (provider_id, service_name, description, price, pricing_type, category_id, images, is_eco_friendly, is_emergency_available, requires_inspection, status, created_at)
     VALUES
        (:provider_id, :service_name, :description, :price, :pricing_type, :category_id, :images, :is_eco_friendly, :is_emergency_available, :requires_inspection, :status, NOW())'
);

$stmt->execute([
    ':provider_id'           => $p['id'],
    ':service_name'          => $title,
    ':description'           => $description,
    ':price'                 => (float)$price,
    ':pricing_type'          => $pricing_type,
    ':category_id'           => (int)$category_id,
    ':images'                => $images_json,
    ':is_eco_friendly'       => $is_eco_friendly ? 1 : 0,
    ':is_emergency_available' => $is_emergency_available ? 1 : 0,
    ':requires_inspection'   => $requires_inspection ? 1 : 0,
    ':status'                => 'active',
]);

$new_id = (int)$pdo->lastInsertId();

ok(['data' => ['id' => $new_id]], 201);
