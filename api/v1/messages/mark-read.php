<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

$user = current_user();
$with = (int)req_inp('with', 'User ID');

$db = db();
$stmt = $db->prepare(
    'UPDATE messages SET is_read = 1 WHERE sender_id = :with AND receiver_id = :me'
);
$stmt->execute([':with' => $with, ':me' => $user['id']]);

ok(['message' => 'Marked as read']);
