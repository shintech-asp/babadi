<?php
// List — free-tier viewable: web's crm-services.php renders its catalog
// regardless of tier, gating only add/edit (owner + `$tier_is_paid`) — see
// store.php/update.php, which keep their own Pro gate.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');

$staff = require_portal_role('owner', 'crm');

$pid    = (int)$staff['provider_id'];
$status = inp('status');

if ($status !== null && $status !== '' && !in_array($status, ['active', 'inactive'], true)) {
    fail('status must be active or inactive');
}

$pg = paginate();

$whereClauses = ['sl.provider_id = :pid'];
$params = [':pid' => $pid];

if ($status !== null && $status !== '') {
    $whereClauses[] = 'sl.status = :status';
    $params[':status'] = $status;
}

$where = 'WHERE ' . implode(' AND ', $whereClauses);

// service_listings was merged into services (see CLAUDE.md's "Recent Work
// Log") — service_name AS title keeps this response shape identical to any
// existing client parsing it.
$countStmt = db()->prepare("SELECT COUNT(DISTINCT sl.id) FROM services sl $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT sl.id,
               sl.service_name AS title,
               sl.description,
               sl.price,
               sl.pricing_type,
               sl.status,
               sl.is_emergency_available,
               sl.requires_inspection,
               sl.equipment_notes,
               sl.duration,
               sl.duration_unit,
               sl.images,
               sl.videos,
               sl.created_at,
               sc.id   AS category_id,
               sc.name AS category_name,
               ROUND(AVG(r.rating), 1) AS avg_rating,
               COUNT(r.id)             AS review_count
        FROM services sl
        LEFT JOIN service_categories sc ON sc.id = sl.category_id
        LEFT JOIN service_reviews r ON r.provider_id = sl.provider_id
        $where
        GROUP BY sl.id, sc.id, sc.name
        ORDER BY sl.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = db()->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit',  $pg['limit'],  PDO::PARAM_INT);
$stmt->bindValue(':offset', $pg['offset'], PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['id']                    = (int)$row['id'];
    $row['category_id']           = (int)$row['category_id'];
    $row['price']                 = (float)$row['price'];
    $row['is_emergency_available']= (bool)$row['is_emergency_available'];
    // Was missing from this SELECT entirely, so the mobile edit form always
    // guessed pricing_type !== 'fixed' instead of reading the real stored
    // value — silently flipping requires_inspection off on any edit of a
    // Fixed-price service that had deliberately opted in for other reasons.
    $row['requires_inspection']   = (bool)$row['requires_inspection'];
    $row['review_count']          = (int)$row['review_count'];
    $row['avg_rating']            = $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null;

    if (!empty($row['images'])) {
        $decoded = json_decode($row['images'], true);
        $row['images'] = is_array($decoded) ? $decoded : [];
    } else {
        $row['images'] = [];
    }

    if (!empty($row['videos'])) {
        $decoded = json_decode($row['videos'], true);
        $row['videos'] = is_array($decoded) ? $decoded : [];
    } else {
        $row['videos'] = [];
    }

    $row['duration'] = $row['duration'] !== null ? (int)$row['duration'] : null;
}
unset($row);

ok([
    'data' => $rows,
    'meta' => [
        'page'        => $pg['page'],
        'limit'       => $pg['limit'],
        'total'       => $total,
        'total_pages' => (int)ceil($total / $pg['limit']),
    ],
]);
