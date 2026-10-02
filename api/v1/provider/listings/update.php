<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$p = current_provider();

$id = (int)req_inp('id', 'Listing ID');

$stmt = db()->prepare('SELECT * FROM services WHERE id = :id AND provider_id = :pid');
$stmt->execute([':id' => $id, ':pid' => $p['id']]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$listing) {
    fail('Listing not found', 404);
}

$fields = [];
$params = [':id' => $id, ':pid' => $p['id']];

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
    $fields[] = 'price = :price';
    $params[':price'] = (float)$price;
}

$pricing_type = inp('pricing_type');
if ($pricing_type !== null) {
    $pricing_type = trim($pricing_type);
    // Must match services.pricing_type's actual ENUM — see store.php.
    if (!in_array($pricing_type, ['fixed', 'per_sqft', 'hourly', 'custom'], true)) {
        fail('Pricing type must be one of: fixed, per_sqft, hourly, custom.');
    }
    $fields[] = 'pricing_type = :pricing_type';
    $params[':pricing_type'] = $pricing_type;
}

$category_id = inp('category_id');
if ($category_id !== null) {
    $fields[] = 'category_id = :category_id';
    $params[':category_id'] = (int)$category_id;
}

$is_emergency_available = inp('is_emergency_available');
if ($is_emergency_available !== null) {
    $fields[] = 'is_emergency_available = :is_emergency_available';
    $params[':is_emergency_available'] = $is_emergency_available ? 1 : 0;
}

$is_eco_friendly = inp('is_eco_friendly');
if ($is_eco_friendly !== null) {
    $fields[] = 'is_eco_friendly = :is_eco_friendly';
    $params[':is_eco_friendly'] = $is_eco_friendly ? 1 : 0;
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
// one). Mirrors provider/services.php's add/edit handlers.
$effectivePricingType = $pricing_type ?? $listing['pricing_type'];
$requires_inspection = inp('requires_inspection');
if ($effectivePricingType !== 'fixed') {
    $fields[] = 'requires_inspection = :requires_inspection';
    $params[':requires_inspection'] = 1;
} elseif ($requires_inspection !== null) {
    $fields[] = 'requires_inspection = :requires_inspection';
    $params[':requires_inspection'] = $requires_inspection ? 1 : 0;
}

$status = inp('status');
if ($status !== null) {
    if (!in_array($status, ['active', 'inactive'], true)) {
        fail('Status must be active or inactive');
    }
    $fields[] = 'status = :status';
    $params[':status'] = $status;
}

$existingImages = json_decode($listing['images'] ?? '[]', true);
if (!is_array($existingImages)) {
    $existingImages = [];
}

$newImages = [];
if (!empty($_FILES['images'])) {
    $uploadDir = dirname(__DIR__, 2) . '/uploads/listings/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $files = $_FILES['images'];
    $isMultiple = is_array($files['name']);

    $fileCount = $isMultiple ? count($files['name']) : 1;

    for ($i = 0; $i < $fileCount; $i++) {
        $name     = $isMultiple ? $files['name'][$i]     : $files['name'];
        $tmp      = $isMultiple ? $files['tmp_name'][$i] : $files['tmp_name'];
        $error    = $isMultiple ? $files['error'][$i]    : $files['error'];
        $size     = $isMultiple ? $files['size'][$i]     : $files['size'];

        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowed, true)) {
            fail('Invalid image type: ' . $ext);
        }

        if ($size > 5 * 1024 * 1024) {
            fail('Image too large (max 5MB)');
        }

        $filename = uniqid('listing_', true) . '.' . $ext;
        $dest = $uploadDir . $filename;

        if (!move_uploaded_file($tmp, $dest)) {
            fail('Failed to save uploaded image');
        }

        $newImages[] = 'uploads/listings/' . $filename;
    }
}

if (!empty($newImages)) {
    $replaceImages = (int)inp('replace_images', 0);
    if ($replaceImages === 1) {
        $finalImages = $newImages;
    } else {
        $finalImages = array_merge($existingImages, $newImages);
    }
    $fields[] = 'images = :images';
    $params[':images'] = json_encode($finalImages);
}

// Video — a new upload always replaces the existing one (one video per
// listing, same as the web's provider/services.php edit handler).
if (!empty($_FILES['videos']) || !empty($_FILES['video'])) {
    $videoUploadDir = dirname(__DIR__, 2) . '/uploads/listings/';
    if (!is_dir($videoUploadDir)) {
        mkdir($videoUploadDir, 0775, true);
    }

    $vFile = !empty($_FILES['videos'])
        ? (is_array($_FILES['videos']['name']) ? [
            'name'     => $_FILES['videos']['name'][0] ?? '',
            'type'     => $_FILES['videos']['type'][0] ?? '',
            'tmp_name' => $_FILES['videos']['tmp_name'][0] ?? '',
            'error'    => $_FILES['videos']['error'][0] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $_FILES['videos']['size'][0] ?? 0,
          ] : $_FILES['videos'])
        : $_FILES['video'];

    if ($vFile['error'] === UPLOAD_ERR_OK) {
        if ($vFile['size'] > 20 * 1024 * 1024) {
            fail('Video too large (max 20MB)');
        }
        $vExt = strtolower(pathinfo($vFile['name'], PATHINFO_EXTENSION));
        if (!in_array($vExt, ['mp4', 'mov'], true)) {
            fail('Only mp4 and mov videos are allowed.');
        }
        $vFilename = uniqid('listing_vid_', true) . '.' . $vExt;
        $vDest = $videoUploadDir . $vFilename;
        if (!move_uploaded_file($vFile['tmp_name'], $vDest)) {
            fail('Failed to save uploaded video');
        }
        $fields[] = 'videos = :videos';
        $params[':videos'] = json_encode(['uploads/listings/' . $vFilename]);
    }
}

if (empty($fields)) {
    fail('No fields provided to update');
}

$sql = 'UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = :id AND provider_id = :pid';
$stmt = db()->prepare($sql);
$stmt->execute($params);

ok(['data' => ['message' => 'Listing updated']]);
