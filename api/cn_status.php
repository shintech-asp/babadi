<?php
// api/cn-status.php
// Lightweight JSON endpoint — both seeker and provider pages poll this
// every ~10 s on service day to reflect the other party's verification in real-time.
//
// GET  ?booking_id=X   → returns dual-CN status for that booking
// POST action=verify_seeker | verify_provider → handled by the main verify-service.php files
//
// This file is placed in: /pestify/api/cn-status.php
// Seeker usage:   fetch('/pestify/api/cn-status.php?booking_id=X&role=seeker')
// Provider usage: fetch('/pestify/api/cn-status.php?booking_id=X&role=provider')

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

session_start();

$bid  = (int)($_GET['booking_id'] ?? 0);
$role = $_GET['role'] ?? 'seeker';   // 'seeker' | 'provider'

if (!$bid) {
    echo json_encode(['error' => 'Missing booking_id']); exit;
}

$database = new Database();
$db       = $database->getConnection();

// Auth check
if ($role === 'seeker') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Unauthenticated']); exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $stmt = $db->prepare(
        "SELECT id, seeker_verified_at, provider_verified_at, dual_verified_at, status
           FROM availed_services
          WHERE id = :bid AND (seeker_user_id = :uid OR user_id = :uid2) LIMIT 1"
    );
    $stmt->execute([':bid' => $bid, ':uid' => $uid, ':uid2' => $uid]);
} else {
    // Provider: check portal session
    session_name('provider_portal'); // adjust to match your portal session name
    if (empty($_SESSION['provider_id'])) {
        echo json_encode(['error' => 'Unauthenticated']); exit;
    }
    $pid = (int)$_SESSION['provider_id'];
    $stmt = $db->prepare(
        "SELECT id, seeker_verified_at, provider_verified_at, dual_verified_at, status
           FROM availed_services
          WHERE id = :bid AND provider_id = :pid LIMIT 1"
    );
    $stmt->execute([':bid' => $bid, ':pid' => $pid]);
}

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['error' => 'Booking not found']); exit;
}

echo json_encode([
    'booking_id'          => $bid,
    'seeker_verified'     => !empty($row['seeker_verified_at']),
    'provider_verified'   => !empty($row['provider_verified_at']),
    'dual_verified'       => !empty($row['dual_verified_at']),
    'dual_verified_at'    => $row['dual_verified_at'] ?? null,
    'status'              => $row['status'],
]);