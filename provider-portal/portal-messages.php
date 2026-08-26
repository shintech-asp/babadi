<?php
// provider-portal/portal-messages.php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

date_default_timezone_set('Asia/Manila');

// Owner and all managers can view messages
$can_view = ($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'finance' || $portal_dept === 'crm' || $portal_dept === 'all');
if (!$can_view) { header('Location: dashboard.php'); exit; }
$can_reply = ($portal_role === 'owner' || $portal_dept === 'crm' || $portal_dept === 'all');

$database = new Database(); $db = $database->getConnection();
$pid = (int)$portal_provider_id;

// Get the provider's user_id (the account that receives messages from seekers)
$prov = $db->prepare("SELECT user_id FROM providers WHERE id=:p");
$prov->execute([':p'=>$pid]);
$prov_row = $prov->fetch(PDO::FETCH_ASSOC);
$prov_user_id = (int)($prov_row['user_id'] ?? 0);

if (!$prov_user_id) { echo "Provider user not found."; exit; }

function portalTargetAllowed(PDO $db, int $providerId, int $providerUserId, int $targetUserId): bool {
    if ($targetUserId <= 0 || $targetUserId === $providerUserId) return false;

    $existing = $db->prepare(
        "SELECT 1
         FROM messages
         WHERE (sender_id = :me AND receiver_id = :target)
            OR (sender_id = :target2 AND receiver_id = :me2)
         LIMIT 1"
    );
    $existing->execute([
        ':me' => $providerUserId,
        ':target' => $targetUserId,
        ':target2' => $targetUserId,
        ':me2' => $providerUserId
    ]);
    if ($existing->fetchColumn()) return true;

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

    return (bool)$request->fetchColumn();
}

// ── AJAX: reply ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    if ($can_reply) {
        $to  = (int)$_POST['to'];
        $msg = trim($_POST['message'] ?? '');
        if ($msg && $to && portalTargetAllowed($db, $pid, $prov_user_id, $to)) {
            $db->prepare("INSERT INTO messages (sender_id,receiver_id,message,is_read,created_at) VALUES (:s,:r,:m,0,NOW())")
               ->execute([':s'=>$prov_user_id,':r'=>$to,':m'=>$msg]);
            $new_id = $db->lastInsertId();
            header('Content-Type: application/json');
            echo json_encode(['ok'=>true,'id'=>$new_id,'time'=>date('h:i A')]);
            exit;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false]);
    exit;
}

// ── Mark as read ─────────────────────────────────────────────
$to_user = isset($_GET['to']) ? (int)$_GET['to'] : 0;
if ($to_user && !portalTargetAllowed($db, $pid, $prov_user_id, $to_user)) {
    $to_user = 0;
}
if ($to_user) {
    $db->prepare("UPDATE messages SET is_read=1 WHERE sender_id=:s AND receiver_id=:me")
       ->execute([':s'=>$to_user,':me'=>$prov_user_id]);
}

// ── Conversations list ────────────────────────────────────────
$convos = $db->prepare("
    SELECT
        u.id, u.first_name, u.last_name, u.user_type,
        (SELECT message FROM messages WHERE (sender_id=u.id AND receiver_id=:puid) OR (sender_id=:puid2 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_msg,
        (SELECT created_at FROM messages WHERE (sender_id=u.id AND receiver_id=:puid3) OR (sender_id=:puid4 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_time,
        (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=:puid5 AND is_read=0) AS unread
    FROM users u
    WHERE u.id IN (
        SELECT DISTINCT CASE WHEN sender_id=:puid6 THEN receiver_id ELSE sender_id END
        FROM messages WHERE sender_id=:puid7 OR receiver_id=:puid8
    )
    ORDER BY last_time DESC
");
$convos->execute([':puid'=>$prov_user_id,':puid2'=>$prov_user_id,':puid3'=>$prov_user_id,
                  ':puid4'=>$prov_user_id,':puid5'=>$prov_user_id,':puid6'=>$prov_user_id,
                  ':puid7'=>$prov_user_id,':puid8'=>$prov_user_id]);
$convo_list = $convos->fetchAll(PDO::FETCH_ASSOC);

$total_unread = array_sum(array_column($convo_list, 'unread'));

// ── Chat messages ─────────────────────────────────────────────
$chat = [];
$other = null;
if ($to_user) {
    $s = $db->prepare("SELECT id,first_name,last_name,user_type FROM users WHERE id=:id");
    $s->execute([':id'=>$to_user]);
    $other = $s->fetch(PDO::FETCH_ASSOC);

    $c = $db->prepare("SELECT m.*, u.first_name, u.last_name FROM messages m JOIN users u ON m.sender_id=u.id
                        WHERE (m.sender_id=:puid AND m.receiver_id=:to) OR (m.sender_id=:to2 AND m.receiver_id=:puid2)
                        ORDER BY m.created_at ASC");
    $c->execute([':puid'=>$prov_user_id,':to'=>$to_user,':to2'=>$to_user,':puid2'=>$prov_user_id]);
    $chat = $c->fetchAll(PDO::FETCH_ASSOC);
}

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
            <div class="conv-empty"><i class="fas fa-inbox"></i>No messages yet</div>
        <?php endif; ?>
        <?php foreach ($convo_list as $c):
            $init = strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1));
        ?>
        <a href="portal-messages.php?to=<?= $c['id'] ?>" class="conv-item <?= $to_user==$c['id']?'active':'' ?>" data-name="<?= htmlspecialchars(strtolower($c['first_name'].' '.$c['last_name'])) ?>">
            <div class="conv-avatar"><?= $init ?></div>
            <div class="conv-info">
                <div class="conv-name"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></div>
                <div class="conv-preview"><?= htmlspecialchars(mb_substr($c['last_msg'] ?? '—', 0, 40)) ?></div>
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
            <div class="chat-hav"><?= strtoupper(substr($other['first_name'],0,1).substr($other['last_name'],0,1)) ?></div>
            <div>
                <div class="chat-hname"><?= htmlspecialchars($other['first_name'].' '.$other['last_name']) ?></div>
                <div class="chat-hsub"><?= ucfirst($other['user_type']) ?> · <?= count($chat) ?> message(s)</div>
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

        <?php if ($can_reply): ?>
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
            <i class="fas fa-lock"></i> Only owner and CRM users can reply. You have read-only access.
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
const toUser = <?php echo $to_user ?: 0; ?>;
const provInitial = <?php echo json_encode(strtoupper(substr($portal_company, 0, 1))); ?>;
const MSG_API_URL = <?php echo json_encode(SITE_URL . '/api/messages.php'); ?>;

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

function appendBubble(msg, time, stickToBottom = true) {
    const row = document.createElement('div');
    row.className = 'msg-row mine';
    row.innerHTML = `
        <div class="msg-av me">${provInitial}</div>
        <div>
            <div class="bubble me">${escapeHtml(msg).replace(/\n/g,'<br>')}</div>
            <div class="msg-time r">${time}</div>
        </div>`;
    chatBox.appendChild(row);
    if (stickToBottom) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
}

async function sendMessage() {
    if (!ta || !toUser) return;
    const msg = ta.value.trim();
    if (!msg) return;
    const winY = window.scrollY || 0;
    const stickToBottom = isNearBottom(chatBox);
    ta.value = '';
    autoResize();
    if (btn) btn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('message', msg);
    fd.append('to', toUser);

    try {
        const res = await fetch(MSG_API_URL, { method:'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data.ok) {
            appendBubble(msg, data.time, stickToBottom);
        } else {
            ta.value = msg;
            alert(data.error || 'Unable to send message right now.');
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
</script>
</body>
</html>
