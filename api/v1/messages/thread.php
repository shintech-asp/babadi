<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$user = current_user();
$me = $user['id'];

$with = (int)inp('with');
if ($with <= 0) fail('with is required');

$pdo = db();

$stmt = $pdo->prepare('SELECT id, first_name, last_name, user_type, profile_image FROM users WHERE id = :with');
$stmt->execute([':with' => $with]);
$other = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$other) fail('User not found', 404);

$stmt = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = :with AND receiver_id = :me');
$stmt->execute([':with' => $with, ':me' => $me]);

$stmt = $pdo->prepare(
    'SELECT m.id, m.sender_id, m.receiver_id, m.message, m.is_read, m.created_at,
            u.first_name, u.last_name
     FROM messages m
     JOIN users u ON u.id = m.sender_id
     WHERE (m.sender_id = :me AND m.receiver_id = :with)
        OR (m.sender_id = :with2 AND m.receiver_id = :me2)
     ORDER BY m.created_at ASC
     LIMIT 200'
);
$stmt->execute([':me' => $me, ':with' => $with, ':with2' => $with, ':me2' => $me]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok(['other' => $other, 'data' => $messages]);
