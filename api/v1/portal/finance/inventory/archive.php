<?php
// POST api/v1/portal/finance/inventory/archive
// Soft-deletes an inventory item — mirrors provider-portal/inventory.php's
// 'archive' action.
// Access: owner, finance | Tier: Pro required.
//
// Body: id
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'finance');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$id = (int) req_inp('id', 'id');
$pdo = db();

$chk = $pdo->prepare("SELECT id FROM inventory_items WHERE id = :id AND provider_id = :pid AND is_archived = 0 LIMIT 1");
$chk->execute([':id' => $id, ':pid' => $pid]);
if (!$chk->fetch()) fail('Inventory item not found.', 404);

$pdo->prepare("UPDATE inventory_items SET is_archived = 1, archived_at = NOW(), archived_by = :by WHERE id = :id AND provider_id = :pid")
    ->execute([':by' => $staff['full_name'] ?? 'portal', ':id' => $id, ':pid' => $pid]);

ok(['data' => ['id' => $id, 'message' => 'Inventory item archived.']]);
