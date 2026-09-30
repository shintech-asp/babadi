<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$user = current_user();
$uid  = $user['id'];
$pdo  = db();

// 1. Booking notifications — role-aware query
$bookings = [];
if ($user['user_type'] === 'seeker') {
    $stmt = $pdo->prepare(
        'SELECT av.id, av.service_name, av.status, av.is_read, av.created_at, av.preferred_date,
                p.company_name
         FROM availed_services av
         JOIN providers p ON av.provider_id = p.id
         WHERE av.seeker_user_id = :uid OR av.user_id = :uid2
         ORDER BY av.created_at DESC
         LIMIT 30'
    );
    $stmt->execute([':uid' => $uid, ':uid2' => $uid]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user['user_type'] === 'provider') {
    $ps = $pdo->prepare('SELECT id FROM providers WHERE user_id = :uid LIMIT 1');
    $ps->execute([':uid' => $uid]);
    $prov = $ps->fetch(PDO::FETCH_ASSOC);
    if ($prov) {
        $stmt = $pdo->prepare(
            'SELECT av.id, av.service_name, av.status, av.is_read, av.created_at, av.preferred_date,
                    u.first_name, u.last_name
             FROM availed_services av
             JOIN users u ON u.id = av.seeker_user_id
             WHERE av.provider_id = :pid
             ORDER BY av.created_at DESC
             LIMIT 30'
        );
        $stmt->execute([':pid' => $prov['id']]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 2. Messages — same for both roles. request_id is the transaction-scoped
// booking this message belongs to (see includes/transaction_chat_helper.php)
// — used as link_id below so tapping the notification opens that booking's
// thread, not a (now-removed) lifetime per-sender thread.
$stmt = $pdo->prepare(
    'SELECT m.id, m.message, m.is_read, m.created_at, m.request_id,
            u.first_name, u.last_name, u.id AS sender_id
     FROM messages m
     JOIN users u ON u.id = m.sender_id
     WHERE m.receiver_id = :uid
     ORDER BY m.created_at DESC
     LIMIT 20'
);
$stmt->execute([':uid' => $uid]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Build unified list
$items = [];

foreach ($bookings as $b) {
    if ($user['user_type'] === 'seeker') {
        $body = 'Your booking for "' . $b['service_name'] . '" with ' . $b['company_name'] . ' is now ' . $b['status'] . '.';
    } else {
        $seeker_name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
        $body = 'Booking for "' . $b['service_name'] . '" from ' . $seeker_name . ' is now ' . $b['status'] . '.';
    }
    $items[] = [
        'type'       => 'booking',
        'title'      => 'Booking Update',
        'body'       => $body,
        'is_read'    => (bool)$b['is_read'],
        'created_at' => $b['created_at'],
        'link_id'    => (int)$b['id'],
    ];
}

foreach ($messages as $m) {
    if (empty($m['request_id'])) {
        // Pre-migration message with no booking scope (shouldn't happen for
        // anything sent after transaction-scoped chat shipped) — skip rather
        // than link to a booking that doesn't exist.
        continue;
    }
    $sender = trim($m['first_name'] . ' ' . $m['last_name']);
    $items[] = [
        'type'       => 'message',
        'title'      => 'New Message from ' . $sender,
        'body'       => $m['message'],
        'is_read'    => (bool)$m['is_read'],
        'created_at' => $m['created_at'],
        'link_id'    => (int)$m['request_id'],
    ];
}

// 4. Sort DESC and cap at 40
usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$items = array_slice($items, 0, 40);

$unread_count = count(array_filter($items, fn($n) => !$n['is_read']));

ok(['data' => $items, 'unread_count' => $unread_count]);
