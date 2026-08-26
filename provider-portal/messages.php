<?php
// messages.php  (seeker side — in Pestify root)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once 'config/config.php';
require_once 'config/database.php';
$database = new Database(); $db = $database->getConnection();

date_default_timezone_set('Asia/Manila');

$me = (int)$_SESSION['user_id'];

// ── POST: send message ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $to  = (int)$_POST['to'];
    $msg = trim($_POST['message'] ?? '');
    if ($msg && $to) {
        $db->prepare("INSERT INTO messages (sender_id,receiver_id,message,is_read,created_at) VALUES (:s,:r,:m,0,NOW())")
           ->execute([':s'=>$me,':r'=>$to,':m'=>$msg]);
    }
    header('Location: messages.php?to='.$to); exit;
}

// ── Mark messages as read ──────────────────────────────────
$to_user = isset($_GET['to']) ? (int)$_GET['to'] : 0;
if ($to_user) {
    $db->prepare("UPDATE messages SET is_read=1 WHERE sender_id=:s AND receiver_id=:me")
       ->execute([':s'=>$to_user,':me'=>$me]);
}

// ── Fetch conversations list ───────────────────────────────
$conversations = $db->prepare("
    SELECT
        u.id, u.first_name, u.last_name, u.user_type,
        (SELECT message FROM messages WHERE (sender_id=u.id AND receiver_id=:me) OR (sender_id=:me2 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_msg,
        (SELECT created_at FROM messages WHERE (sender_id=u.id AND receiver_id=:me3) OR (sender_id=:me4 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_time,
        (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=:me5 AND is_read=0) AS unread
    FROM users u
    WHERE u.id IN (
        SELECT DISTINCT CASE WHEN sender_id=:me6 THEN receiver_id ELSE sender_id END
        FROM messages WHERE sender_id=:me7 OR receiver_id=:me8
    )
    ORDER BY last_time DESC
");
$conversations->execute([':me'=>$me,':me2'=>$me,':me3'=>$me,':me4'=>$me,':me5'=>$me,':me6'=>$me,':me7'=>$me,':me8'=>$me]);
$convos = $conversations->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch chat with selected user ──────────────────────────
$chat = [];
$other = null;
if ($to_user) {
    $s = $db->prepare("SELECT u.id,u.first_name,u.last_name,u.user_type FROM users u WHERE u.id=:id");
    $s->execute([':id'=>$to_user]);
    $other = $s->fetch(PDO::FETCH_ASSOC);

    $c = $db->prepare("SELECT m.*, u.first_name, u.last_name FROM messages m JOIN users u ON m.sender_id=u.id
                        WHERE (m.sender_id=:me AND m.receiver_id=:to) OR (m.sender_id=:to2 AND m.receiver_id=:me2)
                        ORDER BY m.created_at ASC");
    $c->execute([':me'=>$me,':to'=>$to_user,':to2'=>$to_user,':me2'=>$me]);
    $chat = $c->fetchAll(PDO::FETCH_ASSOC);

    // If this user isn't in convos yet (new conversation)
    if ($other && !in_array($to_user, array_column($convos,'id'))) {
        array_unshift($convos, array_merge($other, ['last_msg'=>'','last_time'=>'','unread'=>0]));
    }
}

function timeAgo($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60).'m ago';
    if ($diff < 86400) return date('h:i A', strtotime($dt));
    return date('M j', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages · Pestify</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#3498db;--dark:#1a2744;--bg:#f0f4f8;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748;height:100vh;display:flex;flex-direction:column}

/* Top bar */
.topbar{background:#fff;border-bottom:1px solid var(--border);padding:14px 24px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.topbar a{color:var(--muted);text-decoration:none;font-size:13px;display:flex;align-items:center;gap:6px}
.topbar a:hover{color:var(--dark)}
.topbar h1{font-size:17px;font-weight:700;color:var(--dark)}

/* Messenger layout */
.messenger{display:flex;flex:1;overflow:hidden;max-width:1100px;width:100%;margin:24px auto;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1);background:#fff;border:1px solid var(--border)}

/* Sidebar */
.conv-list{width:300px;border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
.conv-search{padding:14px 16px;border-bottom:1px solid var(--border)}
.conv-search input{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;background:#f8fafc}
.conv-search input:focus{outline:none;border-color:var(--primary)}
.conv-items{flex:1;overflow-y:auto}
.conv-item{display:flex;align-items:center;gap:11px;padding:13px 16px;cursor:pointer;border-bottom:1px solid #f8fafc;transition:background .15s;text-decoration:none}
.conv-item:hover{background:#f8fafc}
.conv-item.active{background:#eff6ff;border-left:3px solid var(--primary)}
.conv-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2980b9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0}
.conv-avatar.provider{background:linear-gradient(135deg,#2E8B57,#27ae60)}
.conv-info{flex:1;min-width:0}
.conv-name{font-size:13px;font-weight:700;color:var(--dark)}
.conv-preview{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.conv-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.conv-time{font-size:11px;color:var(--muted)}
.unread-badge{background:var(--primary);color:#fff;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700}
.conv-empty{padding:30px;text-align:center;color:var(--muted);font-size:13px}

/* Chat area */
.chat-area{flex:1;display:flex;flex-direction:column;min-width:0}
.chat-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;background:#fff}
.chat-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#2E8B57,#27ae60);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.chat-name{font-size:15px;font-weight:700;color:var(--dark)}
.chat-status{font-size:12px;color:var(--muted)}
.chat-messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:10px;background:#f8fafc}
.msg-row{display:flex;gap:8px;align-items:flex-end}
.msg-row.mine{flex-direction:row-reverse}
.msg-avatar-sm{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#2E8B57,#27ae60);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0}
.msg-avatar-sm.me{background:linear-gradient(135deg,#3498db,#2980b9)}
.bubble{max-width:65%;padding:10px 14px;border-radius:14px;font-size:13.5px;line-height:1.5;word-break:break-word}
.bubble.them{background:#fff;border:1px solid var(--border);border-bottom-left-radius:4px;color:#2d3748}
.bubble.me{background:linear-gradient(135deg,#3498db,#2980b9);color:#fff;border-bottom-right-radius:4px}
.msg-time{font-size:10px;color:var(--muted);margin-top:3px;text-align:right}
.msg-time.them{text-align:left}
.chat-date-sep{text-align:center;font-size:11px;color:var(--muted);padding:6px 0}

/* Input bar */
.chat-input{padding:14px 16px;border-top:1px solid var(--border);background:#fff}
.chat-input form{display:flex;gap:10px;align-items:flex-end}
.chat-input textarea{flex:1;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;font-size:14px;font-family:inherit;resize:none;max-height:120px;overflow-y:auto;line-height:1.5;background:#f8fafc;transition:border .2s}
.chat-input textarea:focus{outline:none;border-color:var(--primary);background:#fff}
.send-btn{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2980b9);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;transition:all .2s}
.send-btn:hover{transform:scale(1.08);box-shadow:0 4px 12px rgba(52,152,219,.4)}

/* No chat selected */
.no-chat{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);gap:12px}
.no-chat i{font-size:48px;opacity:.2}
.no-chat p{font-size:14px}

@media(max-width:700px){.conv-list{display:<?= $to_user?'none':'flex' ?>}.messenger{margin:0;border-radius:0;border:none;height:100%}}
</style>
</head>
<body>
<div class="topbar">
    <a href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
    <h1><i class="fas fa-comment-dots" style="color:var(--primary)"></i> Messages</h1>
</div>

<div class="messenger">
    <!-- Conversations list -->
    <div class="conv-list">
        <div class="conv-search">
            <input type="text" id="searchConvo" placeholder="Search conversations…" oninput="filterConvos(this.value)">
        </div>
        <div class="conv-items" id="convoList">
        <?php if (empty($convos)): ?>
            <div class="conv-empty"><i class="fas fa-comment-slash" style="font-size:28px;opacity:.3;display:block;margin-bottom:8px"></i>No conversations yet</div>
        <?php endif; ?>
        <?php foreach ($convos as $c):
            $initials = strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1));
            $isProvider = ($c['user_type'] === 'provider');
        ?>
        <a href="messages.php?to=<?= $c['id'] ?>" class="conv-item <?= $to_user==$c['id']?'active':'' ?>" data-name="<?= htmlspecialchars(strtolower($c['first_name'].' '.$c['last_name'])) ?>">
            <div class="conv-avatar <?= $isProvider?'provider':'' ?>"><?= $initials ?></div>
            <div class="conv-info">
                <div class="conv-name"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></div>
                <div class="conv-preview"><?= htmlspecialchars($c['last_msg'] ?? 'Start a conversation') ?></div>
            </div>
            <div class="conv-meta">
                <?php if ($c['last_time']): ?><div class="conv-time"><?= timeAgo($c['last_time']) ?></div><?php endif; ?>
                <?php if ($c['unread'] > 0): ?><div class="unread-badge"><?= $c['unread'] ?></div><?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Chat area -->
    <div class="chat-area">
    <?php if ($other): ?>
        <div class="chat-header">
            <div class="chat-avatar"><?= strtoupper(substr($other['first_name'],0,1).substr($other['last_name'],0,1)) ?></div>
            <div>
                <div class="chat-name"><?= htmlspecialchars($other['first_name'].' '.$other['last_name']) ?></div>
                <div class="chat-status"><?= ucfirst($other['user_type']) ?></div>
            </div>
        </div>
        <div class="chat-messages" id="chatBox">
        <?php
        $lastDate = '';
        foreach ($chat as $msg):
            $isMine = ($msg['sender_id'] == $me);
            $msgDate = date('M j, Y', strtotime($msg['created_at']));
            if ($msgDate !== $lastDate):
                $lastDate = $msgDate;
        ?>
            <div class="chat-date-sep"><?= $msgDate === date('M j, Y') ? 'Today' : $msgDate ?></div>
        <?php endif; ?>
            <div class="msg-row <?= $isMine?'mine':'' ?>">
                <div class="msg-avatar-sm <?= $isMine?'me':'' ?>"><?= strtoupper(substr($msg['first_name'],0,1)) ?></div>
                <div>
                    <div class="bubble <?= $isMine?'me':'them' ?>"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                    <div class="msg-time <?= $isMine?'':'them' ?>"><?= date('h:i A', strtotime($msg['created_at'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <div class="chat-input">
            <form method="POST">
                <input type="hidden" name="to" value="<?= $to_user ?>">
                <textarea name="message" id="msgInput" placeholder="Type a message…" rows="1" required></textarea>
                <button type="submit" name="send" class="send-btn"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    <?php else: ?>
        <div class="no-chat">
            <i class="fas fa-comments"></i>
            <p>Select a conversation to start messaging</p>
        </div>
    <?php endif; ?>
    </div>
</div>

<script>
// Auto-scroll to bottom
const chatBox = document.getElementById('chatBox');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

// Auto-resize textarea
const ta = document.getElementById('msgInput');
if (ta) {
    ta.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    // Send on Enter (Shift+Enter for newline)
    ta.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.closest('form').submit();
        }
    });
}

// Search conversations
function filterConvos(q) {
    document.querySelectorAll('.conv-item').forEach(el => {
        el.style.display = el.dataset.name.includes(q.toLowerCase()) ? '' : 'none';
    });
}
</script>
</body>
</html>