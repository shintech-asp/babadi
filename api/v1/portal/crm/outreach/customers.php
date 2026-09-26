<?php
// GET api/v1/portal/crm/outreach/customers
// Past customers (seekers with a completed booking) + this provider's
// service catalog, for building the outreach compose screen — mirrors
// provider-portal/crm-outreach.php.
// Access: owner, crm | Tier: Pro required.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('GET');
$staff = require_portal_role('owner', 'crm');
$pid   = (int)$staff['provider_id'];
portal_require_pro($pid);

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT COALESCE(a.seeker_user_id, a.user_id) AS seeker_user_id,
            u.first_name, u.last_name, u.email,
            COUNT(a.id) AS completed_count,
            MAX(a.updated_at) AS last_completed_at,
            GROUP_CONCAT(DISTINCT COALESCE(a.service_name, 'Service') ORDER BY a.service_name SEPARATOR ', ') AS services_had
     FROM availed_services a
     JOIN users u ON u.id = COALESCE(a.seeker_user_id, a.user_id)
     WHERE a.provider_id = :pid AND a.status = 'completed'
     GROUP BY COALESCE(a.seeker_user_id, a.user_id), u.first_name, u.last_name, u.email
     ORDER BY last_completed_at DESC"
);
$stmt->execute([':pid' => $pid]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$customers = array_map(function ($c) {
    return [
        'seeker_user_id'    => (int)$c['seeker_user_id'],
        'full_name'         => trim($c['first_name'] . ' ' . $c['last_name']),
        'email'             => $c['email'],
        'completed_count'   => (int)$c['completed_count'],
        'last_completed_at' => $c['last_completed_at'],
        'services_had'      => $c['services_had'],
    ];
}, $customers);

$svcStmt = $pdo->prepare("SELECT id, service_name, price, status FROM services WHERE provider_id = :pid ORDER BY status = 'active' DESC, service_name");
$svcStmt->execute([':pid' => $pid]);
$services = $svcStmt->fetchAll(PDO::FETCH_ASSOC);
$services = array_map(function ($s) {
    return ['id' => (int)$s['id'], 'service_name' => $s['service_name'], 'price' => (float)$s['price'], 'status' => $s['status']];
}, $services);

ok(['data' => ['customers' => $customers, 'services' => $services]]);
