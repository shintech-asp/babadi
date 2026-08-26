<?php
$appRoot = dirname(__DIR__);
chdir($appRoot);
if (!isset($db) || !($db instanceof PDO)) {
    require_once $appRoot . '/config/config.php';
    require_once $appRoot . '/config/database.php';
    $database = new Database();
    $db = $database->getConnection();
}

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

$to_user = isset($_GET['to']) ? (int)$_GET['to'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $post_to = (int)($_POST['to'] ?? 0);
    $post_msg = trim($_POST['message'] ?? '');

    if ($post_to > 0 && $post_msg !== '') {
        $db->prepare("INSERT INTO messages (sender_id,receiver_id,message,is_read,created_at) VALUES (:s,:r,:m,0,NOW())")
           ->execute([':s' => $me, ':r' => $post_to, ':m' => $post_msg]);
    }

    header('Location: ' . $messagesUrl . ($post_to > 0 ? '?to=' . $post_to : ''));
    exit;
}

$conversations = $db->prepare(
    "SELECT
        u.id, u.first_name, u.last_name, u.user_type,
        (SELECT message FROM messages WHERE (sender_id=u.id AND receiver_id=:me) OR (sender_id=:me2 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_msg,
        (SELECT created_at FROM messages WHERE (sender_id=u.id AND receiver_id=:me3) OR (sender_id=:me4 AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_time,
        (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=:me5 AND is_read=0) AS unread
     FROM users u
     WHERE u.id IN (
        SELECT DISTINCT CASE WHEN sender_id=:me6 THEN receiver_id ELSE sender_id END
        FROM messages
        WHERE sender_id=:me7 OR receiver_id=:me8
     )
     ORDER BY last_time DESC"
);
$conversations->execute([
    ':me' => $me,
    ':me2' => $me,
    ':me3' => $me,
    ':me4' => $me,
    ':me5' => $me,
    ':me6' => $me,
    ':me7' => $me,
    ':me8' => $me,
]);
$convos = $conversations->fetchAll(PDO::FETCH_ASSOC);

$allowed_ids = array_map(static fn($r) => (int)$r['id'], $convos);
if ($to_user > 0 && !in_array($to_user, $allowed_ids, true)) {
    $to_user = 0;
}
if ($to_user <= 0 && !empty($convos)) {
    $to_user = (int)$convos[0]['id'];
}

if ($to_user > 0) {
    $db->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = :s AND receiver_id = :me")
       ->execute([':s' => $to_user, ':me' => $me]);
}

$other = null;
$chat = [];
if ($to_user > 0) {
    $s = $db->prepare("SELECT id, first_name, last_name, user_type FROM users WHERE id = :id LIMIT 1");
    $s->execute([':id' => $to_user]);
    $other = $s->fetch(PDO::FETCH_ASSOC);

    if ($other) {
        $c = $db->prepare(
            "SELECT m.*, u.first_name, u.last_name
             FROM messages m
             JOIN users u ON m.sender_id = u.id
             WHERE (m.sender_id = :me AND m.receiver_id = :to)
                OR (m.sender_id = :to2 AND m.receiver_id = :me2)
             ORDER BY m.created_at ASC"
        );
        $c->execute([':me' => $me, ':to' => $to_user, ':to2' => $to_user, ':me2' => $me]);
        $chat = $c->fetchAll(PDO::FETCH_ASSOC);
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
.chat-input{padding:12px 14px;border-top:1px solid #cfdbea;background:#f8fcff}
.chat-input form{display:flex;gap:10px;align-items:flex-end}
.chat-input textarea{flex:1;padding:11px 14px;border:1px solid #bdd0e2;border-radius:14px;font-size:15px;font-family:inherit;resize:none;max-height:120px;line-height:1.5;background:#f3f7fb}
.send{width:40px;height:40px;border-radius:50%;border:none;background:linear-gradient(135deg,var(--ui-primary),var(--ui-primary-dark));color:#fff;cursor:pointer;box-shadow:0 6px 12px rgba(30,159,230,.3)}
.no-chat{flex:1;display:flex;align-items:center;justify-content:center;color:var(--ui-muted)}
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
                        <div class="empty"><i class="fas fa-inbox"></i><br>No messages yet</div>
                    <?php endif; ?>
                    <?php foreach ($convos as $c): ?>
                        <a href="<?php echo htmlspecialchars($messagesUrl . '?to=' . (int)$c['id']); ?>" class="conv-item <?php echo ((int)$to_user === (int)$c['id']) ? 'active' : ''; ?>" data-name="<?php echo htmlspecialchars(strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')))); ?>">
                            <div class="conv-avatar"><?php echo strtoupper(substr((string)($c['first_name'] ?? 'U'), 0, 1)); ?></div>
                            <div class="conv-info">
                                <div class="conv-name"><?php echo htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))); ?></div>
                                <div class="conv-preview"><?php echo htmlspecialchars((string)($c['last_msg'] ?? '')); ?></div>
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
                        <div class="chat-avatar"><?php echo strtoupper(substr((string)$other['first_name'], 0, 1)); ?></div>
                        <div>
                            <div class="chat-name"><?php echo htmlspecialchars((string)$other['first_name'] . ' ' . (string)$other['last_name']); ?></div>
                            <div class="chat-status"><?php echo ucfirst((string)$other['user_type']); ?></div>
                        </div>
                    </div>
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
                    <div class="chat-input">
                        <form method="POST">
                            <input type="hidden" name="to" value="<?php echo (int)$to_user; ?>">
                            <textarea name="message" id="msgInput" rows="1" placeholder="Type a message..." required></textarea>
                            <button type="submit" class="send"><i class="fas fa-paper-plane"></i></button>
                        </form>
                    </div>
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

const ta = document.getElementById('msgInput');
if (ta) {
    ta.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    ta.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.closest('form').submit();
        }
    });
}

function filterConvos(q) {
    document.querySelectorAll('.conv-item').forEach(function (el) {
        el.style.display = el.dataset.name.includes(q.toLowerCase()) ? '' : 'none';
    });
}
</script>
<?php include $appRoot . '/includes/provider-guide.php'; ?>
</body>
</html>
