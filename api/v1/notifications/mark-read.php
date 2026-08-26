<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$user = current_user();
$uid  = (int) $user['id'];
$pdo  = db();

if ($user['user_type'] === 'seeker') {
    // Mark booking status notifications as read
    $pdo->prepare("UPDATE availed_services SET is_read = 1 WHERE (seeker_user_id = ? OR user_id = ?) AND is_read = 0")
        ->execute([$uid, $uid]);
} elseif ($user['user_type'] === 'provider') {
    $ps = $pdo->prepare("SELECT id FROM providers WHERE user_id = ? LIMIT 1");
    $ps->execute([$uid]);
    $prov = $ps->fetch(PDO::FETCH_ASSOC);
    if ($prov) {
        $pdo->prepare("UPDATE availed_services SET is_read = 1 WHERE provider_id = ? AND is_read = 0")
            ->execute([$prov['id']]);
    }
}

// Mark received messages as read
$pdo->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND is_read = 0")
    ->execute([$uid]);

ok(['message' => 'Notifications marked as read']);
