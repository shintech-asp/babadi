<?php

session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();


try {
    $db->exec("CREATE TABLE IF NOT EXISTS service_reviews (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        avail_id        INT NOT NULL,
        seeker_user_id  INT NOT NULL,
        provider_id     INT NOT NULL,
        service_name    VARCHAR(255) DEFAULT NULL,
        rating          TINYINT NOT NULL,
        feedback        TEXT DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_review (avail_id, seeker_user_id)
    )");
} catch (Exception $e) {}

// â”€â”€ Get featured service listings â”€â”€
// Uses service_reviews (not the non-existent `reviews` table)
$query = "SELECT sl.*, sl.service_name AS title, p.company_name, p.logo_url, sc.name as category_name,
          (SELECT ROUND(AVG(r.rating),1) FROM service_reviews r WHERE r.provider_id = p.id) as avg_rating,
          (SELECT COUNT(*)               FROM service_reviews r WHERE r.provider_id = p.id) as review_count
          FROM services sl
          JOIN providers p  ON sl.provider_id = p.id
          LEFT JOIN service_categories sc ON sl.category_id = sc.id
          WHERE sl.status = 'active'
          ORDER BY sl.views_count DESC, sl.created_at DESC
          LIMIT 6";
$stmt = $db->prepare($query);
$stmt->execute();
$featured_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// â”€â”€ Get categories â”€â”€
$query = "SELECT * FROM service_categories ORDER BY name";
$stmt = $db->prepare($query);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// â”€â”€ Get top providers â”€â”€
// - avg_rating and review_count from service_reviews
// - service_count: active listings from services
// - availed_count: completed jobs from availed_services
$query = "SELECT p.*,
          (SELECT ROUND(AVG(r.rating),1) FROM service_reviews r WHERE r.provider_id = p.id)             AS avg_rating,
          (SELECT COUNT(*)               FROM service_reviews r WHERE r.provider_id = p.id)             AS review_count,
          (SELECT COUNT(*)               FROM services sl
                                         WHERE sl.provider_id = p.user_id AND sl.status = 'active')     AS service_count,
          (SELECT COUNT(*)               FROM availed_services av
                                         WHERE av.provider_id = p.id AND av.status = 'completed')       AS completed_count
          FROM providers p
          WHERE p.status = 'active'
          ORDER BY avg_rating DESC, completed_count DESC
          LIMIT 4";
$stmt = $db->prepare($query);
$stmt->execute();
$top_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// â”€â”€ Notifications & Messages (only for logged-in users) â”€â”€
$notif_items   = [];
$notif_unread  = 0;

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    // 1. Availed service status updates
    try {
        $nSql = "SELECT
                    a.id, a.service_name, a.status, a.is_read, a.created_at, a.preferred_date,
                    p.company_name
                 FROM availed_services a
                 JOIN providers p ON a.provider_id = p.id
                 WHERE a.seeker_user_id = :uid
                 ORDER BY a.created_at DESC
                 LIMIT 20";
        $nStmt = $db->prepare($nSql);
        $nStmt->execute([':uid' => $uid]);
        foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notif_items[] = [
                'type'       => 'status',
                'id'         => $row['id'],
                'title'      => $row['service_name'] ?? 'Service',
                'body'       => 'Status: ' . ucwords(str_replace('_', ' ', $row['status'])) . ' â€” ' . $row['company_name'],
                'is_read'    => (bool)$row['is_read'],
                'created_at' => $row['created_at'],
                'link'       => appUrl('my-requests.php'),
                'icon'       => 'fa-clipboard-list',
                'color'      => '#3498db',
            ];
            if (!$row['is_read']) $notif_unread++;
        }
    } catch (Exception $e) {}

    // 2. Messages sent by providers to this seeker
    try {
        $mSql = "SELECT m.id, m.message, m.is_read, m.created_at, p.company_name, p.id as provider_id
                 FROM messages m
                 JOIN providers p ON m.provider_id = p.id
                 WHERE m.seeker_user_id = :uid
                 ORDER BY m.created_at DESC
                 LIMIT 20";
        $mStmt = $db->prepare($mSql);
        $mStmt->execute([':uid' => $uid]);
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notif_items[] = [
                'type'       => 'message',
                'id'         => $row['id'],
                'title'      => $row['company_name'],
                'body'       => mb_substr($row['message'], 0, 70) . (mb_strlen($row['message']) > 70 ? 'â€¦' : ''),
                'is_read'    => (bool)$row['is_read'],
                'created_at' => $row['created_at'],
                'link'       => appUrl('provider-details.php') . '?id=' . (int)$row['provider_id'],
                'icon'       => 'fa-comment-dots',
                'color'      => '#8e44ad',
            ];
            if (!$row['is_read']) $notif_unread++;
        }
    } catch (Exception $e) {}

    usort($notif_items, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $notif_items = array_slice($notif_items, 0, 25);

    // Mark all as read if requested
    if (isset($_GET['mark_notif_read']) && $_GET['mark_notif_read'] === '1') {
        try { $db->prepare("UPDATE availed_services SET is_read=1 WHERE user_id=:uid AND is_read=0")->execute([':uid' => $uid]); } catch (Exception $e) {}
        try { $db->prepare("UPDATE messages SET is_read=1 WHERE seeker_user_id=:uid AND is_read=0")->execute([':uid' => $uid]); } catch (Exception $e) {}
        header('Location: index.php');
        exit();
    }
}

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Professional Pest Control Services - Pestify</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* â”€â”€ Notification Bell â”€â”€ */
        .notif-bell-wrap {
            display: none; align-items: center; position: relative; margin-right: 10px;
        }
        .notif-bell-btn {
            width: 38px; height: 38px; border-radius: 50%;
            background: #f0f4ff; color: #1a2744; border: 2px solid #d0d8f0;
            cursor: pointer; font-size: 16px; display: flex; align-items: center;
            justify-content: center; transition: all 0.2s; position: relative;
            box-shadow: 0 2px 8px rgba(26,39,68,0.12);
        }
        .notif-bell-btn:hover { background: #1a2744; color: white; border-color: #1a2744; transform: scale(1.08); }
        .notif-bell-btn.has-notif { background: #fff3f3; border-color: #e74c3c; color: #e74c3c; animation: bellShake 2.5s ease infinite; }
        .notif-bell-btn.has-notif:hover { background: #e74c3c; color: white; }
        @keyframes bellShake {
            0%,55%,100% { transform: rotate(0deg); }
            60% { transform: rotate(-12deg); } 65% { transform: rotate(12deg); }
            70% { transform: rotate(-8deg); }  75% { transform: rotate(8deg); }
            80% { transform: rotate(0deg); }
        }
        .notif-badge {
            position: absolute; top: -6px; right: -6px;
            background: #e74c3c; color: white; font-size: 10px; font-weight: 800;
            min-width: 18px; height: 18px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid white; padding: 0 3px; line-height: 1;
            animation: badgePop 0.3s cubic-bezier(0.34,1.56,0.64,1); pointer-events: none;
        }
        @keyframes badgePop { from { transform: scale(0) rotate(-15deg); } to { transform: scale(1) rotate(0deg); } }

        .notif-panel {
            position: absolute; top: calc(100% + 10px); right: -10px;
            width: 380px; max-height: 520px; background: white;
            border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 4px 16px rgba(0,0,0,0.08);
            border: 1px solid #e8edf2; display: none; flex-direction: column;
            overflow: hidden; z-index: 999999;
            animation: panelDrop 0.22s cubic-bezier(0.34,1.2,0.64,1);
        }
        .notif-panel.open { display: flex; }
        @keyframes panelDrop { from { opacity:0; transform:translateY(-12px) scale(0.96); } to { opacity:1; transform:translateY(0) scale(1); } }

        .notif-panel-header {
            padding: 16px 18px 12px;
            background: linear-gradient(135deg, #1a2744 0%, #2d4a8a 100%);
            color: white; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }
        .notif-panel-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; letter-spacing: 0.2px; }
        .notif-new-pill { background: rgba(255,255,255,0.2); color: white; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; }
        .notif-mark-all {
            font-size: 11px; color: rgba(255,255,255,0.85); text-decoration: none;
            background: rgba(255,255,255,0.15); padding: 5px 12px; border-radius: 20px;
            transition: background 0.2s; white-space: nowrap; border: 1px solid rgba(255,255,255,0.2);
        }
        .notif-mark-all:hover { background: rgba(255,255,255,0.28); color: white; }

        .notif-tabs { display: flex; background: #f8fafc; border-bottom: 2px solid #f0f4f8; flex-shrink: 0; padding: 0 6px; gap: 2px; }
        .notif-tab {
            flex: 1; padding: 10px 8px; font-size: 12px; font-weight: 600; color: #94a3b8;
            border: none; background: none; cursor: pointer; border-bottom: 3px solid transparent;
            margin-bottom: -2px; transition: all 0.18s; display: flex; align-items: center;
            justify-content: center; gap: 5px; white-space: nowrap; font-family: inherit; border-radius: 6px 6px 0 0;
        }
        .notif-tab:hover { color: #1a2744; background: #eef2ff; }
        .notif-tab.active { color: #1a2744; border-bottom-color: #3b82f6; background: white; font-weight: 700; }
        .notif-tab-count { background: #e74c3c; color: white; font-size: 10px; font-weight: 800; padding: 1px 5px; border-radius: 10px; line-height: 1.5; min-width: 16px; text-align: center; }

        .notif-list { overflow-y: auto; flex: 1; scrollbar-width: thin; scrollbar-color: #e0e7ff transparent; }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: #e0e7ff; border-radius: 4px; }

        .notif-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #f4f7fb; text-decoration: none; transition: background 0.15s; cursor: pointer; color: inherit; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #f8fbff; }
        .notif-item.unread { background: linear-gradient(90deg, #f0f7ff 0%, #fafcff 100%); border-left: 3px solid #3b82f6; }
        .notif-item.unread:hover { background: #e8f3ff; }
        .notif-item-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-size: 13px; font-weight: 700; color: #1a1f3a; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-item-text { font-size: 12px; color: #64748b; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .notif-item-time { font-size: 11px; color: #b0bec5; margin-top: 5px; display: flex; align-items: center; gap: 4px; }
        .notif-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: #3b82f6; flex-shrink: 0; margin-top: 6px; animation: dotPulse 2s ease infinite; }
        @keyframes dotPulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:0.6; transform:scale(0.8); } }
        .notif-empty { text-align: center; padding: 44px 20px; color: #b0bec5; }
        .notif-empty i { font-size: 40px; display: block; margin-bottom: 12px; opacity: 0.35; }
        .notif-empty p { font-size: 13px; margin: 0; }

        .notif-panel-footer { padding: 12px 18px; border-top: 1px solid #f0f4f8; background: #fafbfc; flex-shrink: 0; }
        .notif-view-all { font-size: 13px; font-weight: 600; color: #3b82f6; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 8px; border-radius: 8px; transition: background 0.15s; }
        .notif-view-all:hover { background: #eff6ff; color: #1d4ed8; }

        /* â”€â”€ Providers Showcase â”€â”€ */
        .providers-showcase { background: #f8fafc; padding: 80px 0; font-family: 'DM Sans', sans-serif; }
        .providers-showcase .section-header { text-align: center; margin-bottom: 48px; }
        .providers-showcase .section-header h2 { font-size: 32px; font-weight: 700; color: #1a1f3a; margin-bottom: 10px; }
        .providers-showcase .section-header p { font-size: 16px; color: #64748b; }

        .providers-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 24px; max-width: 1200px; margin: 0 auto; padding: 0 24px; }

        .provider-card {
            background: #ffffff; border-radius: 16px; padding: 28px 24px;
            border: 1px solid #e8edf2; box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            transition: all 0.3s ease; display: flex; flex-direction: column;
            align-items: center; text-align: center; position: relative; overflow: hidden;
        }
        .provider-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,#3498db,#2d3561); opacity:0; transition:opacity 0.3s; }
        .provider-card:hover { transform: translateY(-6px); box-shadow: 0 16px 40px rgba(0,0,0,0.10); border-color: #c8d9f0; }
        .provider-card:hover::before { opacity: 1; }

        .provider-avatar { width:72px; height:72px; border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:700; color:#2d3561; background:linear-gradient(135deg,#e8f1fb,#d0e4f7); margin-bottom:16px; overflow:hidden; flex-shrink:0; }
        .provider-avatar img { width:100%; height:100%; object-fit:cover; border-radius:16px; }
        .provider-card h3 { font-size:16px; font-weight:700; color:#1a1f3a; margin-bottom:8px; line-height:1.3; }

        .provider-rating { display:flex; align-items:center; justify-content:center; gap:4px; margin-bottom:16px; }
        .provider-rating .stars { color:#f59e0b; font-size:13px; letter-spacing:1px; }
        .provider-rating .rating-val { font-size:13px; font-weight:600; color:#374151; margin-left:4px; }
        .provider-rating .rating-count { font-size:12px; color:#9ca3af; }
        .no-rating-text { font-size:12px; color:#9ca3af; margin-bottom:16px; }
        .provider-divider { width:100%; height:1px; background:#f1f5f9; margin-bottom:16px; }

        /* â”€â”€ Stats row â€” now shows 3 items â”€â”€ */
        .provider-stats {
            display: flex; width: 100%; margin-bottom: 20px;
            background: #f8fafc; border-radius: 10px; overflow: hidden;
            border: 1px solid #e8edf2;
        }
        .provider-stat { flex: 1; padding: 12px 6px; display: flex; flex-direction: column; align-items: center; gap: 2px; }
        .provider-stat + .provider-stat { border-left: 1px solid #e8edf2; }
        .provider-stat-num   { font-size: 17px; font-weight: 700; color: #1a1f3a; line-height: 1; }
        .provider-stat-label { font-size: 10px; font-weight: 500; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.4px; }

        /* Service count badge on card top-right */
        .provider-service-badge {
            position: absolute; top: 14px; right: 14px;
            background: linear-gradient(135deg, #3498db, #2d3561);
            color: white; font-size: 11px; font-weight: 700;
            padding: 4px 10px; border-radius: 20px;
            display: flex; align-items: center; gap: 4px;
            box-shadow: 0 2px 8px rgba(52,152,219,0.3);
        }

        .provider-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 22px; background:linear-gradient(135deg,#3498db,#2980b9); color:white; text-decoration:none; border-radius:8px; font-size:13px; font-weight:600; font-family:'DM Sans',sans-serif; transition:all 0.25s; box-shadow:0 4px 12px rgba(52,152,219,0.25); width:100%; justify-content:center; }
        .provider-btn:hover { background:linear-gradient(135deg,#2980b9,#1a6fa8); transform:translateY(-1px); box-shadow:0 6px 16px rgba(52,152,219,0.35); }

        .providers-footer { text-align:center; margin-top:40px; }
        .btn-view-all { display:inline-flex; align-items:center; gap:8px; padding:12px 28px; background:white; color:#2d3561; text-decoration:none; border-radius:10px; font-size:14px; font-weight:600; font-family:'DM Sans',sans-serif; border:2px solid #e2e8f0; transition:all 0.25s; }
        .btn-view-all:hover { border-color:#3498db; color:#3498db; box-shadow:0 4px 16px rgba(52,152,219,0.15); }

        @media (max-width:768px) {
            .providers-grid { grid-template-columns:1fr 1fr; gap:16px; }
            .providers-showcase { padding:50px 0; }
            .notif-panel { width:300px; right:-60px; }
        }
        @media (max-width:480px) {
            .providers-grid { grid-template-columns:1fr; }
            .notif-panel { width:calc(100vw - 24px); right:-80px; }
        }
    </style>
</head>
<body>
    <?php $current_page = 'home'; include 'includes/header.php'; ?>

    <!-- â”€â”€ Notification Bell â”€â”€ -->
    <?php if (false): // Bell is now in includes/header.php ?>
    <div class="notif-bell-wrap" id="notifBellWrap">
        <button class="notif-bell-btn <?php echo $notif_unread > 0 ? 'has-notif' : ''; ?>"
                id="notifBellBtn" onclick="toggleNotifPanel(event)" title="Notifications &amp; Messages">
            <i class="fas fa-bell"></i>
            <?php if ($notif_unread > 0): ?>
                <span class="notif-badge"><?php echo $notif_unread > 99 ? '99+' : $notif_unread; ?></span>
            <?php endif; ?>
        </button>

        <div class="notif-panel" id="notifPanel">
            <div class="notif-panel-header">
                <span class="notif-panel-title">    
                    <i class="fas fa-bell"></i> Notifications
                    <?php if ($notif_unread > 0): ?><span class="notif-new-pill"><?php echo $notif_unread; ?> new</span><?php endif; ?>
                </span>
                <?php if ($notif_unread > 0): ?>
                    <a href="?mark_notif_read=1" class="notif-mark-all"><i class="fas fa-check-double"></i> Mark all read</a>
                <?php endif; ?>
            </div>

            <div class="notif-tabs">
                <button class="notif-tab active" id="tabAll" onclick="switchTab('all')">
                    <i class="fas fa-layer-group"></i> All
                    <?php if ($notif_unread > 0): ?><span class="notif-tab-count"><?php echo $notif_unread; ?></span><?php endif; ?>
                </button>
                <button class="notif-tab" id="tabMessages" onclick="switchTab('message')">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php $msg_unread = count(array_filter($notif_items, fn($n) => $n['type']==='message' && !$n['is_read']));
                    if ($msg_unread > 0) echo '<span class="notif-tab-count">'.$msg_unread.'</span>'; ?>
                </button>
                <button class="notif-tab" id="tabStatus" onclick="switchTab('status')">
                    <i class="fas fa-clipboard-check"></i> Requests
                    <?php $req_unread = count(array_filter($notif_items, fn($n) => $n['type']==='status' && !$n['is_read']));
                    if ($req_unread > 0) echo '<span class="notif-tab-count">'.$req_unread.'</span>'; ?>
                </button>
            </div>

            <div class="notif-list" id="notifList">
                <?php if (count($notif_items) > 0): ?>
                    <?php foreach ($notif_items as $item):
                        $statusLabels = [
                            'pending'                       => ['label'=>'Pending',              'color'=>'#f59e0b','bg'=>'#fffbeb'],
                            'preparing'                     => ['label'=>'Preparing',             'color'=>'#06b6d4','bg'=>'#ecfeff'],
                            'starting'                      => ['label'=>'Starting',              'color'=>'#3b82f6','bg'=>'#eff6ff'],
                            'ongoing'                       => ['label'=>'Ongoing',               'color'=>'#10b981','bg'=>'#ecfdf5'],
                            'completed'                     => ['label'=>'Completed',             'color'=>'#22c55e','bg'=>'#f0fdf4'],
                            'cancelled'                     => ['label'=>'Cancelled',             'color'=>'#ef4444','bg'=>'#fef2f2'],
                            'waiting_remaining_payment'     => ['label'=>'Awaiting Payment',      'color'=>'#f97316','bg'=>'#fff7ed'],
                            'waiting_seeker_information'    => ['label'=>'Awaiting Your Info',    'color'=>'#8b5cf6','bg'=>'#f5f3ff'],
                            'waiting_provider_confirmation' => ['label'=>'Awaiting Confirmation', 'color'=>'#0ea5e9','bg'=>'#f0f9ff'],
                            'accepted'                      => ['label'=>'Accepted',              'color'=>'#10b981','bg'=>'#ecfdf5'],
                        ];
                        $rawStatus = '';
                        if ($item['type'] === 'status') {
                            preg_match('/Status: ([^â€”]+)/', $item['body'], $m);
                            $rawStatus = isset($m[1]) ? strtolower(trim(str_replace(' ', '_', $m[1]))) : '';
                        }
                        $sl = $statusLabels[$rawStatus] ?? ['label'=>'', 'color'=>$item['color'], 'bg'=>$item['color'].'18'];
                    ?>
                    <a href="<?php echo htmlspecialchars($item['link']); ?>"
                       class="notif-item <?php echo !$item['is_read'] ? 'unread' : ''; ?>"
                       data-type="<?php echo $item['type']; ?>">
                        <div class="notif-item-icon"
                             style="background:<?php echo $item['type']==='status' ? $sl['bg'] : $item['color'].'18'; ?>;color:<?php echo $item['type']==='status' ? $sl['color'] : $item['color']; ?>;">
                            <i class="fas <?php echo $item['icon']; ?>"></i>
                        </div>
                        <div class="notif-item-body">
                            <div class="notif-item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <?php if ($item['type']==='status' && $sl['label']): ?>
                                <div class="notif-item-text">
                                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:<?php echo $sl['color']; ?>;background:<?php echo $sl['bg']; ?>;padding:2px 8px;border-radius:20px;"><?php echo $sl['label']; ?></span>
                                    &nbsp;<span style="font-size:12px;color:#64748b;"><?php $parts=explode('â€”',$item['body']); echo isset($parts[1]) ? htmlspecialchars(trim($parts[1])) : ''; ?></span>
                                </div>
                            <?php else: ?>
                                <div class="notif-item-text"><?php echo htmlspecialchars($item['body']); ?></div>
                            <?php endif; ?>
                            <div class="notif-item-time"><i class="fas fa-clock"></i><?php echo timeAgo($item['created_at']); ?></div>
                        </div>
                        <?php if (!$item['is_read']): ?><div class="notif-unread-dot"></div><?php endif; ?>
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
    <?php endif; ?>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h1>Professional Pest Control Services at Your Fingertips</h1>
                <p>Connect with certified pest control experts in your area. Quick, reliable, and affordable solutions for your home or business.</p>
                <div class="search-box">
                    <form action="<?php echo appUrl('listings.php'); ?>" method="GET" id="searchForm">
                        <div class="search-group">
                            <label for="search"><i class="fas fa-search"></i> What service do you need?</label>
                            <input type="text" name="search" id="search" placeholder="E.g., Termite control, Rodent removal..." class="search-input">
                        </div>
                        <div class="search-group">
                            <label for="location"><i class="fas fa-map-marker-alt"></i> Where?</label>
                            <input type="text" name="location" id="location" placeholder="City or ZIP code" class="location-input">
                        </div>
                        <button type="submit" class="btn-primary search-btn"><i class="fas fa-search"></i> Find Services</button>
                    </form>
                    <p style="margin-top:14px;font-size:14px;">
                        <a href="<?php echo appUrl('recommend.php'); ?>" style="color:var(--primary);font-weight:700;text-decoration:none;">
                            <i class="fas fa-wand-magic-sparkles"></i> Not sure who to pick? Let us match you →
                        </a>
                    </p>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat"><span class="hero-stat-number">500+</span><span class="hero-stat-label">Trusted Providers</span></div>
                    <div class="hero-stat"><span class="hero-stat-number">10,000+</span><span class="hero-stat-label">Happy Customers</span></div>
                    <div class="hero-stat"><span class="hero-stat-number">24/7</span><span class="hero-stat-label">Emergency Service</span></div>
                    <div class="hero-stat"><span class="hero-stat-number">4.8â˜…</span><span class="hero-stat-label">Average Rating</span></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Categories Section -->
    <section class="categories">
        <div class="container">
            <div class="section-header">
                <h2>Popular Pest Control Services</h2>
                <p>Browse our most requested pest control categories</p>
            </div>
            <div class="category-grid">
                <?php foreach($categories as $category):
                    $categoryIcons = ['Rodent Control'=>'ðŸ­','Insect Control'=>'ðŸœ','Mosquito Control'=>'ðŸ¦Ÿ','Bee/Wasp Removal'=>'ðŸ','Spider Control'=>'ðŸ•·ï¸','Cockroach Control'=>'ðŸª³','Termite Control'=>'ðŸ”¨','Bed Bug Treatment'=>'ðŸ›ï¸','Ant Control'=>'ðŸœ','Flea Control'=>'ðŸ¦—'];
                    $icon = $categoryIcons[$category['name']] ?? 'ðŸ›';
                ?>
                    <a href="<?php echo appUrl('listings.php'); ?>?category_id=<?php echo $category['id']; ?>" class="category-card">
                        <span class="category-icon"><?php echo $icon; ?></span>
                        <h3><?php echo htmlspecialchars($category['name']); ?></h3>
                        <p>Professional <?php echo htmlspecialchars($category['name']); ?> control and prevention services</p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Featured Services -->
    <section class="featured-services">
        <div class="container">
            <div class="section-header">
                <h2>Featured Pest Control Services</h2>
                <p>Top-rated services from our trusted providers</p>
            </div>
            <?php if(count($featured_services) > 0): ?>
                <div class="service-grid">
                    <?php foreach($featured_services as $service):
                        $images = $service['images'] ? json_decode($service['images'], true) : [];
                        $firstImage = !empty($images) ? $images[0] : null;
                    ?>
                        <div class="service-card">
                            <?php if($service['is_emergency_available']): ?>
                                <div class="service-badge"><i class="fas fa-bolt"></i> Emergency</div>
                            <?php endif; ?>
                            <div class="service-image">
                                <?php if($firstImage): ?>
                                    <img src="<?php echo htmlspecialchars($firstImage); ?>" alt="<?php echo htmlspecialchars($service['title']); ?>">
                                <?php else: ?>
                                    <div class="no-image"><i class="fas fa-bug"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="service-content">
                                <div class="service-header">
                                    <div>
                                        <h3 class="service-title"><?php echo htmlspecialchars($service['title']); ?></h3>
                                        <div class="company-info">
                                            <?php if($service['logo_url']): ?>
                                                <img src="<?php echo htmlspecialchars($service['logo_url']); ?>" alt="<?php echo htmlspecialchars($service['company_name']); ?>" class="company-logo">
                                            <?php endif; ?>
                                            <span class="company-name"><?php echo htmlspecialchars($service['company_name']); ?></span>
                                        </div>
                                    </div>
                                    <?php if($service['avg_rating']): ?>
                                        <div class="rating">
                                            <i class="fas fa-star"></i>
                                            <span><?php echo number_format($service['avg_rating'], 1); ?></span>
                                            <small>(<?php echo $service['review_count']; ?>)</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <p class="service-description"><?php echo substr(htmlspecialchars($service['description']), 0, 120); ?>...</p>
                                <div class="service-footer">
                                    <div>
                                        <div class="service-price">&#8369;<?php echo number_format($service['price'], 2); ?></div>
                                        <span class="service-price-type"><?php echo ucfirst($service['pricing_type']); ?></span>
                                    </div>
                                    <a href="<?php echo appUrl('listing-details.php'); ?>?id=<?php echo $service['id']; ?>" class="btn-secondary">View <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-4">
                    <a href="<?php echo appUrl('listings.php'); ?>" class="btn-primary">View All Services <i class="fas fa-arrow-right"></i></a>
                </div>
            <?php else: ?>
                <div class="text-center p-4">
                    <p>No featured services available at the moment.</p>
                    <a href="<?php echo appUrl('listings.php'); ?>" class="btn-primary mt-2">Browse All Services</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- How It Works -->
    <section class="how-it-works">
        <div class="container">
            <div class="section-header">
                <h2>How Pestify Works</h2>
                <p>Simple steps to get professional pest control services</p>
            </div>
            <div class="steps-grid">
                <div class="step"><div class="step-number">1</div><h3>Search &amp; Compare</h3><p>Browse verified pest control providers in your area. Compare services, prices, and reviews.</p></div>
                <div class="step"><div class="step-number">2</div><h3>Book Service</h3><p>Select your preferred provider and schedule a service at your convenience.</p></div>
                <div class="step"><div class="step-number">3</div><h3>Get Service</h3><p>Professional technicians arrive at your location with all necessary equipment.</p></div>
                <div class="step"><div class="step-number">4</div><h3>Review &amp; Repeat</h3><p>Share your experience and schedule follow-up services as needed.</p></div>
            </div>
        </div>
    </section>

    <!-- â”€â”€ Trusted Providers â”€â”€ -->
    <section class="providers-showcase">
        <div class="section-header">
            <h2>Trusted Pest Control Companies</h2>
            <p>Partner with the best in the business</p>
        </div>

        <?php if(count($top_providers) > 0): ?>
        <div class="providers-grid">
            <?php foreach($top_providers as $provider):
                $svcCount       = (int)($provider['service_count']   ?? 0);
                $completedCount = (int)($provider['completed_count'] ?? 0);
                $avgRating      = (float)($provider['avg_rating']    ?? 0);
                $revCount       = (int)($provider['review_count']    ?? 0);
            ?>
            <div class="provider-card">

                <!-- Service count badge top-right -->
                <?php if ($svcCount > 0): ?>
                <div class="provider-service-badge">
                    <i class="fas fa-list-ul" style="font-size:9px;"></i>
                    <?php echo $svcCount; ?> service<?php echo $svcCount !== 1 ? 's' : ''; ?>
                </div>
                <?php endif; ?>

                <div class="provider-avatar">
                    <?php if($provider['logo_url']): ?>
                        <img src="<?php echo htmlspecialchars($provider['logo_url']); ?>"
                             alt="<?php echo htmlspecialchars($provider['company_name']); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($provider['company_name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>

                <h3><?php echo htmlspecialchars($provider['company_name']); ?></h3>

                <!-- Rating -->
                <?php if ($avgRating > 0): ?>
                    <div class="provider-rating">
                        <span class="stars">
                            <?php $r = round($avgRating); for ($i=1;$i<=5;$i++) echo $i<=$r?'â˜…':'â˜†'; ?>
                        </span>
                        <span class="rating-val"><?php echo number_format($avgRating, 1); ?></span>
                        <?php if($revCount > 0): ?>
                            <span class="rating-count">(<?php echo $revCount; ?> review<?php echo $revCount!==1?'s':''; ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="no-rating-text">No reviews yet</p>
                <?php endif; ?>

                <div class="provider-divider"></div>

                <!-- Stats: Services | Completed | Radius -->
                <div class="provider-stats">
                    <div class="provider-stat">
                        <span class="provider-stat-num"><?php echo $svcCount; ?></span>
                        <span class="provider-stat-label">Services</span>
                    </div>
                    <div class="provider-stat">
                        <span class="provider-stat-num"><?php echo $completedCount; ?></span>
                        <span class="provider-stat-label">Completed</span>
                    </div>
                    <div class="provider-stat">
                        <span class="provider-stat-num"><?php echo $provider['service_radius'] ?? 'â€”'; ?>km</span>
                        <span class="provider-stat-label">Radius</span>
                    </div>
                </div>

                <a href="<?php echo appUrl('provider-details.php'); ?>?id=<?php echo $provider['id']; ?>" class="provider-btn">
                    View Profile <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="providers-footer">
            <a href="<?php echo appUrl('providers.php'); ?>" class="btn-view-all">
                <i class="fas fa-building"></i> View All Companies
            </a>
        </div>
        <?php endif; ?>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <div class="cta-content">
                <h2>Ready to Solve Your Pest Problems?</h2>
                <p>Join thousands of satisfied customers who trust Pestify for their pest control needs.</p>
                <div class="cta-buttons">
                    <?php if(!isset($_SESSION['user_id'])): ?>
                        <a href="<?php echo appUrl('register.php?type=seeker'); ?>" class="btn-light"><i class="fas fa-home"></i> Find Services</a>
                        <a href="<?php echo appUrl('register.php?type=provider'); ?>" class="btn-outline" style="border-color:rgba(255,255,255,0.5);color:white;"><i class="fas fa-building"></i> List Your Business</a>
                    <?php else: ?>
                        <a href="<?php echo appUrl('listings.php'); ?>" class="btn-light"><i class="fas fa-search"></i> Browse Services</a>
                        <?php if(isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'provider'): ?>
                            <a href="<?php echo appUrl('create-listing.php'); ?>" class="btn-outline" style="border-color:rgba(255,255,255,0.5);color:white;"><i class="fas fa-plus"></i> Add New Service</a>
                        <?php else: ?>
                            <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn-outline" style="border-color:rgba(255,255,255,0.5);color:white;"><i class="fas fa-list"></i> View My Requests</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>

    <script>
        const notifPanel = document.getElementById('notifPanel');
        const notifBtn   = document.getElementById('notifBellBtn');
        const notifWrap  = document.getElementById('notifBellWrap');

        function injectBellIntoNavbar() {
            if (!notifWrap) return;
            const targets = [
                '.user-dropdown','.user-menu','.nav-user','.header-user','.navbar-user',
                '.user-info','.user-avatar','.nav-profile','.user-section','.account-menu',
                '.profile-menu','[class*="user-drop"]','[class*="user-menu"]','[class*="user-info"]',
                '[class*="profile"]','[class*="account"]','.btn-user','.header-btn',
            ];
            for (const sel of targets) {
                const el = document.querySelector(sel);
                if (el && el.parentNode) {
                    el.parentNode.insertBefore(notifWrap, el);
                    notifWrap.style.display = 'inline-flex';
                    return;
                }
            }
            const allNavLis = document.querySelectorAll('header ul li, nav ul li, .nav-items li, .navbar-nav li, .nav-links li, .header-nav li');
            if (allNavLis.length) {
                const last = allNavLis[allNavLis.length - 1];
                last.parentNode.insertBefore(notifWrap, last);
                notifWrap.style.display = 'inline-flex';
                return;
            }
            const headerContainers = document.querySelectorAll('header .container, header .wrapper, header nav, header');
            for (const c of headerContainers) {
                if (c.children.length) { c.appendChild(notifWrap); notifWrap.style.display = 'inline-flex'; return; }
            }
        }

        // Bell injection removed — bell now lives in includes/header.php

        function toggleNotifPanel(e) { e.stopPropagation(); notifPanel.classList.toggle('open'); }
        document.addEventListener('click', function(e) { if (notifWrap && !notifWrap.contains(e.target)) notifPanel && notifPanel.classList.remove('open'); });

        let activeTab = 'all';
        function switchTab(type) {
            activeTab = type;
            document.querySelectorAll('.notif-tab').forEach(t => t.classList.remove('active'));
            const tabMap = { all:'tabAll', message:'tabMessages', status:'tabStatus' };
            document.getElementById(tabMap[type]).classList.add('active');
            document.querySelectorAll('.notif-item').forEach(item => {
                item.style.display = (type === 'all' || item.dataset.type === type) ? 'flex' : 'none';
            });
            const list = document.getElementById('notifList');
            let anyVisible = false;
            list.querySelectorAll('.notif-item').forEach(el => { if (el.style.display !== 'none') anyVisible = true; });
            const emptyEl = list.querySelector('.notif-empty');
            if (emptyEl) emptyEl.style.display = anyVisible ? 'none' : 'block';
        }

        const searchForm = document.getElementById('searchForm');
        if (searchForm) {
            searchForm.addEventListener('submit', function(e) {
                const s = document.getElementById('search');
                const l = document.getElementById('location');
                if (!s.value.trim() && !l.value.trim()) {
                    e.preventDefault();
                    s.style.borderColor = 'var(--danger)';
                    l.style.borderColor = 'var(--danger)';
                    s.focus();
                    setTimeout(() => { s.style.borderColor = ''; l.style.borderColor = ''; }, 3000);
                }
            });
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) { entry.target.style.opacity='1'; entry.target.style.transform='translateY(0)'; }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        document.querySelectorAll('.service-card, .step, .provider-card').forEach(el => {
            el.style.opacity='0'; el.style.transform='translateY(20px)'; el.style.transition='all 0.6s ease';
            observer.observe(el);
        });

        <?php if (isset($_SESSION['user_id'])): ?>
        setInterval(function() {
            fetch('get-notif-count.php')
                .then(r => r.json())
                .then(data => {
                    const badge = document.querySelector('.notif-badge');
                    const btn   = document.getElementById('notifBellBtn');
                    if (data.count > 0) {
                        if (badge) { badge.textContent = data.count > 99 ? '99+' : data.count; }
                        else { const b = document.createElement('span'); b.className='notif-badge'; b.textContent=data.count; btn.appendChild(b); }
                        btn.classList.add('has-notif');
                    } else {
                        if (badge) badge.remove();
                        btn.classList.remove('has-notif');
                    }
                }).catch(()=>{});
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>
