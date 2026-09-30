<?php
// GET — one row per booking this provider has ever had, each a selectable
// chat thread (even with zero messages yet). Mirrors
// api/v1/seeker/messages/index.php exactly, scoped by provider_id instead
// of seeker_user_id — see includes/transaction_chat_helper.php's
// getProviderBookingThreads(), already shared with the web's
// provider-portal chat surfaces.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once 'includes/transaction_chat_helper.php';

allow('GET');

$p = current_provider();
$db = db();

$threads = getProviderBookingThreads($db, (int)$p['id']);

ok(['data' => $threads]);
