<?php
// provider/messages-provider.php — transaction-scoped chat (provider side).
// One thread per booking, closed on completed/cancelled, with Accept/
// Decline available inline for a pending booking. See CLAUDE.md's
// "Recent Work Log" for the full design writeup.
$appRoot = dirname(__DIR__);
chdir($appRoot);
if (!isset($db) || !($db instanceof PDO)) {
    require_once $appRoot . '/config/config.php';
    require_once $appRoot . '/config/database.php';
    $database = new Database();
    $db = $database->getConnection();
}
require_once $appRoot . '/includes/transaction_chat_helper.php';
require_once $appRoot . '/includes/availed_booking_helper.php';

$loginUrl = function_exists('appUrl') ? appUrl('login.php') : '../login.php';
$messagesUrl = function_exists('appUrl') ? appUrl('messages.php') : '../messages.php';
$dashboardUrl = function_exists('appUrl') ? appUrl('providers-dashboard.php') : '../providers-dashboard.php';
$servicesUrl = function_exists('appUrl') ? appUrl('services.php') : '../services.php';
$serviceRequestsUrl = function_exists('appUrl') ? appUrl('service-requests.php') : '../service-requests.php';
$profileUrl = function_exists('appUrl') ? appUrl('profile.php') : '../profile.php';
$logoutUrl = function_exists('appUrl') ? appUrl('logout.php') : '../logout.php';

$me = (int)($_SESSION['user_id'] ?? 0);
if ($me <= 0) {
    header('Location: ' . $loginUrl);
    exit;
}

$provider_stmt = $db->prepare(
    "SELECT p.id AS provider_id, p.company_name, u.email, u.first_name
     FROM providers p
     JOIN users u ON u.id = p.user_id
     WHERE p.user_id = :uid
     LIMIT 1"
);
$provider_stmt->execute([':uid' => $me]);
$provider = $provider_stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    header('Location: ' . $dashboardUrl);
    exit;
}
$provider_id = (int)$provider['provider_id'];

$booking_id = isset($_GET['booking']) ? (int)$_GET['booking'] : 0;

// AJAX send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message']) && isset($_POST['booking_id'])) {
    header('Content-Type: application/json');
    $post_booking = (int)$_POST['booking_id'];
    $post_msg     = trim((string)$_POST['message']);

    $ctx = getBookingChatContext($db, $post_booking);
    if (!$ctx || (int)$ctx['provider_id'] !== $provider_id) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
        exit;
    }
    $result = sendBookingMessage($db, $post_booking, $me, (int)$ctx['seeker_uid'], $post_msg);
    echo json_encode($result);
    exit;
}

// AJAX poll — short-interval fetch instead of WebSockets, so this keeps
// working on ordinary shared PHP hosting with no persistent process.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll']) && isset($_GET['booking'])) {
    header('Content-Type: application/json');
    $pBooking = (int)$_GET['booking'];
    $pSince   = (int)($_GET['since'] ?? 0);
    $ctx = getBookingChatContext($db, $pBooking);
    if (!$ctx || (int)$ctx['provider_id'] !== $provider_id) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
        exit;
    }
    markBookingMessagesRead($db, $pBooking, $me);
    $newMsgs = array_map(static function ($m) use ($me) {
        return [
            'id' => (int)$m['id'],
            'sender_id' => (int)$m['sender_id'],
            'sender_name' => trim($m['first_name'] . ' ' . $m['last_name']),
            'message' => $m['message'],
            'mine' => (int)$m['sender_id'] === $me,
            'time_label' => date('h:i A', strtotime($m['created_at'])),
        ];
    }, getBookingMessagesSince($db, $pBooking, $pSince));
    echo json_encode([
        'ok' => true,
        'messages' => $newMsgs,
        'status' => $ctx['status'],
        'status_label' => ucwords(str_replace('_', ' ', $ctx['status'])),
        'chat_open' => chatIsOpenForBooking($ctx),
    ]);
    exit;
}

// AJAX inline Accept/Decline — reuses the exact same acceptAvailedBooking()
// helper provider/service-requests.php's own Accept button calls, so
// clicking it here changes the real booking the same way. Declined
// bookings are rejected the same way service-requests.php does.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_action']) && isset($_POST['booking_id'])) {
    header('Content-Type: application/json');
    $act = $_POST['chat_action'];
    $bId = (int)$_POST['booking_id'];
    $ctx = getBookingChatContext($db, $bId);
    if (!$ctx || (int)$ctx['provider_id'] !== $provider_id) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
        exit;
    }
    if ($ctx['status'] !== 'pending') {
        echo json_encode(['ok' => false, 'error' => 'This request is no longer pending.']);
        exit;
    }
    if ($act === 'accept') {
        $result = acceptAvailedBooking($db, $bId, $provider_id, $me, 'provider', 'Accepted from chat.');
        if ($result['ok']) {
            echo json_encode(['ok' => true, 'status' => 'accepted']);
        } else {
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Could not accept.']);
        }
        exit;
    }
    if ($act === 'decline') {
        $db->prepare("UPDATE availed_services SET status = 'cancelled', is_read = 0 WHERE id = :id AND provider_id = :pid AND status = 'pending'")
           ->execute([':id' => $bId, ':pid' => $provider_id]);
        echo json_encode(['ok' => true, 'status' => 'cancelled']);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

$convos = getProviderBookingThreads($db, $provider_id);
$allowed_ids = array_map(static fn($r) => (int)$r['booking_id'], $convos);

if ($booking_id <= 0 && !empty($convos)) {
    $booking_id = (int)$convos[0]['booking_id'];
}
if ($booking_id > 0 && !in_array($booking_id, $allowed_ids, true)) {
    $booking_id = 0;
}

$other = null;
$chat = [];
$chatOpen = false;
if ($booking_id > 0) {
    $other = getBookingChatContext($db, $booking_id);
    if ($other && (int)$other['provider_id'] === $provider_id) {
        markBookingMessagesRead($db, $booking_id, $me);
        $chat = getBookingMessages($db, $booking_id);
        $chatOpen = chatIsOpenForBooking($other);
    } else {
        $other = null;
        $booking_id = 0;
    }
}

$total_unread = (int)array_sum(array_map(static fn($row) => (int)$row['unread'], $convos));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages - Pestify</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap">
<style>
:root{
    --ui-primary:#1e9fe6;
    --ui-primary-dark:#147bb6;
    --ui-secondary:#2ca25f;
    --ui-ink:#0f2747;
    --ui-muted:#6b7d93;
    --ui-glow:0 20px 40px rgba(15,23,42,.08);
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%}
body{
    font-family:'Manrope',sans-serif;
    color:var(--ui-ink);
    background:
        radial-gradient(circle at 8% -18%, rgba(30,159,230,.2), transparent 32%),
        radial-gradient(circle at 98% 2%, rgba(44,162,95,.15), transparent 32%),
        linear-gradient(180deg,#eaf4fb 0%,#d9eaf5 100%);
}
.dashboard-container{display:flex;min-height:100vh}
.sidebar{width:260px;background:linear-gradient(165deg,#0b1a3a 0%,#132f57 55%,#0d3a58 100%);color:#fff;position:fixed;height:100vh;overflow-y:auto;border-right:1px solid rgba(255,255,255,.14);box-shadow:0 12px 35px rgba(2,6,23,.28)}
.sidebar-header{padding:25px 20px;border-bottom:1px solid rgba(255,255,255,.15);background:linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,.02))}
.sidebar-header h2{font-family:'Space Grotesk',sans-serif;font-size:28px;color:#fff;display:flex;align-items:center;gap:10px;letter-spacing:-.4px}
.sidebar-header p{font-size:12px;color:rgba(255,255,255,.78);margin-top:5px}
.sidebar-menu{list-style:none;padding:15px 0}
.sidebar-menu a{display:flex;align-items:center;gap:10px;margin:4px 12px;padding:12px 14px;color:rgba(255,255,255,.92);text-decoration:none;border-radius:12px;font-weight:600;position:relative;overflow:hidden}
.sidebar-menu a i{width:18px;text-align:center}
.sidebar-menu a::before{content:'';position:absolute;left:0;top:0;bottom:0;width:0;background:linear-gradient(180deg,var(--ui-primary),var(--ui-secondary));border-radius:10px;transition:width .25s ease}
.sidebar-menu a:hover,.sidebar-menu a.active{background:rgba(255,255,255,.16);backdrop-filter:blur(3px)}
.sidebar-menu a:hover::before,.sidebar-menu a.active::before{width:4px}
.sidebar-footer{position:absolute;bottom:0;width:100%;padding:20px;border-top:1px solid rgba(255,255,255,.14)}
.user-profile{display:flex;align-items:center;gap:12px;padding:14px;border-radius:12px;border:1px solid rgba(255,255,255,.16);background:linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,.07))}
.user-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;background:#fff;color:#0b3a64}
.user-info h4{font-size:13px;color:#fff;margin:0 0 2px}
.user-info p{font-size:11px;color:rgba(255,255,255,.8);margin:0}
.main-content{flex:1;margin-left:260px;padding:18px 18px 18px 30px;min-height:100vh}
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.page-head h1{font-family:'Space Grotesk',sans-serif;letter-spacing:-.4px;font-size:42px;line-height:1}
.badge{background:var(--ui-primary);color:#fff;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700}
.messenger{
    display:flex;
    height:calc(100vh - 98px);
    border-radius:16px;
    border:1px solid #c9d8e7;
    background:#f7fbff;
    box-shadow:var(--ui-glow);
    overflow:hidden;
}
.conv-list{width:300px;border-right:1px solid #ccd9e7;display:flex;flex-direction:column;background:#f1f5f9}
.conv-top{padding:16px 14px;border-bottom:1px solid #d7e1ea;font-size:24px;font-weight:800;color:#0e2f56;letter-spacing:-.4px}
.conv-search{padding:10px;border-bottom:1px solid #d7e1ea}
.conv-search input{width:100%;padding:9px 11px;border:1px solid #c3d2e1;border-radius:10px;font-size:13px;background:#f5f8fc;font-family:inherit;color:#55677d}
.conv-items{overflow-y:auto;flex:1}
.conv-item{display:flex;align-items:center;gap:11px;padding:12px 12px;border-bottom:1px solid #e3ebf3;text-decoration:none;transition:.15s}
.conv-item:hover{background:#eaf1f8}
.conv-item.active{background:#d6e4f1;border-left:3px solid var(--ui-primary)}
.conv-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2980b9);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
.conv-info{flex:1;min-width:0}
.conv-name{font-size:16px;font-weight:700;color:#103e68;line-height:1.15}
.conv-preview{font-size:13px;color:#6f7f94;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.conv-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px}
.conv-time{font-size:11px;color:#8ea0b5}
.unread{background:var(--ui-primary);color:#fff;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700}
.empty{padding:28px;text-align:center;color:var(--ui-muted);font-size:13px}
.chat-area{flex:1;display:flex;flex-direction:column;min-width:0}
.chat-header{padding:12px 16px;border-bottom:1px solid #d2ddea;display:flex;align-items:center;gap:12px;background:#f8fcff}
.chat-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#2ca25f,#2db56a);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px}
.chat-name{font-size:18px;font-weight:700;color:#0e355d;line-height:1.15}
.chat-status{font-size:12px;color:#7d8fa4}
.chat-messages{flex:1;overflow-y:auto;padding:14px 18px;background:#edf1f5;display:flex;flex-direction:column;gap:10px}
.row{display:flex;gap:8px;align-items:flex-end}
.row.mine{flex-direction:row-reverse}
.av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#2E8B57,#27ae60);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0}
.av.them{background:linear-gradient(135deg,#3498db,#2980b9)}
.bubble{
    display:inline-block;
    width:fit-content;
    max-width:min(62%, 460px);
    min-width:80px;
    padding:10px 15px;
    border-radius:16px;
    font-size:15px;
    line-height:1.45;
    white-space:pre-wrap;
    word-break:normal;
    overflow-wrap:anywhere;
    writing-mode:horizontal-tb;
    text-orientation:mixed;
}
.bubble.me{background:linear-gradient(135deg,#2ca25f,#2db56a);color:#fff;border-bottom-right-radius:4px}
.bubble.them{background:#f4f5f6;border:1px solid #cad6e2;color:#2d3748;border-bottom-left-radius:4px}
.time{font-size:12px;color:#8295aa;margin-top:4px}
.time.r{text-align:right}
.date{text-align:center;font-size:14px;color:#7f95ab;padding:4px 0}
.row > div:last-child{display:flex;flex-direction:column}
.row.mine > div:last-child{align-items:flex-end}
.chat-input{padding:12px 14px;border-top:1px solid #cfdbea;background:#f8fcff;display:flex;gap:10px;align-items:flex-end}
.chat-input textarea{flex:1;padding:11px 14px;border:1px solid #bdd0e2;border-radius:14px;font-size:15px;font-family:inherit;resize:none;max-height:120px;line-height:1.5;background:#f3f7fb}
.send{width:40px;height:40px;border-radius:50%;border:none;background:linear-gradient(135deg,var(--ui-primary),var(--ui-primary-dark));color:#fff;cursor:pointer;box-shadow:0 6px 12px rgba(30,159,230,.3);flex-shrink:0}
.no-chat{flex:1;display:flex;align-items:center;justify-content:center;color:var(--ui-muted)}
.thread-status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:4px}
.thread-status-dot.open{background:#2ca25f}
.thread-status-dot.closed{background:#94a3b8}
.pending-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-left:4px}
.manage-link{background:#eef2ff;color:#3730a3;padding:8px 12px;border-radius:9px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.chat-action-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;background:linear-gradient(135deg,#eff6ff,#f0f9ff);border-bottom:1px solid #bfdbfe;font-size:13px;color:#1e3a8a;font-weight:600}
.chat-accept-btn{background:linear-gradient(135deg,#10b759,#0a9648);color:#fff;border:none;padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer}
.chat-decline-btn{background:#fff;color:#dc2626;border:1.5px solid #fecaca;padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer}
.chat-closed-bar{padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;text-align:center;font-size:13px;color:#64748b;display:flex;align-items:center;justify-content:center;gap:8px}
@media (max-width:1024px){
    .sidebar{width:220px}
    .main-content{margin-left:220px;padding:16px}
    .page-head h1{font-size:34px}
    .conv-top{font-size:21px}
    .conv-name{font-size:17px}
    .conv-preview{font-size:13px}
}
@media (max-width:768px){
    .sidebar{display:none}
    .main-content{margin-left:0;padding:12px}
    .messenger{height:calc(100vh - 84px)}
    .conv-list{width:100%;display:<?php echo $other ? 'none' : 'flex'; ?>}
    .chat-name{font-size:18px}
    .chat-status{font-size:12px}
}
</style>
</head>
<body>
<div class="dashboard-container">
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-bug"></i> Pestify</h2>
            <p>Provider Portal</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="<?php echo htmlspecialchars($dashboardUrl); ?>"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo htmlspecialchars($servicesUrl); ?>"><i class="fas fa-briefcase"></i> My Services</a></li>
            <li><a href="<?php echo htmlspecialchars($serviceRequestsUrl); ?>"><i class="fas fa-list-check"></i> Requests</a></li>
            <li><a href="<?php echo htmlspecialchars($messagesUrl); ?>" class="active"><i class="fas fa-comments"></i> Messages</a></li>
            <li><a href="<?php echo htmlspecialchars($profileUrl); ?>"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="<?php echo htmlspecialchars($dashboardUrl . '?view=settings#dashboard-settings'); ?>"><i class="fas fa-sliders-h"></i> Settings</a></li>
            <li><a href="<?php echo htmlspecialchars($logoutUrl); ?>"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar"><?php echo strtoupper(substr((string)$provider['company_name'], 0, 1)); ?></div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars((string)$provider['company_name']); ?></h4>
                    <p><?php echo htmlspecialchars((string)$provider['email']); ?></p>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="page-head">
            <h1>Messages</h1>
            <?php if ($total_unread > 0): ?><span class="badge"><?php echo $total_unread; ?> new</span><?php endif; ?>
        </div>

        <div class="messenger">
            <div class="conv-list">
                <div class="conv-top">Conversations</div>
                <div class="conv-search"><input type="text" id="searchConvo" placeholder="Search conversations..." oninput="filterConvos(this.value)"></div>
                <div class="conv-items">
                    <?php if (empty($convos)): ?>
                        <div class="empty"><i class="fas fa-inbox"></i><br>No bookings yet</div>
                    <?php endif; ?>
                    <?php foreach ($convos as $c):
                        $cOpen = chatIsOpenForBooking($c);
                    ?>
                        <a href="<?php echo htmlspecialchars($messagesUrl . '?booking=' . (int)$c['booking_id']); ?>" class="conv-item <?php echo ((int)$booking_id === (int)$c['booking_id']) ? 'active' : ''; ?>" data-name="<?php echo htmlspecialchars(strtolower(trim(($c['seeker_name'] ?? '') . ' ' . ($c['service_name'] ?? '')))); ?>">
                            <div class="conv-avatar"><?php echo strtoupper(substr((string)($c['seeker_name'] ?? 'U'), 0, 1)); ?></div>
                            <div class="conv-info">
                                <div class="conv-name"><?php echo htmlspecialchars((string)($c['seeker_name'] ?? 'Seeker')); ?><?php if ($c['status'] === 'pending'): ?> <span class="pending-dot"></span><?php endif; ?></div>
                                <div class="conv-preview">
                                    <span class="thread-status-dot <?php echo $cOpen ? 'open' : 'closed'; ?>"></span>
                                    <?php echo htmlspecialchars((string)($c['service_name'] ?: 'Service')); ?> &middot; <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$c['status']))); ?>
                                </div>
                                <div class="conv-preview" style="opacity:.75;"><?php echo htmlspecialchars((string)($c['last_msg'] ?? 'No messages yet')); ?></div>
                            </div>
                            <div class="conv-meta">
                                <?php if (!empty($c['last_time'])): ?><div class="conv-time"><?php echo date('M j', strtotime((string)$c['last_time'])); ?></div><?php endif; ?>
                                <?php if ((int)$c['unread'] > 0): ?><div class="unread"><?php echo (int)$c['unread']; ?></div><?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="chat-area">
                <?php if ($other): ?>
                    <div class="chat-header">
                        <div class="chat-avatar"><?php echo strtoupper(substr((string)$other['full_name'], 0, 1)); ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="chat-name"><?php echo htmlspecialchars((string)$other['full_name']); ?></div>
                            <div class="chat-status" id="chatStatusLine">
                                <?php echo htmlspecialchars((string)($other['service_name'] ?: 'Service')); ?> &middot;
                                <span id="chatStatusBadge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$other['status']))); ?></span>
                                &middot; &#8369;<?php echo number_format((float)$other['total_amount'], 2); ?>
                                &middot; <?php echo htmlspecialchars(ucfirst((string)$other['payment_status'])); ?>
                            </div>
                        </div>
                        <a href="<?php echo htmlspecialchars($serviceRequestsUrl); ?>?open_booking=<?php echo (int)$booking_id; ?>" target="_blank" rel="noopener" class="manage-link"><i class="fas fa-gear"></i> Manage Booking</a>
                    </div>
                    <?php if ($other['status'] === 'pending'): ?>
                    <div class="chat-action-banner" id="chatActionBanner">
                        <div><i class="fas fa-hourglass-half"></i> This request is pending — accept it to start preparing, or decline it.</div>
                        <div style="display:flex;gap:8px;">
                            <button type="button" class="chat-decline-btn" onclick="chatBookingAction('decline')">Decline</button>
                            <button type="button" class="chat-accept-btn" onclick="chatBookingAction('accept')">Accept</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="chat-messages" id="chatBox">
                        <?php
                        $lastDate = '';
                        foreach ($chat as $msg):
                            $mine = ((int)$msg['sender_id'] === $me);
                            $msgDate = date('M j, Y', strtotime((string)$msg['created_at']));
                            if ($msgDate !== $lastDate):
                                $lastDate = $msgDate;
                        ?>
                            <div class="date"><?php echo $msgDate === date('M j, Y') ? 'Today' : $msgDate; ?></div>
                        <?php endif; ?>
                            <div class="row <?php echo $mine ? 'mine' : ''; ?>">
                                <div class="av <?php echo $mine ? '' : 'them'; ?>"><?php echo strtoupper(substr((string)$msg['first_name'], 0, 1)); ?></div>
                                <div>
                                    <div class="bubble <?php echo $mine ? 'me' : 'them'; ?>"><?php echo htmlspecialchars((string)$msg['message']); ?></div>
                                    <div class="time <?php echo $mine ? 'r' : ''; ?>"><?php echo date('h:i A', strtotime((string)$msg['created_at'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($chat)): ?><div class="no-chat">No messages in this conversation yet.</div><?php endif; ?>
                    </div>
                    <?php if ($chatOpen): ?>
                    <div class="chat-input">
                        <textarea id="msgInput" rows="1" placeholder="Type a message..."></textarea>
                        <button type="button" id="sendBtn" class="send"><i class="fas fa-paper-plane"></i></button>
                    </div>
                    <?php else: ?>
                    <div class="chat-closed-bar"><i class="fas fa-lock"></i> This conversation is closed because the service is <?php echo htmlspecialchars((string)$other['status']); ?>.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-chat">No conversation selected yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
const chatBox = document.getElementById('chatBox');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

const BOOKING_ID = <?php echo (int)$booking_id; ?>;
const ME_ID = <?php echo (int)$me; ?>;
const MSG_ENDPOINT = <?php echo json_encode($messagesUrl); ?>;

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function appendIncomingBubble(payload) {
    if (!chatBox) return;
    const mine = Number(payload.sender_id) === ME_ID;
    const row = document.createElement('div');
    row.className = 'row' + (mine ? ' mine' : '');
    row.innerHTML = `
        <div class="av ${mine ? '' : 'them'}">${escapeHtml((payload.sender_name || '?').substring(0,1).toUpperCase())}</div>
        <div>
            <div class="bubble ${mine ? 'me' : 'them'}">${escapeHtml(payload.message).replace(/\n/g,'<br>')}</div>
            <div class="time ${mine ? 'r' : ''}">${escapeHtml(payload.time_label || '')}</div>
        </div>`;
    chatBox.appendChild(row);
    chatBox.scrollTop = chatBox.scrollHeight;
}

const ta = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');

async function sendChatMessage() {
    if (!ta || !BOOKING_ID) return;
    const msg = ta.value.trim();
    if (!msg) return;
    ta.value = '';
    ta.style.height = 'auto';
    if (sendBtn) sendBtn.disabled = true;
    try {
        const body = new URLSearchParams();
        body.set('booking_id', String(BOOKING_ID));
        body.set('message', msg);
        const res = await fetch(MSG_ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
        const data = await res.json();
        if (!data.ok) { alert(data.error || 'Failed to send message.'); ta.value = msg; }
    } catch (e) { ta.value = msg; }
    if (sendBtn) sendBtn.disabled = false;
}

if (ta) {
    ta.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    ta.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChatMessage(); }
    });
}
if (sendBtn) sendBtn.addEventListener('click', sendChatMessage);

async function chatBookingAction(action) {
    if (!BOOKING_ID) return;
    if (action === 'decline' && !confirm('Decline this request?')) return;
    try {
        const body = new URLSearchParams();
        body.set('chat_action', action);
        body.set('booking_id', String(BOOKING_ID));
        const res = await fetch(MSG_ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
        const data = await res.json();
        if (data.ok) {
            window.location.reload();
        } else {
            alert(data.error || 'Action failed.');
        }
    } catch (e) { alert('Network error.'); }
}

function filterConvos(q) {
    document.querySelectorAll('.conv-item').forEach(function (el) {
        el.style.display = el.dataset.name.includes(q.toLowerCase()) ? '' : 'none';
    });
}

// ── Live updates via short-interval polling (no WebSocket/persistent
// process needed — works on ordinary shared PHP hosting) ────────────────
<?php if ($booking_id): ?>
(function () {
    let lastId = <?php echo !empty($chat) ? (int)end($chat)['id'] : 0; ?>;
    let knownStatus = <?php echo json_encode((string)$other['status']); ?>;
    let inFlight = false;

    async function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        try {
            const res = await fetch(`${MSG_ENDPOINT}?poll=1&booking=${BOOKING_ID}&since=${lastId}`);
            const data = await res.json();
            if (data.ok) {
                (data.messages || []).forEach(function (m) {
                    if (m.mine) return; // sender already sees their own bubble locally
                    appendIncomingBubble(m);
                    lastId = Math.max(lastId, m.id);
                });
                const badge = document.getElementById('chatStatusBadge');
                if (badge && data.status_label) badge.textContent = data.status_label;
                if (data.status !== knownStatus) {
                    knownStatus = data.status;
                    window.location.reload(); // status changed — refresh to update the action banner/closed state
                }
            }
        } catch (e) { /* next tick retries */ }
        inFlight = false;
    }
    setInterval(poll, 4000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
<?php endif; ?>
</script>
<?php include $appRoot . '/includes/provider-guide.php'; ?>
</body>
</html>
