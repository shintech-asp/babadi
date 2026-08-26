<?php
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');

$p = current_provider();
$id = (int)req_inp('id', 'Listing ID');

$db = db();

$stmt = $db->prepare('SELECT id FROM service_listings WHERE id = :id AND provider_id = :pid');
$stmt->execute([':id' => $id, ':pid' => $p['id']]);

if (!$stmt->fetch()) {
    fail('Listing not found', 404);
}

$upd = $db->prepare('UPDATE service_listings SET status = \'inactive\' WHERE id = :id AND provider_id = :pid');
$upd->execute([':id' => $id, ':pid' => $p['id']]);

ok(['message' => 'Listing removed']);
