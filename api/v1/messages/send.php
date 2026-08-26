<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$user = current_user();
$me   = $user['id'];

$to  = (int) req_inp('to', 'Recipient');
$msg = trim(req_inp('message', 'Message'));

if ($to === $me) {
    fail('You cannot send a message to yourself.');
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $to]);
if (!$stmt->fetch()) {
    fail('Recipient not found.', 404);
}

if (mb_strlen($msg) > 4000) {
    fail('Message must not exceed 4000 characters.');
}

$ins = $pdo->prepare(
    'INSERT INTO messages (sender_id, receiver_id, message, is_read, created_at)
     VALUES (:me, :to, :msg, 0, NOW())'
);
$ins->execute([
    ':me'  => $me,
    ':to'  => $to,
    ':msg' => $msg,
]);

ok(['id' => (int) $pdo->lastInsertId(), 'created_at' => date('Y-m-d H:i:s')], 201);
