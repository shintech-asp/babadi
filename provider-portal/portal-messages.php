<?php
// provider-portal/portal-messages.php — transaction-scoped chat (CRM /
// technician / owner side). One thread per booking, closed on completed/
// cancelled. Previously this page's AJAX send routed through api/messages.php,
// which had its OWN separate copy of the access-control check — missing
// the $is_field_tech clause added below, so a field technician's chat
// input silently 403'd even though the page showed it to them. Now sends
// itself (like the other rewritten chat pages), through the one shared
// sendBookingMessage() helper — no second copy of this logic to drift.
// See CLAUDE.md's "Recent Work Log" for the full design writeup.
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/transaction_chat_helper.php';

date_default_timezone_set('Asia/Manila');

// Field technicians get full access alongside CRM/owner — they're the ones
// actually on-site and often the ones who need to coordinate with the seeker.
$is_field_tech = (($_SESSION['portal_staff_type'] ?? 'office') === 'field');

// Owner and all managers can view messages
$can_view = ($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'finance' || $portal_dept === 'crm' || $portal_dept === 'all' || $is_field_tech);
if (!$can_view) { header('Location: dashboard.php'); exit; }
$can_reply = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all' || $is_field_tech);

$database = new Database(); $db = $database->getConnection();
$pid = (int)$portal_provider_id;
// Sidebar defaults to the free tier when $tier_is_paid is unset — this page
// never loaded portal-tier.php, so a paid provider's own sidebar always
// showed Pro nav items as locked here.
require_once 'includes/portal-tier.php';

// Get the provider's user_id (the account that receives messages from seekers)
$prov = $db->prepare("SELECT user_id FROM providers WHERE id=:p");
$prov->execute([':p'=>$pid]);
$prov_row = $prov->fetch(PDO::FETCH_ASSOC);
$prov_user_id = (int)($prov_row['user_id'] ?? 0);

if (!$prov_user_id) { echo "Provider user not found."; exit; }

$booking_id = isset($_GET['booking']) ? (int)$_GET['booking'] : 0;

// ── AJAX: reply ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message']) && isset($_POST['booking_id'])) {
    header('Content-Type: application/json');
    if (!$can_reply) {
        echo json_encode(['ok' => false, 'error' => 'Only owner, CRM, and field technicians can reply.']);
        exit;
    }
    $post_booking = (int)$_POST['booking_id'];
    $post_msg     = trim((string)$_POST['message']);
    $ctx = getBookingChatContext($db, $post_booking);
    if (!$ctx || (int)$ctx['provider_id'] !== $pid) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
        exit;
    }
    $result = sendBookingMessage($db, $post_booking, $prov_user_id, (int)$ctx['seeker_uid'], $post_msg);
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
    if (!$ctx || (int)$ctx['provider_id'] !== $pid) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
        exit;
    }
    markBookingMessagesRead($db, $pBooking, $prov_user_id);
    $newMsgs = array_map(static function ($m) use ($prov_user_id) {
        return [
            'id' => (int)$m['id'],
            'sender_id' => (int)$m['sender_id'],
            'sender_name' => trim($m['first_name'] . ' ' . $m['last_name']),
            'message' => $m['message'],
            'mine' => (int)$m['sender_id'] === $prov_user_id,
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

$convo_list = getProviderBookingThreads($db, $pid);
$allowed_ids = array_map(static fn($r) => (int)$r['booking_id'], $convo_list);
if ($booking_id > 0 && !in_array($booking_id, $allowed_ids, true)) {
    $booking_id = 0;
}
if ($booking_id <= 0 && !empty($convo_list)) {
    $booking_id = (int)$convo_list[0]['booking_id'];
}

$total_unread = array_sum(array_column($convo_list, 'unread'));

// ── Chat messages ─────────────────────────────────────────────
$chat = [];
$other = null;
$chatOpen = false;
if ($booking_id > 0) {
    $other = getBookingChatContext($db, $booking_id);
    if ($other && (int)$other['provider_id'] === $pid) {
        markBookingMessagesRead($db, $booking_id, $prov_user_id);
        $chat = getBookingMessages($db, $booking_id);
        $chatOpen = chatIsOpenForBooking($other);
    } else {
        $other = null;
        $booking_id = 0;
    }
}
$to_user = $booking_id; // kept for the (unchanged) mobile-narrow CSS check below

function timeAgo($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60).'m ago';
    if ($diff < 86400) return date('h:i A', strtotime($dt));
    return date('M j', strtotime($dt));
}

$active_menu = 'messages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;overflow:hidden}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;height:100vh;min-height:100vh;overflow:hidden}
.portal-main{flex:1;min-width:0;display:flex;flex-direction:column;min-height:0;overflow:hidden}

/* Messenger wrapper */
.msg-wrapper{flex:1;display:flex;flex-direction:column;padding:24px;gap:0;min-height:0;overflow:hidden}
.page-header{margin-bottom:18px}
.page-header h1{font-size:20px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}

.messenger{display:flex;flex:1;border-radius:16px;border:1px solid var(--border);background:#fff;box-shadow:0 4px 20px rgba(0,0,0,.07);overflow:hidden;min-height:0}

/* Conversation list */
.conv-list{width:290px;border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;background:#fafbfc}
.conv-top{padding:16px;border-bottom:1px solid var(--border);background:#fff}
.conv-top h3{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;justify-content:space-between}
.unread-total{background:var(--primary);color:#fff;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700}
.conv-search{padding:10px 14px;border-bottom:1px solid var(--border)}
.conv-search input{width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;background:#f8fafc}
.conv-search input:focus{outline:none;border-color:var(--primary)}
.conv-items{flex:1;overflow-y:auto}
.conv-item{display:flex;align-items:center;gap:11px;padding:12px 14px;cursor:pointer;border-bottom:1px solid #f0f4f8;transition:background .15s;text-decoration:none}
.conv-item:hover{background:#f0f4f8}
.conv-item.active{background:#f0fdf4;border-left:3px solid var(--primary)}
.conv-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2980b9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0;position:relative}
.conv-info{flex:1;min-width:0}
.conv-name{font-size:13px;font-weight:700;color:var(--dark)}
.conv-preview{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.conv-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.conv-time{font-size:11px;color:var(--muted)}
.unread-dot{background:var(--primary);color:#fff;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700}
.conv-empty{padding:30px;text-align:center;color:var(--muted);font-size:13px}
.conv-empty i{font-size:32px;opacity:.2;display:block;margin-bottom:10px}

/* Chat area */
.chat-area{flex:1;display:flex;flex-direction:column;min-width:0;min-height:0}
.chat-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;background:#fff}
.chat-hav{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2980b9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.chat-hname{font-size:15px;font-weight:700;color:var(--dark)}
.chat-hsub{font-size:12px;color:var(--muted)}
.ro-badge{margin-left:auto;background:#fef3c7;color:#92400e;border-radius:999px;padding:4px 12px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:5px}

/* Messages */
.chat-messages{flex:1;min-height:0;overflow-y:auto;padding:20px;display:flex;flex-direction:column;justify-content:flex-start;align-content:flex-start;gap:10px;background:#f8fafc}
.msg-row{display:flex;gap:8px;align-items:flex-end;margin:0;padding:0}
.msg-row.mine{flex-direction:row-reverse}
.msg-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2980b9);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0}
.msg-av.me{background:linear-gradient(135deg,#2E8B57,#27ae60)}
.bubble{max-width:65%;min-width:60px;padding:10px 14px;border-radius:14px;font-size:13.5px;line-height:1.5;word-break:break-word;overflow-wrap:break-word;white-space:pre-wrap}
.bubble.them{background:#fff;border:1px solid var(--border);border-bottom-left-radius:4px;color:#2d3748}
.bubble.me{background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff;border-bottom-right-radius:4px}
.msg-time{font-size:10px;color:var(--muted);margin-top:3px}
.msg-time.r{text-align:right}
.date-sep{text-align:center;font-size:11px;color:var(--muted);padding:6px 0;margin:2px 0}

/* Input */
.chat-input{padding:14px 16px;border-top:1px solid var(--border);background:#fff}
.chat-input form{display:flex;gap:10px;align-items:flex-end}
.chat-input textarea{flex:1;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;font-size:14px;font-family:inherit;resize:none;max-height:120px;overflow-y:auto;line-height:1.5;background:#f8fafc;transition:border .2s}
.chat-input textarea:focus{outline:none;border-color:var(--primary);background:#fff}
.send-btn{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#2E8B57,#27ae60);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;transition:all .2s}
.send-btn:hover{transform:scale(1.08);box-shadow:0 4px 12px rgba(46,139,87,.4)}
.readonly-bar{padding:14px 20px;border-top:1px solid var(--border);background:#f8fafc;text-align:center;font-size:13px;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:8px}

/* No chat */
.no-chat{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);gap:12px}
.no-chat i{font-size:52px;opacity:.15}
.no-chat p{font-size:14px}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="msg-wrapper">

<div class="page-header">
    <h1><i class="fas fa-comment-dots"></i> Messages
        <?php if ($total_unread > 0): ?>
        <span style="background:var(--primary);color:#fff;border-radius:999px;padding:3px 10px;font-size:13px;margin-left:4px"><?= $total_unread ?> new</span>
        <?php endif; ?>
    </h1>
    <p>All conversations with clients — <?= $can_reply ? 'you can read and reply' : 'read-only view for managers' ?></p>
</div>

<div class="messenger">
    <!-- Conversation List -->
    <div class="conv-list">
        <div class="conv-top">
            <h3>Conversations <?php if ($total_unread > 0): ?><span class="unread-total"><?= $total_unread ?></span><?php endif; ?></h3>
        </div>
        <div class="conv-search">
            <input type="text" id="searchConvo" placeholder="Search…" oninput="filterConvos(this.value)">
        </div>
        <div class="conv-items">
        <?php if (empty($convo_list)): ?>
            <div class="conv-empty"><i class="fas fa-inbox"></i>No bookings yet</div>
        <?php endif; ?>
        <?php foreach ($convo_list as $c):
            $init = strtoupper(substr((string)($c['seeker_name'] ?? 'U'), 0, 1));
            $cOpen = chatIsOpenForBooking($c);
        ?>
        <a href="portal-messages.php?booking=<?= (int)$c['booking_id'] ?>" class="conv-item <?= $booking_id==$c['booking_id']?'active':'' ?>" data-name="<?= htmlspecialchars(strtolower(($c['seeker_name'] ?? '') . ' ' . ($c['service_name'] ?? ''))) ?>">
            <div class="conv-avatar"><?= $init ?></div>
            <div class="conv-info">
                <div class="conv-name"><?= htmlspecialchars((string)($c['seeker_name'] ?? 'Seeker')) ?></div>
                <div class="conv-preview"><span class="thread-status-dot <?= $cOpen ? 'open' : 'closed' ?>"></span><?= htmlspecialchars((string)($c['service_name'] ?: 'Service')) ?> &middot; <?= htmlspecialchars(ucwords(str_replace('_',' ',(string)$c['status']))) ?></div>
                <div class="conv-preview" style="opacity:.75;"><?= htmlspecialchars(mb_substr($c['last_msg'] ?? 'No messages yet', 0, 40)) ?></div>
            </div>
            <div class="conv-meta">
                <?php if ($c['last_time']): ?><div class="conv-time"><?= timeAgo($c['last_time']) ?></div><?php endif; ?>
                <?php if ($c['unread'] > 0): ?><div class="unread-dot"><?= $c['unread'] ?></div><?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Chat Area -->
    <div class="chat-area">
    <?php if ($other): ?>
        <div class="chat-header">
            <div class="chat-hav"><?= strtoupper(substr((string)$other['full_name'], 0, 1)) ?></div>
            <div style="flex:1;min-width:0;">
                <div class="chat-hname"><?= htmlspecialchars((string)$other['full_name']) ?></div>
                <div class="chat-hsub">
                    <?= htmlspecialchars((string)($other['service_name'] ?: 'Service')) ?> &middot;
                    <span id="chatStatusBadge"><?= htmlspecialchars(ucwords(str_replace('_',' ',(string)$other['status']))) ?></span>
                    &middot; &#8369;<?= number_format((float)$other['total_amount'], 2) ?>
                    &middot; <?= htmlspecialchars(ucfirst((string)$other['payment_status'])) ?>
                </div>
            </div>
            <?php if (!$can_reply): ?>
            <div class="ro-badge"><i class="fas fa-eye"></i> Read-only</div>
            <?php endif; ?>
        </div>

        <div class="chat-messages" id="chatBox">
        <?php
        $lastDate = '';
        foreach ($chat as $msg):
            $isMine = ($msg['sender_id'] == $prov_user_id);
            $msgDate = date('M j, Y', strtotime($msg['created_at']));
            if ($msgDate !== $lastDate): $lastDate = $msgDate;
        ?>
            <div class="date-sep"><?= $msgDate === date('M j, Y') ? 'Today' : $msgDate ?></div>
        <?php endif; ?>
            <div class="msg-row <?= $isMine?'mine':'' ?>">
                <div class="msg-av <?= $isMine?'me':'' ?>"><?= strtoupper(substr($msg['first_name'],0,1)) ?></div>
                <div>
                    <div class="bubble <?= $isMine?'me':'them' ?>"><?= htmlspecialchars($msg['message']) ?></div>
                    <div class="msg-time <?= $isMine?'r':'' ?>"><?= date('h:i A', strtotime($msg['created_at'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($chat)): ?>
            <div style="text-align:center;color:var(--muted);font-size:13px;margin-top:40px">No messages in this conversation yet.</div>
        <?php endif; ?>
        </div>

        <?php if (!$chatOpen): ?>
        <div class="readonly-bar"><i class="fas fa-lock"></i> This conversation is closed because the service is <?= htmlspecialchars((string)$other['status']) ?>.</div>
        <?php elseif ($can_reply): ?>
        <div class="chat-input">
            <div id="msgForm">
                <div style="display:flex;gap:10px;align-items:flex-end">
                    <textarea id="msgInput" placeholder="Reply as <?= htmlspecialchars($portal_company) ?>…" rows="1"></textarea>
                    <button type="button" id="sendBtn" class="send-btn"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="readonly-bar">
            <i class="fas fa-lock"></i> Only owner, CRM, and field technicians can reply. You have read-only access.
        </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="no-chat">
            <i class="fas fa-comments"></i>
            <p>Select a conversation to view messages</p>
        </div>
    <?php endif; ?>
    </div>
</div>

</div></div></div>

<script>
if ('scrollRestoration' in history) {
    history.scrollRestoration = 'manual';
}

const chatBox = document.getElementById('chatBox');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
window.addEventListener('load', function () {
    window.scrollTo(0, 0);
    if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
});

const ta  = document.getElementById('msgInput');
const btn = document.getElementById('sendBtn');
const BOOKING_ID = <?php echo (int)$booking_id; ?>;
const ME_ID = <?php echo (int)$prov_user_id; ?>;
const provInitial = <?php echo json_encode(strtoupper(substr($portal_company, 0, 1))); ?>;
// Plain relative filename, not appUrl() — this page was never a legacy
// root-level alias (canonicalAppRoute()'s map only covers seeker/provider/
// auth/browse pages), so appUrl('portal-messages.php') silently resolved to
// the wrong path (site root, missing the provider-portal/ prefix), 404ing
// every send/poll fetch. A bare relative name resolves correctly since this
// script only ever runs loaded from its own directory — same convention the
// conversation-list links in this file already use (see the <a href> above).
const MSG_ENDPOINT = 'portal-messages.php';

function focusInputNoScroll() {
    if (!ta) return;
    try {
        ta.focus({ preventScroll: true });
    } catch (e) {
        const x = window.scrollX || 0;
        const y = window.scrollY || 0;
        ta.focus();
        window.scrollTo(x, y);
    }
}

function autoResize() {
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
}

function isNearBottom(el, threshold = 80) {
    if (!el) return true;
    return (el.scrollHeight - el.scrollTop - el.clientHeight) <= threshold;
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function appendBubble(payload, stickToBottom = true) {
    if (!chatBox) return;
    const mine = Number(payload.sender_id) === ME_ID;
    const row = document.createElement('div');
    row.className = 'msg-row' + (mine ? ' mine' : '');
    const initial = mine ? provInitial : escapeHtml((payload.sender_name || '?').substring(0, 1).toUpperCase());
    row.innerHTML = `
        <div class="msg-av ${mine ? 'me' : ''}">${initial}</div>
        <div>
            <div class="bubble ${mine ? 'me' : 'them'}">${escapeHtml(payload.message).replace(/\n/g,'<br>')}</div>
            <div class="msg-time ${mine ? 'r' : ''}">${escapeHtml(payload.time_label || '')}</div>
        </div>`;
    chatBox.appendChild(row);
    if (stickToBottom) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
}

async function sendMessage() {
    if (!ta || !BOOKING_ID) return;
    const msg = ta.value.trim();
    if (!msg) return;
    const winY = window.scrollY || 0;
    const stickToBottom = isNearBottom(chatBox);
    ta.value = '';
    autoResize();
    if (btn) btn.disabled = true;

    try {
        const body = new URLSearchParams();
        body.set('booking_id', String(BOOKING_ID));
        body.set('message', msg);
        const res = await fetch(MSG_ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
        const data = await res.json();
        if (!data.ok) {
            ta.value = msg;
            alert(data.error || 'Unable to send message right now.');
        }
        // No local append — the next poll tick (or the sender's own optimistic
        // echo below) renders it, so every viewer sees identical output.
        else {
            appendBubble({ sender_id: ME_ID, message: msg, time_label: new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) }, stickToBottom);
            lastMsgId = Math.max(lastMsgId, data.id || 0);
        }
    } catch(e) {
        ta.value = msg;
    }
    if (btn) btn.disabled = false;
    focusInputNoScroll();
    if (window.scrollY !== winY) {
        window.scrollTo(window.scrollX || 0, winY);
    }
}

if (ta) {
    ta.addEventListener('input', autoResize);
    ta.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
}
if (btn) btn.addEventListener('click', sendMessage);

function filterConvos(q) {
    document.querySelectorAll('.conv-item').forEach(el => {
        el.style.display = el.dataset.name.includes(q.toLowerCase()) ? '' : 'none';
    });
}

// ── Live updates via short-interval polling (no WebSocket/persistent
// process needed — works on ordinary shared PHP hosting) ────────────────
let lastMsgId = <?php echo !empty($chat) ? (int)end($chat)['id'] : 0; ?>;
<?php if ($booking_id): ?>
(function () {
    let knownStatus = <?php echo json_encode((string)$other['status']); ?>;
    let inFlight = false;

    async function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        try {
            const res = await fetch(`${MSG_ENDPOINT}?poll=1&booking=${BOOKING_ID}&since=${lastMsgId}`);
            const data = await res.json();
            if (data.ok) {
                (data.messages || []).forEach(function (m) {
                    if (m.mine) return;
                    appendBubble(m);
                    lastMsgId = Math.max(lastMsgId, m.id);
                });
                const badge = document.getElementById('chatStatusBadge');
                if (badge && data.status_label) badge.textContent = data.status_label;
                if (data.status !== knownStatus) {
                    knownStatus = data.status;
                    window.location.reload();
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
</body>
</html>
