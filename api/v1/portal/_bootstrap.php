<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

function require_portal(): array {
    $payload = auth();
    if (($payload['user_type'] ?? '') !== 'portal_staff') fail('Portal staff access required', 403);
    $stmt = db()->prepare("SELECT * FROM provider_staff WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute([':id' => (int)($payload['sub'] ?? 0)]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) fail('Staff account not found or inactive', 401);
    return $staff;
}

function require_portal_role(string ...$roles): array {
    $staff = require_portal();
    if (!in_array($staff['role'], $roles, true)) fail('Insufficient portal role', 403);
    return $staff;
}

function current_portal_staff(): array { return require_portal(); }

function portal_require_pro(int $provider_id): void {
    $stmt = db()->prepare(
        "SELECT id FROM provider_subscriptions WHERE provider_id = ? AND status IN ('active','grace') LIMIT 1"
    );
    $stmt->execute([$provider_id]);
    if (!$stmt->fetch()) fail('Pro subscription required to access this feature.', 403);
}
