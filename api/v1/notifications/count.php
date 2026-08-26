<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$user = current_user();
$uid  = $user['id'];
$pdo  = db();

// Booking unread count — role-aware
$booking_count = 0;
if ($user['user_type'] === 'seeker') {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM availed_services
         WHERE (seeker_user_id = :uid OR user_id = :uid2) AND is_read = 0'
    );
    $stmt->execute([':uid' => $uid, ':uid2' => $uid]);
    $booking_count = (int)$stmt->fetchColumn();
} elseif ($user['user_type'] === 'provider') {
    $ps = $pdo->prepare('SELECT id FROM providers WHERE user_id = :uid LIMIT 1');
    $ps->execute([':uid' => $uid]);
    $prov = $ps->fetch(PDO::FETCH_ASSOC);
    if ($prov) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM availed_services WHERE provider_id = :pid AND is_read = 0'
        );
        $stmt->execute([':pid' => $prov['id']]);
        $booking_count = (int)$stmt->fetchColumn();
    }
}

// Unread messages count — same for both roles
$stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = :uid AND is_read = 0');
$stmt->execute([':uid' => $uid]);
$msg_count = (int)$stmt->fetchColumn();

ok(['count' => $booking_count + $msg_count, 'bookings' => $booking_count, 'messages' => $msg_count]);
