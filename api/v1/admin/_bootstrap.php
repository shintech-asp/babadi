<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

function require_admin(): array {
    $payload = auth(); // already validates JWT signature and expiry
    if (($payload['user_type'] ?? '') !== 'admin') fail('Admin access required', 403);
    $stmt = db()->prepare("SELECT * FROM admin_users WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute([':id' => (int)($payload['sub'] ?? 0)]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) fail('Admin account not found or inactive', 401);
    return $admin;
}

function require_admin_role(string ...$roles): array {
    $admin = require_admin();
    if (!in_array($admin['role'], $roles, true)) fail('Insufficient admin role', 403);
    return $admin;
}

function current_admin(): array { return require_admin(); }
