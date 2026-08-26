<?php
// GET /api/v1/admin/subscription-plans/
// List all subscription plans with active subscriber count per plan.
// Access: super_admin only.

require_once dirname(__DIR__) . '/_bootstrap.php';

allow('GET');
require_admin_role('super_admin');

$rows = db()->query(
    "SELECT
        sp.id,
        sp.name,
        sp.monthly_price,
        sp.yearly_price,
        COUNT(CASE WHEN ps.status IN ('active','grace') THEN 1 END) AS active_subscribers
     FROM subscription_plans sp
     LEFT JOIN provider_subscriptions ps ON ps.plan_id = sp.id
     GROUP BY sp.id, sp.name, sp.monthly_price, sp.yearly_price
     ORDER BY sp.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$plans = array_map(function (array $row): array {
    return [
        'id'                 => (int)$row['id'],
        'name'               => $row['name'],
        'monthly_price'      => (float)$row['monthly_price'],
        'yearly_price'       => (float)$row['yearly_price'],
        'active_subscribers' => (int)$row['active_subscribers'],
    ];
}, $rows);

ok(['data' => $plans]);
