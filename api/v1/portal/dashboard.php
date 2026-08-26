<?php
// api/v1/portal/dashboard.php
// GET — returns provider dashboard stats for the authenticated portal staff member.

require_once __DIR__ . '/_bootstrap.php';

allow('GET');
$staff = require_portal();
$pid   = (int)$staff['provider_id'];
$pdo   = db();

// ── Booking counts ────────────────────────────────────────────────────────────

$stmtPending = $pdo->prepare(
    "SELECT COUNT(*) FROM availed_services WHERE provider_id = ? AND status = 'pending'"
);
$stmtPending->execute([$pid]);
$pending_requests = (int)$stmtPending->fetchColumn();

$stmtActive = $pdo->prepare(
    "SELECT COUNT(*) FROM availed_services
     WHERE provider_id = ? AND status IN ('accepted','preparing','starting','on_going')"
);
$stmtActive->execute([$pid]);
$active_bookings = (int)$stmtActive->fetchColumn();

$stmtToday = $pdo->prepare(
    "SELECT COUNT(*) FROM availed_services
     WHERE provider_id = ? AND status = 'completed' AND DATE(updated_at) = CURDATE()"
);
$stmtToday->execute([$pid]);
$completed_today = (int)$stmtToday->fetchColumn();

// ── Staff count ───────────────────────────────────────────────────────────────

$stmtStaff = $pdo->prepare(
    "SELECT COUNT(*) FROM provider_staff WHERE provider_id = ? AND status = 'active'"
);
$stmtStaff->execute([$pid]);
$total_staff = (int)$stmtStaff->fetchColumn();

// ── Subscription tier ─────────────────────────────────────────────────────────

$stmtSub = $pdo->prepare(
    "SELECT status, expires_at
     FROM provider_subscriptions
     WHERE provider_id = ?
     ORDER BY id DESC
     LIMIT 1"
);
$stmtSub->execute([$pid]);
$sub = $stmtSub->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    $subscription_tier = 'free';
} elseif (in_array($sub['status'], ['active', 'grace'], true)) {
    $subscription_tier = $sub['status'] === 'grace' ? 'grace' : 'pro';
} else {
    $subscription_tier = 'free';
}

// ── Recent requests (last 5) ──────────────────────────────────────────────────

$stmtRecent = $pdo->prepare(
    "SELECT
         av.id,
         av.status,
         av.preferred_date,
         av.created_at,
         av.service_name,
         u.first_name AS seeker_first_name,
         u.last_name  AS seeker_last_name
     FROM availed_services av
     JOIN users u ON u.id = av.user_id
     WHERE av.provider_id = ?
     ORDER BY av.created_at DESC
     LIMIT 5"
);
$stmtRecent->execute([$pid]);
$rows = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

$recent_requests = array_map(function (array $r): array {
    return [
        'id'           => (int)$r['id'],
        'seeker_name'  => trim($r['seeker_first_name'] . ' ' . $r['seeker_last_name']),
        'service_name' => $r['service_name'],
        'status'       => $r['status'],
        'preferred_date' => $r['preferred_date'],
        'created_at'   => $r['created_at'],
    ];
}, $rows);

ok([
    'data' => [
        'stats' => [
            'pending_requests' => $pending_requests,
            'active_bookings'  => $active_bookings,
            'completed_today'  => $completed_today,
            'total_staff'      => $total_staff,
        ],
        'subscription_tier' => $subscription_tier,
        'recent_requests'   => $recent_requests,
    ],
]);
