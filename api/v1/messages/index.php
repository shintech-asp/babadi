<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$user = current_user();
$me = $user['id'];

$stmt = db()->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.user_type, u.profile_image,
            (SELECT message FROM messages WHERE (sender_id=u.id AND receiver_id=:me1) OR (sender_id=:me2 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_msg,
            (SELECT created_at FROM messages WHERE (sender_id=u.id AND receiver_id=:me3) OR (sender_id=:me4 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_time,
            (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=:me5 AND is_read=0) AS unread
     FROM users u
     WHERE u.id IN (SELECT DISTINCT CASE WHEN sender_id=:me6 THEN receiver_id ELSE sender_id END FROM messages WHERE sender_id=:me7 OR receiver_id=:me8)
     ORDER BY last_time DESC"
);

$stmt->execute([
    ':me1' => $me,
    ':me2' => $me,
    ':me3' => $me,
    ':me4' => $me,
    ':me5' => $me,
    ':me6' => $me,
    ':me7' => $me,
    ':me8' => $me,
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok(['data' => $rows]);
