<?php
// GET — one row per booking the seeker has ever had, each a selectable chat
// thread (even with zero messages yet). Mirrors seeker/messages-seeker.php's
// conversation list via the same shared helper used by the web app.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/transaction_chat_helper.php';

allow('GET');

$user = require_seeker();
$db = db();

$threads = getSeekerBookingThreads($db, (int)$user['id']);

ok(['data' => $threads]);
