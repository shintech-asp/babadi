<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Shared DB connection for header (avatar + notification bell) ──────────
$_hdr_db = null;
if (isset($_SESSION['user_id'])) {
    try {
        if (!class_exists('Database')) require_once __DIR__ . '/../config/database.php';
        $_hdr_db_inst = new Database();
        $_hdr_db = $_hdr_db_inst->getConnection();
    } catch (Throwable $e) {}
}

// ── Avatar ────────────────────────────────────────────────────────────────
$header_avatar_url = '';
if (isset($_SESSION['user_id'])) {
    $header_avatar_url = trim((string)($_SESSION['profile_image'] ?? ''));
    if ($header_avatar_url === '' && $_hdr_db) {
        try {
            $stmt = $_hdr_db->prepare("SELECT profile_image FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int)$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['profile_image'])) {
                $header_avatar_url = trim((string)$row['profile_image']);
                $_SESSION['profile_image'] = $header_avatar_url;
            }
        } catch (Throwable $e) {}
    }
}

// ── Notification Bell data ────────────────────────────────────────────────
if (!function_exists('_hdrTimeAgo')) {
    function _hdrTimeAgo($dt) {
        $d = time() - strtotime((string)$dt);
        if ($d < 60)     return 'just now';
        if ($d < 3600)   return floor($d / 60) . 'm ago';
        if ($d < 86400)  return floor($d / 3600) . 'h ago';
        if ($d < 604800) return floor($d / 86400) . 'd ago';
        return date('M j', strtotime((string)$dt));
    }
}
$_bell_items  = [];
$_bell_unread = 0;
if (isset($_SESSION['user_id']) && $_hdr_db) {
    $_buid = (int)$_SESSION['user_id'];

    // Handle "Mark all read" — update DB then fall through so the page renders fresh
    if (isset($_GET['mark_notif_read']) && $_GET['mark_notif_read'] === '1') {
        try { $_hdr_db->prepare("UPDATE availed_services SET is_read=1 WHERE seeker_user_id=:u")->execute([':u' => $_buid]); } catch (Exception $e) {}
        try { $_hdr_db->prepare("UPDATE messages SET is_read=1 WHERE seeker_user_id=:u")->execute([':u' => $_buid]); } catch (Exception $e) {}
        // No redirect — just let the queries below re-fetch as all-read
    }

    // Status notifications (availed_services)
    try {
        $s = $_hdr_db->prepare("
            SELECT a.id, a.service_name, a.status, a.is_read, a.created_at, p.company_name
            FROM   availed_services a
            JOIN   providers p ON a.provider_id = p.id
            WHERE  a.seeker_user_id = :u
            ORDER  BY a.created_at DESC LIMIT 20
        ");
        $s->execute([':u' => $_buid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $_bell_items[] = [
                'type'       => 'status',
                'title'      => $r['service_name'] ?? 'Service',
                'body'       => 'Status: ' . ucwords(str_replace('_', ' ', $r['status'])) . ' — ' . $r['company_name'],
                'is_read'    => (bool)$r['is_read'],
                'created_at' => $r['created_at'],
                'link'       => appUrl('my-requests.php'),
                'icon'       => 'fa-clipboard-list',
                'color'      => '#3498db',
            ];
            if (!$r['is_read']) $_bell_unread++;
        }
    } catch (Exception $e) {}

    // Message notifications
    try {
        $m = $_hdr_db->prepare("
            SELECT m.id, m.message, m.is_read, m.created_at, p.company_name, p.id AS pid
            FROM   messages m
            JOIN   providers p ON m.provider_id = p.id
            WHERE  m.seeker_user_id = :u
            ORDER  BY m.created_at DESC LIMIT 20
        ");
        $m->execute([':u' => $_buid]);
        foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $_bell_items[] = [
                'type'       => 'message',
                'title'      => $r['company_name'],
                'body'       => mb_substr($r['message'], 0, 70) . (mb_strlen($r['message']) > 70 ? '…' : ''),
                'is_read'    => (bool)$r['is_read'],
                'created_at' => $r['created_at'],
                'link'       => appUrl('provider-details.php') . '?id=' . (int)$r['pid'],
                'icon'       => 'fa-comment-dots',
                'color'      => '#8e44ad',
            ];
            if (!$r['is_read']) $_bell_unread++;
        }
    } catch (Exception $e) {}

    usort($_bell_items, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $_bell_items = array_slice($_bell_items, 0, 25);
}

// Get current page for active navigation
$current_page = isset($current_page) ? $current_page : basename($_SERVER['PHP_SELF'], '.php');
$hide_discovery_links = !empty($hide_discovery_links);
$is_provider_session = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'provider';
$provider_dashboard_url = appUrl('providers-dashboard.php');
$provider_services_url = appUrl('services.php');
$provider_create_listing_url = appUrl('create-listing.php');
$provider_requests_url = appUrl('service-requests.php');
$messages_url = appUrl('messages.php');
$profile_url = appUrl('profile.php');
$logout_url = appUrl('logout.php');
$my_requests_url = appUrl('my-requests.php');
$request_service_url = appUrl('request-service.php');
$provider_dashboard_active = in_array($current_page, ['dashboard', 'providers-dashboard'], true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Pestify' : 'Pestify - Professional Pest Control Services'; ?></title>
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/style.css'); ?>">
    <?php if (!empty($use_seeker_unified_ui)): ?>
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/seeker-unified.css'); ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="<?php echo appUrl('assets/images/favicon.ico'); ?>">
    <?php if (!empty($extra_head)) echo $extra_head; ?>
    <style>
        /* ─── Notification Bell ──────────────────────────────────── */
        .notif-bell-wrap { display:inline-flex; align-items:center; position:relative; }
        .notif-bell-btn {
            width:38px; height:38px; border-radius:50%;
            background:#f0f4ff; color:#1a2744; border:2px solid #d0d8f0;
            cursor:pointer; font-size:16px; display:flex; align-items:center;
            justify-content:center; transition:all 0.2s; position:relative;
            box-shadow:0 2px 8px rgba(26,39,68,0.12);
        }
        .notif-bell-btn:hover { background:#1a2744; color:white; border-color:#1a2744; transform:scale(1.08); }
        .notif-bell-btn.has-notif { background:#fff3f3; border-color:#e74c3c; color:#e74c3c; animation:bellShake 2.5s ease infinite; }
        .notif-bell-btn.has-notif:hover { background:#e74c3c; color:white; }
        @keyframes bellShake {
            0%,55%,100% { transform:rotate(0deg); }
            60% { transform:rotate(-12deg); } 65% { transform:rotate(12deg); }
            70% { transform:rotate(-8deg); }  75% { transform:rotate(8deg); }
            80% { transform:rotate(0deg); }
        }
        .notif-badge {
            position:absolute; top:-6px; right:-6px;
            background:#e74c3c; color:white; font-size:10px; font-weight:800;
            min-width:18px; height:18px; border-radius:9px;
            display:flex; align-items:center; justify-content:center;
            border:2px solid white; padding:0 3px; line-height:1;
            animation:badgePop 0.3s cubic-bezier(0.34,1.56,0.64,1); pointer-events:none;
        }
        @keyframes badgePop { from { transform:scale(0) rotate(-15deg); } to { transform:scale(1) rotate(0deg); } }
        .notif-panel {
            position:absolute; top:calc(100% + 10px); right:-10px;
            width:380px; max-height:520px; background:white;
            border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,0.15),0 4px 16px rgba(0,0,0,0.08);
            border:1px solid #e8edf2; display:none; flex-direction:column;
            overflow:hidden; z-index:999999;
            animation:panelDrop 0.22s cubic-bezier(0.34,1.2,0.64,1);
        }
        .notif-panel.open { display:flex; }
        @keyframes panelDrop { from { opacity:0; transform:translateY(-12px) scale(0.96); } to { opacity:1; transform:translateY(0) scale(1); } }
        .notif-panel-header { padding:16px 18px 12px; background:linear-gradient(135deg,#1a2744 0%,#2d4a8a 100%); color:white; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
        .notif-panel-title { font-size:14px; font-weight:700; display:flex; align-items:center; gap:8px; letter-spacing:0.2px; }
        .notif-new-pill { background:rgba(255,255,255,0.2); color:white; font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px; }
        .notif-mark-all { font-size:11px; color:rgba(255,255,255,0.85); text-decoration:none; background:rgba(255,255,255,0.15); padding:5px 12px; border-radius:20px; transition:background 0.2s; white-space:nowrap; border:1px solid rgba(255,255,255,0.2); }
        .notif-mark-all:hover { background:rgba(255,255,255,0.28); color:white; }
        .notif-tabs { display:flex; background:#f8fafc; border-bottom:2px solid #f0f4f8; flex-shrink:0; padding:0 6px; gap:2px; }
        .notif-tab { flex:1; padding:10px 8px; font-size:12px; font-weight:600; color:#94a3b8; border:none; background:none; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all 0.18s; display:flex; align-items:center; justify-content:center; gap:5px; white-space:nowrap; font-family:inherit; border-radius:6px 6px 0 0; }
        .notif-tab:hover { color:#1a2744; background:#eef2ff; }
        .notif-tab.active { color:#1a2744; border-bottom-color:#3b82f6; background:white; font-weight:700; }
        .notif-tab-count { background:#e74c3c; color:white; font-size:10px; font-weight:800; padding:1px 5px; border-radius:10px; line-height:1.5; min-width:16px; text-align:center; }
        .notif-list { overflow-y:auto; flex:1; scrollbar-width:thin; scrollbar-color:#e0e7ff transparent; }
        .notif-list::-webkit-scrollbar { width:4px; }
        .notif-list::-webkit-scrollbar-thumb { background:#e0e7ff; border-radius:4px; }
        .notif-item { display:flex; align-items:flex-start; gap:12px; padding:14px 16px; border-bottom:1px solid #f4f7fb; text-decoration:none; transition:background 0.15s; cursor:pointer; color:inherit; }
        .notif-item:last-child { border-bottom:none; }
        .notif-item:hover { background:#f8fbff; }
        .notif-item.unread { background:linear-gradient(90deg,#f0f7ff 0%,#fafcff 100%); border-left:3px solid #3b82f6; }
        .notif-item.unread:hover { background:#e8f3ff; }
        .notif-item-icon { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .notif-item-body { flex:1; min-width:0; }
        .notif-item-title { font-size:13px; font-weight:700; color:#1a1f3a; margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .notif-item-text { font-size:12px; color:#64748b; line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .notif-item-time { font-size:11px; color:#b0bec5; margin-top:5px; display:flex; align-items:center; gap:4px; }
        .notif-unread-dot { width:8px; height:8px; border-radius:50%; background:#3b82f6; flex-shrink:0; margin-top:6px; animation:dotPulse 2s ease infinite; }
        @keyframes dotPulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:0.6; transform:scale(0.8); } }
        .notif-empty { text-align:center; padding:44px 20px; color:#b0bec5; }
        .notif-empty i { font-size:40px; display:block; margin-bottom:12px; opacity:0.35; }
        .notif-empty p { font-size:13px; margin:0; }
        .notif-panel-footer { padding:12px 18px; border-top:1px solid #f0f4f8; background:#fafbfc; flex-shrink:0; }
        .notif-view-all { font-size:13px; font-weight:600; color:#3b82f6; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px; padding:8px; border-radius:8px; transition:background 0.15s; }
        .notif-view-all:hover { background:#eff6ff; color:#1d4ed8; }
        @media (max-width:768px) { .notif-panel { width:300px; right:-60px; } }
        @media (max-width:480px) { .notif-panel { width:calc(100vw - 24px); right:-80px; } }
    </style>
</head>
<body class="<?php
    $__body_cls = [];
    if (!empty($use_seeker_unified_ui)) $__body_cls[] = 'seeker-unified';
    if (!empty($body_class)) $__body_cls[] = htmlspecialchars((string)$body_class, ENT_QUOTES, 'UTF-8');
    echo implode(' ', $__body_cls);
?>">
    <?php include __DIR__ . '/login_success_alert.php'; ?>
    <header class="main-header">
        <nav class="navbar container">
            <div class="logo-section">
                <a href="<?php echo appUrl('index.php'); ?>" class="logo-link">
                    <div class="logo-container">
                        <div class="logo-icon-wrapper">
                            <div class="logo-icon">
                                <i class="fas fa-bug"></i>
                            </div>
                        </div>
                        <div class="logo-text">
                            <div class="logo-main">Pestify</div>
                            <div class="logo-tagline">Pest Control Experts</div>
                        </div>
                    </div>
                </a>
            </div>

            <ul class="nav-menu">

                <?php if (!$hide_discovery_links): ?>
                <li>
                    <a href="<?php echo appUrl('listings.php'); ?>" class="<?php echo $current_page == 'listings' ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i> Find Services
                    </a>
                </li>
                <li>
                    <a href="<?php echo appUrl('providers.php'); ?>" class="<?php echo $current_page == 'providers' ? 'active' : ''; ?>">
                        <i class="fas fa-building"></i> Providers
                    </a>
                </li>
                <?php endif; ?>

                <?php if(isset($_SESSION['user_id'])): ?>
                    <?php if($is_provider_session): ?>
                        <li>
                            <a href="<?php echo $provider_dashboard_url; ?>" class="<?php echo $provider_dashboard_active ? 'active' : ''; ?>">
                                <i class="fas fa-chart-line"></i> Dashboard
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo $provider_create_listing_url; ?>" class="btn-primary">
                                <i class="fas fa-plus"></i> Post Service
                            </a>
                        </li>
                    <?php else: ?>
                        <li>
                            <a href="<?php echo $my_requests_url; ?>" class="<?php echo $current_page == 'my-requests' ? 'active' : ''; ?>">
                                <i class="fas fa-list"></i> My Requests
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo $request_service_url; ?>" class="btn-primary">
                                <i class="fas fa-calendar-plus"></i> Request Service
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Notification Bell -->
                    <li style="display:flex;align-items:center;">
                        <div class="notif-bell-wrap" id="notifBellWrap">
                            <button class="notif-bell-btn <?php echo $_bell_unread > 0 ? 'has-notif' : ''; ?>"
                                    id="notifBellBtn" onclick="toggleNotifPanel(event)" title="Notifications &amp; Messages">
                                <i class="fas fa-bell"></i>
                                <?php if ($_bell_unread > 0): ?>
                                    <span class="notif-badge"><?php echo $_bell_unread > 99 ? '99+' : $_bell_unread; ?></span>
                                <?php endif; ?>
                            </button>

                            <div class="notif-panel" id="notifPanel">
                                <div class="notif-panel-header">
                                    <span class="notif-panel-title">
                                        <i class="fas fa-bell"></i> Notifications
                                        <?php if ($_bell_unread > 0): ?><span class="notif-new-pill"><?php echo $_bell_unread; ?> new</span><?php endif; ?>
                                    </span>
                                    <?php if ($_bell_unread > 0): ?>
                                        <a href="?mark_notif_read=1" class="notif-mark-all"><i class="fas fa-check-double"></i> Mark all read</a>
                                    <?php endif; ?>
                                </div>

                                <div class="notif-tabs">
                                    <button class="notif-tab active" id="tabAll" onclick="switchNotifTab('all')">
                                        <i class="fas fa-layer-group"></i> All
                                        <?php if ($_bell_unread > 0): ?><span class="notif-tab-count"><?php echo $_bell_unread; ?></span><?php endif; ?>
                                    </button>
                                    <button class="notif-tab" id="tabMessages" onclick="switchNotifTab('message')">
                                        <i class="fas fa-comment-dots"></i> Messages
                                        <?php $_bmsg = count(array_filter($_bell_items, fn($n) => $n['type']==='message' && !$n['is_read']));
                                        if ($_bmsg > 0) echo '<span class="notif-tab-count">'.$_bmsg.'</span>'; ?>
                                    </button>
                                    <button class="notif-tab" id="tabStatus" onclick="switchNotifTab('status')">
                                        <i class="fas fa-clipboard-check"></i> Requests
                                        <?php $_breq = count(array_filter($_bell_items, fn($n) => $n['type']==='status' && !$n['is_read']));
                                        if ($_breq > 0) echo '<span class="notif-tab-count">'.$_breq.'</span>'; ?>
                                    </button>
                                </div>

                                <div class="notif-list" id="notifList">
                                    <?php if (count($_bell_items) > 0):
                                        $_bStatusLabels = [
                                            'pending'                          => ['label'=>'Pending',              'color'=>'#f59e0b','bg'=>'#fffbeb'],
                                            'accepted'                         => ['label'=>'Accepted',             'color'=>'#10b981','bg'=>'#ecfdf5'],
                                            'preparing'                        => ['label'=>'Preparing',            'color'=>'#06b6d4','bg'=>'#ecfeff'],
                                            'starting'                         => ['label'=>'Starting',             'color'=>'#3b82f6','bg'=>'#eff6ff'],
                                            'on_going'                         => ['label'=>'Ongoing',              'color'=>'#10b981','bg'=>'#ecfdf5'],
                                            'ongoing'                          => ['label'=>'Ongoing',              'color'=>'#10b981','bg'=>'#ecfdf5'],
                                            'completed'                        => ['label'=>'Completed',            'color'=>'#22c55e','bg'=>'#f0fdf4'],
                                            'cancelled'                        => ['label'=>'Cancelled',            'color'=>'#ef4444','bg'=>'#fef2f2'],
                                            'waiting_for_remaining_payment'    => ['label'=>'Awaiting Payment',     'color'=>'#f97316','bg'=>'#fff7ed'],
                                            'waiting_for_seeker_confirmation'  => ['label'=>'Awaiting Your Info',   'color'=>'#8b5cf6','bg'=>'#f5f3ff'],
                                            'waiting_for_provider_confirmation'=> ['label'=>'Awaiting Confirm.',    'color'=>'#0ea5e9','bg'=>'#f0f9ff'],
                                        ];
                                        foreach ($_bell_items as $_bi):
                                            $_braw = '';
                                            if ($_bi['type'] === 'status') {
                                                preg_match('/Status: ([^\x{2014}]+)/u', $_bi['body'], $_bm);
                                                $_braw = isset($_bm[1]) ? strtolower(trim(str_replace(' ', '_', trim($_bm[1])))) : '';
                                            }
                                            $_bsl = $_bStatusLabels[$_braw] ?? ['label'=>'','color'=>$_bi['color'],'bg'=>$_bi['color'].'18'];
                                    ?>
                                    <a href="<?php echo htmlspecialchars($_bi['link']); ?>"
                                       class="notif-item <?php echo !$_bi['is_read'] ? 'unread' : ''; ?>"
                                       data-type="<?php echo $_bi['type']; ?>">
                                        <div class="notif-item-icon"
                                             style="background:<?php echo $_bi['type']==='status' ? $_bsl['bg'] : $_bi['color'].'18'; ?>;color:<?php echo $_bi['type']==='status' ? $_bsl['color'] : $_bi['color']; ?>;">
                                            <i class="fas <?php echo $_bi['icon']; ?>"></i>
                                        </div>
                                        <div class="notif-item-body">
                                            <div class="notif-item-title"><?php echo htmlspecialchars($_bi['title']); ?></div>
                                            <?php if ($_bi['type']==='status' && $_bsl['label']): ?>
                                                <div class="notif-item-text">
                                                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:<?php echo $_bsl['color']; ?>;background:<?php echo $_bsl['bg']; ?>;padding:2px 8px;border-radius:20px;"><?php echo $_bsl['label']; ?></span>
                                                    &nbsp;<span style="font-size:12px;color:#64748b;"><?php
                                                        $_bparts = explode('—', $_bi['body']);
                                                        echo isset($_bparts[1]) ? htmlspecialchars(trim($_bparts[1])) : '';
                                                    ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div class="notif-item-text"><?php echo htmlspecialchars($_bi['body']); ?></div>
                                            <?php endif; ?>
                                            <div class="notif-item-time"><i class="fas fa-clock"></i><?php echo _hdrTimeAgo($_bi['created_at']); ?></div>
                                        </div>
                                        <?php if (!$_bi['is_read']): ?><div class="notif-unread-dot"></div><?php endif; ?>
                                    </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notif-empty"><i class="fas fa-bell-slash"></i><p>You're all caught up!</p></div>
                                <?php endif; ?>
                                </div>

                                <div class="notif-panel-footer">
                                    <a href="<?php echo appUrl('my-requests.php'); ?>" class="notif-view-all">View all requests <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </li>

                    <!-- User Menu -->
                    <li class="user-menu">
                        <a href="javascript:void(0)" class="user-name">
                            <div class="user-avatar">
                                <?php if (!empty($header_avatar_url)): ?>
                                    <img src="<?php echo htmlspecialchars($header_avatar_url); ?>" alt="Profile Photo" class="user-avatar-img">
                                <?php else: ?>
                                    <?php
                                    $initials = '';
                                    if(isset($_SESSION['first_name']) && !empty($_SESSION['first_name'])) {
                                        $initials = strtoupper(substr($_SESSION['first_name'], 0, 1));
                                    }
                                    echo $initials ?: 'U';
                                    ?>
                                <?php endif; ?>
                            </div>
                            <div class="user-info">
                                <span><?php echo isset($_SESSION['first_name']) ? htmlspecialchars($_SESSION['first_name']) : 'User'; ?></span>
                                <span><?php echo isset($_SESSION['user_type']) ? ucfirst($_SESSION['user_type']) : 'Member'; ?></span>
                            </div>
                            <i class="fas fa-chevron-down"></i>
                        </a>
                        <div class="dropdown-menu">
                            <a href="<?php echo $profile_url; ?>">
                                <i class="fas fa-user"></i> My Profile
                            </a>
                            <?php if($is_provider_session): ?>
                                <a href="<?php echo $provider_dashboard_url; ?>">
                                    <i class="fas fa-chart-line"></i> Dashboard
                                </a>
                                <a href="<?php echo $provider_create_listing_url; ?>">
                                    <i class="fas fa-plus"></i> Create Service
                                </a>
                                <a href="<?php echo $provider_services_url; ?>">
                                    <i class="fas fa-list"></i> My Services
                                </a>
                                <a href="<?php echo $provider_requests_url; ?>">
                                    <i class="fas fa-list-check"></i> Requests
                                </a>
                                <a href="<?php echo $messages_url; ?>">
                                    <i class="fas fa-envelope"></i> Messages
                                </a>
                            <?php else: ?>
                                <a href="<?php echo $my_requests_url; ?>">
                                    <i class="fas fa-list"></i> My Requests
                                </a>
                                <a href="<?php echo $messages_url; ?>">
                                    <i class="fas fa-envelope"></i> Messages
                                </a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?php echo $logout_url; ?>">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                <?php else: ?>
                    <!-- Auth Buttons -->
                    <li>
                        <a href="<?php echo appUrl('login.php'); ?>" class="btn-outline">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo appUrl('register.php'); ?>" class="btn-primary">
                            <i class="fas fa-user-plus"></i> Sign Up
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <button class="mobile-menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
        </nav>
    </header>

    <script>
        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            var mobileToggle = document.querySelector('.mobile-menu-toggle');
            var navMenu = document.querySelector('.nav-menu');

            if (mobileToggle && navMenu) {
                mobileToggle.addEventListener('click', function() {
                    navMenu.classList.toggle('show');
                });
            }

            // Close mobile menu when clicking a link
            document.querySelectorAll('.nav-menu a').forEach(function(link) {
                link.addEventListener('click', function() {
                    navMenu && navMenu.classList.remove('show');
                });
            });

            // User dropdown toggle
            var userMenu = document.querySelector('.user-name');
            var dropdownMenu = document.querySelector('.dropdown-menu');

            if (userMenu && dropdownMenu) {
                userMenu.addEventListener('click', function(e) {
                    e.stopPropagation();
                    dropdownMenu.classList.toggle('show');
                });

                document.addEventListener('click', function(e) {
                    if (!e.target.closest('.user-menu')) {
                        dropdownMenu.classList.remove('show');
                    }
                });
            }
        });

        // Notification bell
        function toggleNotifPanel(e) {
            e.stopPropagation();
            var p = document.getElementById('notifPanel');
            if (p) p.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            var w = document.getElementById('notifBellWrap');
            var p = document.getElementById('notifPanel');
            if (p && w && !w.contains(e.target)) p.classList.remove('open');
        });
        function switchNotifTab(type) {
            document.querySelectorAll('.notif-tab').forEach(function(t) { t.classList.remove('active'); });
            var map = {all:'tabAll', message:'tabMessages', status:'tabStatus'};
            var el = document.getElementById(map[type]);
            if (el) el.classList.add('active');
            document.querySelectorAll('.notif-item').forEach(function(item) {
                item.style.display = (type === 'all' || item.dataset.type === type) ? 'flex' : 'none';
            });
            var list = document.getElementById('notifList');
            if (!list) return;
            var any = false;
            list.querySelectorAll('.notif-item').forEach(function(el) { if (el.style.display !== 'none') any = true; });
            var empty = list.querySelector('.notif-empty');
            if (empty) empty.style.display = any ? 'none' : 'block';
        }
    </script>
