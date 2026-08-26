<?php
// api/v1/admin/auth/me.php
// GET /api/v1/admin/auth/me
// Returns the authenticated admin's profile (all roles allowed).

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');

$admin = require_admin_role('super_admin', 'admin', 'hr', 'finance');

ok([
    'admin' => [
        'id'         => (int)$admin['id'],
        'username'   => $admin['username'] ?? '',
        'email'      => $admin['email'],
        'role'       => $admin['role'],
        'first_name' => $admin['first_name'] ?? null,
        'last_name'  => $admin['last_name'] ?? null,
        'status'     => $admin['status'],
        'created_at' => $admin['created_at'] ?? null,
    ],
]);
