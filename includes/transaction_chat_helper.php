<?php
// includes/transaction_chat_helper.php
//
// Transaction-scoped chat: previously every chat surface (seeker/messages-
// seeker.php, provider/messages-provider.php, provider-portal/portal-
// messages.php) grouped messages purely by sender/receiver pair — one
// lifetime thread per relationship, with no idea which booking (or
// whether ANY booking) a conversation was even about. messages.request_id
// existed in the schema from the start but was never actually written or
// read anywhere (same "collected but dead" pattern as pricing_type before
// this log entry). This file makes it the real scoping key: one chat
// thread per availed_services row ("transaction"), closed the moment that
// booking reaches completed/cancelled, with the service + status always
// visible so a seeker never lands in an unlabeled chat.
//
// "Live" updates are done via short-interval polling (each chat page's own
// ?poll=1 handler, called every few seconds from JS) rather than
// WebSockets — deliberately, so this keeps working on ordinary shared PHP
// hosting with no persistent server process required.

require_once __DIR__ . '/../config/config.php';

function chatIsOpenForBooking(array $booking): bool {
    return !in_array((string)($booking['status'] ?? ''), ['completed', 'cancelled'], true);
}

// Full booking context for the chat header: status, service, price,
// payment state, and who's on each side. Returns null if not found.
function getBookingChatContext(PDO $db, int $bookingId): ?array {
    $stmt = $db->prepare(
        "SELECT a.*, p.company_name, p.user_id AS provider_user_id,
                COALESCE(a.seeker_user_id, a.user_id) AS seeker_uid,
                su.first_name AS seeker_first_name, su.last_name AS seeker_last_name,
                COALESCE(sv.requires_inspection, 0) AS requires_inspection
         FROM availed_services a
         JOIN providers p ON p.id = a.provider_id
         LEFT JOIN users su ON su.id = COALESCE(a.seeker_user_id, a.user_id)
         LEFT JOIN services sv ON sv.id = a.service_id
         WHERE a.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// One row per booking the seeker has ever had — every booking is a
// selectable thread (even with zero messages yet), so "service availed +
// status" is always visible instead of a bare name.
function getSeekerBookingThreads(PDO $db, int $seekerUserId): array {
    $stmt = $db->prepare(
        "SELECT a.id AS booking_id, a.service_name, a.status, a.total_amount,
                a.preferred_date, a.working_date, a.created_at,
                p.company_name, p.user_id AS provider_user_id,
                (SELECT m.message FROM messages m WHERE m.request_id = a.id ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
                (SELECT m.created_at FROM messages m WHERE m.request_id = a.id ORDER BY m.created_at DESC LIMIT 1) AS last_time,
                (SELECT COUNT(*) FROM messages m WHERE m.request_id = a.id AND m.receiver_id = :uid AND m.is_read = 0) AS unread
         FROM availed_services a
         JOIN providers p ON p.id = a.provider_id
         WHERE COALESCE(a.seeker_user_id, a.user_id) = :uid2
         ORDER BY COALESCE(
             (SELECT MAX(m.created_at) FROM messages m WHERE m.request_id = a.id),
             a.created_at
         ) DESC"
    );
    $stmt->execute([':uid' => $seekerUserId, ':uid2' => $seekerUserId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// One row per booking for a provider (owner or portal staff — scoped by
// provider_id, not by a specific staff user, so every staff member with
// chat access sees the same thread list).
function getProviderBookingThreads(PDO $db, int $providerId): array {
    $stmt = $db->prepare(
        "SELECT a.id AS booking_id, a.service_name, a.status, a.total_amount,
                a.preferred_date, a.working_date, a.created_at,
                COALESCE(a.seeker_user_id, a.user_id) AS seeker_user_id,
                a.full_name AS seeker_name,
                (SELECT m.message FROM messages m WHERE m.request_id = a.id ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
                (SELECT m.created_at FROM messages m WHERE m.request_id = a.id ORDER BY m.created_at DESC LIMIT 1) AS last_time,
                (SELECT COUNT(*) FROM messages m JOIN providers p2 ON p2.id = :pid3 WHERE m.request_id = a.id AND m.receiver_id = p2.user_id AND m.is_read = 0) AS unread
         FROM availed_services a
         WHERE a.provider_id = :pid
         ORDER BY COALESCE(
             (SELECT MAX(m.created_at) FROM messages m WHERE m.request_id = a.id),
             a.created_at
         ) DESC"
    );
    $stmt->execute([':pid' => $providerId, ':pid3' => $providerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBookingMessages(PDO $db, int $bookingId): array {
    $stmt = $db->prepare(
        "SELECT m.*, u.first_name, u.last_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.request_id = :bid
         ORDER BY m.created_at ASC"
    );
    $stmt->execute([':bid' => $bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Only messages newer than $sinceId — what the poll endpoint hands back on
// each tick instead of re-sending the whole history.
function getBookingMessagesSince(PDO $db, int $bookingId, int $sinceId): array {
    $stmt = $db->prepare(
        "SELECT m.*, u.first_name, u.last_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.request_id = :bid AND m.id > :sid
         ORDER BY m.created_at ASC"
    );
    $stmt->execute([':bid' => $bookingId, ':sid' => $sinceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Sends a message scoped to one booking. Rejects if the booking's
// transaction is already closed (completed/cancelled) — this IS the
// "chat closes when the transaction is done" rule, enforced at the one
// place every chat surface funnels through.
function sendBookingMessage(PDO $db, int $bookingId, int $senderId, int $receiverId, string $body): array {
    $body = trim($body);
    if ($body === '') {
        return ['ok' => false, 'error' => 'Message cannot be empty.'];
    }
    if (mb_strlen($body) > 1000) {
        return ['ok' => false, 'error' => 'Message is too long (max 1000 characters).'];
    }

    $booking = getBookingChatContext($db, $bookingId);
    if (!$booking) {
        return ['ok' => false, 'error' => 'Booking not found.'];
    }
    if (!chatIsOpenForBooking($booking)) {
        return ['ok' => false, 'error' => 'This conversation is closed because the service is ' . $booking['status'] . '.'];
    }

    $stmt = $db->prepare(
        "INSERT INTO messages (sender_id, receiver_id, request_id, message, is_read, created_at)
         VALUES (:s, :r, :rid, :m, 0, NOW())"
    );
    $stmt->execute([':s' => $senderId, ':r' => $receiverId, ':rid' => $bookingId, ':m' => $body]);

    return ['ok' => true, 'id' => (int)$db->lastInsertId()];
}

function markBookingMessagesRead(PDO $db, int $bookingId, int $readerId): void {
    $db->prepare("UPDATE messages SET is_read = 1 WHERE request_id = :bid AND receiver_id = :me")
       ->execute([':bid' => $bookingId, ':me' => $readerId]);
}
