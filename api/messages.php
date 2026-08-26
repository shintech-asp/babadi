<?php
// api/messages.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');

function api_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function read_input(): array {
    $data = $_POST;
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }
    return $data;
}

function conversation_exists(PDO $db, int $a, int $b): bool {
    $q = $db->prepare(
        "SELECT 1
         FROM messages
         WHERE (sender_id = :a AND receiver_id = :b)
            OR (sender_id = :b2 AND receiver_id = :a2)
         LIMIT 1"
    );
    $q->execute([':a' => $a, ':b' => $b, ':b2' => $b, ':a2' => $a]);
    return (bool)$q->fetchColumn();
}

function user_exists(PDO $db, int $uid): bool {
    $q = $db->prepare("SELECT 1 FROM users WHERE id = :id LIMIT 1");
    $q->execute([':id' => $uid]);
    return (bool)$q->fetchColumn();
}

function portal_target_allowed(PDO $db, int $providerId, int $providerUserId, int $targetUserId): bool {
    if ($targetUserId <= 0 || $targetUserId === $providerUserId) return false;

    if (conversation_exists($db, $providerUserId, $targetUserId)) return true;

    // Allow seekers with booking/request relationship to this provider.
    $booking = $db->prepare(
        "SELECT 1
         FROM availed_services
         WHERE provider_id = :pid
           AND (seeker_user_id = :uid OR user_id = :uid2)
         LIMIT 1"
    );
    $booking->execute([':pid' => $providerId, ':uid' => $targetUserId, ':uid2' => $targetUserId]);
    if ($booking->fetchColumn()) return true;

    $request = $db->prepare(
        "SELECT 1
         FROM service_requests
         WHERE provider_id = :pid
           AND seeker_id = :uid
         LIMIT 1"
    );
    $request->execute([':pid' => $providerId, ':uid' => $targetUserId]);
    if ($request->fetchColumn()) return true;

    return false;
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    api_json(500, ['ok' => false, 'error' => 'Database connection failed.']);
}

try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender_receiver (sender_id, receiver_id),
            INDEX idx_receiver_read (receiver_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Exception $e) {
    // Non-fatal: table likely already exists with older structure.
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    api_json(200, ['ok' => true]);
}

$input = read_input();
$action = $input['action'] ?? ($_GET['action'] ?? ($method === 'GET' ? 'conversations' : 'send'));

$isPortal = isset($_SESSION['portal_staff_id']);
$isAppUser = isset($_SESSION['user_id']);

if (!$isPortal && !$isAppUser) {
    api_json(401, ['ok' => false, 'error' => 'Unauthorized.']);
}

$ctx = [
    'mode'         => $isPortal ? 'portal' : 'app',
    'actor_user_id'=> 0,
    'provider_id'  => 0,
    'can_view'     => false,
    'can_reply'    => false,
    'user_type'    => '',
];

if ($isPortal) {
    $portalProviderId = (int)($_SESSION['portal_provider_id'] ?? 0);
    $portalRole = (string)($_SESSION['portal_role'] ?? '');
    $portalDept = (string)($_SESSION['portal_dept'] ?? '');

    $canView = ($portalRole === 'owner' || in_array($portalDept, ['hr', 'finance', 'crm', 'all'], true));
    $canReply = ($portalRole === 'owner' || in_array($portalDept, ['crm', 'all'], true));
    if (!$canView) {
        api_json(403, ['ok' => false, 'error' => 'Access denied.']);
    }

    $provStmt = $db->prepare("SELECT user_id FROM providers WHERE id = :pid LIMIT 1");
    $provStmt->execute([':pid' => $portalProviderId]);
    $provUserId = (int)($provStmt->fetchColumn() ?: 0);
    if ($portalProviderId <= 0 || $provUserId <= 0) {
        api_json(403, ['ok' => false, 'error' => 'Provider context not found.']);
    }

    $ctx['actor_user_id'] = $provUserId;
    $ctx['provider_id'] = $portalProviderId;
    $ctx['can_view'] = true;
    $ctx['can_reply'] = $canReply;
    $ctx['user_type'] = 'provider';
} else {
    $actorUserId = (int)$_SESSION['user_id'];
    $actorType = (string)($_SESSION['user_type'] ?? '');
    if ($actorUserId <= 0) {
        api_json(401, ['ok' => false, 'error' => 'Unauthorized.']);
    }
    $ctx['actor_user_id'] = $actorUserId;
    $ctx['provider_id'] = 0;
    $ctx['can_view'] = true;
    $ctx['can_reply'] = true;
    $ctx['user_type'] = $actorType;
}

$actor = (int)$ctx['actor_user_id'];

if ($method === 'GET' && $action === 'conversations') {
    $q = $db->prepare(
        "SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.user_type,
            (
              SELECT message
              FROM messages
              WHERE (sender_id = u.id AND receiver_id = :me1)
                 OR (sender_id = :me2 AND receiver_id = u.id)
              ORDER BY created_at DESC
              LIMIT 1
            ) AS last_msg,
            (
              SELECT created_at
              FROM messages
              WHERE (sender_id = u.id AND receiver_id = :me3)
                 OR (sender_id = :me4 AND receiver_id = u.id)
              ORDER BY created_at DESC
              LIMIT 1
            ) AS last_time,
            (
              SELECT COUNT(*)
              FROM messages
              WHERE sender_id = u.id
                AND receiver_id = :me5
                AND is_read = 0
            ) AS unread
         FROM users u
         WHERE u.id IN (
            SELECT DISTINCT CASE WHEN sender_id = :me6 THEN receiver_id ELSE sender_id END
            FROM messages
            WHERE sender_id = :me7 OR receiver_id = :me8
         )
         ORDER BY last_time DESC"
    );
    $q->execute([
        ':me1' => $actor, ':me2' => $actor, ':me3' => $actor, ':me4' => $actor,
        ':me5' => $actor, ':me6' => $actor, ':me7' => $actor, ':me8' => $actor,
    ]);
    api_json(200, ['ok' => true, 'data' => $q->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'GET' && $action === 'thread') {
    $with = (int)($_GET['with'] ?? 0);
    if ($with <= 0 || !user_exists($db, $with)) {
        api_json(404, ['ok' => false, 'error' => 'User not found.']);
    }
    if ($ctx['mode'] === 'portal' && !portal_target_allowed($db, (int)$ctx['provider_id'], $actor, $with)) {
        api_json(403, ['ok' => false, 'error' => 'Not allowed to access this conversation.']);
    }

    $otherQ = $db->prepare("SELECT id, first_name, last_name, user_type FROM users WHERE id = :id LIMIT 1");
    $otherQ->execute([':id' => $with]);
    $other = $otherQ->fetch(PDO::FETCH_ASSOC);

    if (!$other) {
        api_json(404, ['ok' => false, 'error' => 'User not found.']);
    }

    if (isset($_GET['mark_read']) && $_GET['mark_read'] === '1') {
        $db->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = :from AND receiver_id = :me")
           ->execute([':from' => $with, ':me' => $actor]);
    }

    $threadQ = $db->prepare(
        "SELECT m.id, m.sender_id, m.receiver_id, m.message, m.is_read, m.created_at, u.first_name, u.last_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE (m.sender_id = :me AND m.receiver_id = :with1)
            OR (m.sender_id = :with2 AND m.receiver_id = :me2)
         ORDER BY m.created_at ASC
         LIMIT 1000"
    );
    $threadQ->execute([':me' => $actor, ':with1' => $with, ':with2' => $with, ':me2' => $actor]);

    api_json(200, [
        'ok' => true,
        'other' => $other,
        'data' => $threadQ->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

if ($method === 'POST' && $action === 'mark_read') {
    $with = (int)($input['with'] ?? 0);
    if ($with <= 0 || !user_exists($db, $with)) {
        api_json(404, ['ok' => false, 'error' => 'User not found.']);
    }
    if ($ctx['mode'] === 'portal' && !portal_target_allowed($db, (int)$ctx['provider_id'], $actor, $with)) {
        api_json(403, ['ok' => false, 'error' => 'Not allowed to mark this conversation.']);
    }

    $db->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = :from AND receiver_id = :me")
       ->execute([':from' => $with, ':me' => $actor]);

    api_json(200, ['ok' => true]);
}

if ($method === 'POST' && $action === 'send') {
    if (!$ctx['can_reply']) {
        api_json(403, ['ok' => false, 'error' => 'Reply not allowed for your role.']);
    }

    $to = (int)($input['to'] ?? 0);
    $msg = trim((string)($input['message'] ?? ''));
    if ($to <= 0 || !user_exists($db, $to)) {
        api_json(404, ['ok' => false, 'error' => 'Recipient not found.']);
    }
    if ($ctx['mode'] === 'portal' && !portal_target_allowed($db, (int)$ctx['provider_id'], $actor, $to)) {
        api_json(403, ['ok' => false, 'error' => 'Not allowed to message this user.']);
    }
    if ($msg === '') {
        api_json(422, ['ok' => false, 'error' => 'Message is required.']);
    }
    if (mb_strlen($msg) > 4000) {
        api_json(422, ['ok' => false, 'error' => 'Message is too long.']);
    }

    $ins = $db->prepare(
        "INSERT INTO messages (sender_id, receiver_id, message, is_read, created_at)
         VALUES (:s, :r, :m, 0, NOW())"
    );
    $ins->execute([':s' => $actor, ':r' => $to, ':m' => $msg]);

    api_json(201, [
        'ok' => true,
        'id' => (int)$db->lastInsertId(),
        'time' => date('h:i A'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

api_json(400, ['ok' => false, 'error' => 'Invalid action.']);

