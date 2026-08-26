<?php
// api/v1/admin/dashboard.php
// GET  — platform overview stats for admin dashboard
// Access: super_admin, admin, hr, finance

require_once __DIR__ . '/_bootstrap.php';

allow('GET');

$admin = require_admin_role('super_admin', 'admin', 'hr', 'finance');
$role  = $admin['role'];

$db = db();

// ── Core platform stats (all admin roles) ────────────────────────────────────

$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE user_type = 'seeker'");
$stmt->execute();
$total_users = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM providers");
$stmt->execute();
$total_providers = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM providers WHERE status = 'pending'");
$stmt->execute();
$pending_providers = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM availed_services");
$stmt->execute();
$total_bookings = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM availed_services WHERE status = 'pending'");
$stmt->execute();
$pending_bookings = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM availed_services WHERE status = 'completed'");
$stmt->execute();
$completed_bookings = (int) $stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(paid_amount), 0)
     FROM availed_services
     WHERE payment_status IN ('paid', 'partial')"
);
$stmt->execute();
$total_revenue = (float) $stmt->fetchColumn();

// ── Recent bookings (last 5) ─────────────────────────────────────────────────

$stmt = $db->prepare(
    "SELECT
         av.id,
         av.service_name,
         av.status,
         av.payment_status,
         av.total_amount,
         av.paid_amount,
         av.preferred_date,
         av.preferred_time,
         av.created_at,
         u.first_name  AS seeker_first_name,
         u.last_name   AS seeker_last_name,
         u.email       AS seeker_email,
         p.company_name,
         p.logo_url    AS provider_logo
     FROM availed_services av
     LEFT JOIN users     u ON u.id = av.seeker_user_id
     LEFT JOIN providers p ON p.id = av.provider_id
     ORDER BY av.created_at DESC
     LIMIT 5"
);
$stmt->execute();
$recent_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cast numeric fields
foreach ($recent_bookings as &$row) {
    $row['total_amount'] = $row['total_amount'] !== null ? (float) $row['total_amount'] : null;
    $row['paid_amount']  = $row['paid_amount']  !== null ? (float) $row['paid_amount']  : null;
}
unset($row);

// ── Build response payload ────────────────────────────────────────────────────

$data = [
    'total_users'       => $total_users,
    'total_providers'   => $total_providers,
    'pending_providers' => $pending_providers,
    'total_bookings'    => $total_bookings,
    'pending_bookings'  => $pending_bookings,
    'completed_bookings'=> $completed_bookings,
    'total_revenue'     => $total_revenue,
    'recent_bookings'   => $recent_bookings,
];

// ── Super-admin extras ────────────────────────────────────────────────────────

if ($role === 'super_admin') {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM provider_subscriptions
         WHERE status IN ('active', 'grace')"
    );
    $stmt->execute();
    $data['active_subscriptions'] = (int) $stmt->fetchColumn();

    // Revenue this calendar month from paid/partial bookings
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(paid_amount), 0)
         FROM availed_services
         WHERE payment_status IN ('paid', 'partial')
           AND DATE_FORMAT(updated_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')"
    );
    $stmt->execute();
    $data['monthly_revenue'] = (float) $stmt->fetchColumn();
}

ok(['data' => $data]);
