<?php
chdir(dirname(__DIR__));
// providers.php - Browse all providers
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$city   = isset($_GET['city'])   ? trim($_GET['city'])   : '';
$sort   = isset($_GET['sort'])   ? trim($_GET['sort'])   : 'rating';
$page   = isset($_GET['page'])   ? max(1, intval($_GET['page'])) : 1;
$limit  = 12;
$offset = ($page - 1) * $limit;

// -- Ensure service_reviews table exists before querying it --
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

// -- Main query � pulls avg + count from service_reviews --
$query = "SELECT p.*, u.email, u.profile_image,
          (SELECT ROUND(AVG(r.rating), 1) FROM service_reviews r WHERE r.provider_id = p.id) AS avg_rating,
          (SELECT COUNT(*)               FROM service_reviews r WHERE r.provider_id = p.id) AS review_count,
          (SELECT COUNT(*) FROM services s WHERE s.provider_id = p.id AND s.status = 'active') AS service_count
          FROM providers p
          JOIN users u ON p.user_id = u.id
          WHERE p.status = 'active'";

$count_query = "SELECT COUNT(DISTINCT p.id) AS total
                FROM providers p
                JOIN users u ON p.user_id = u.id
                WHERE p.status = 'active'";

$params = [];

if (!empty($search)) {
    $query       .= " AND (p.company_name LIKE :search OR p.description LIKE :search OR u.email LIKE :search)";
    $count_query .= " AND (p.company_name LIKE :search OR p.description LIKE :search OR u.email LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($city)) {
    $query       .= " AND p.city LIKE :city";
    $count_query .= " AND p.city LIKE :city";
    $params[':city'] = '%' . $city . '%';
}

$query .= ($sort === 'name') ? " ORDER BY p.company_name ASC" : " ORDER BY avg_rating DESC, service_count DESC";
$query .= " LIMIT :limit OFFSET :offset";

// Total count
$stmt = $db->prepare($count_query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$total_providers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages     = ceil($total_providers / $limit);

// Providers
$stmt = $db->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindParam(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -- Per-star breakdown for every provider on this page --
// Returns [provider_id => [1=>count, 2=>count, 3=>count, 4=>count, 5=>count]]
$ratingBreakdown = [];
if (count($providers) > 0) {
    $providerIds   = array_column($providers, 'id');
    $placeholders  = implode(',', array_fill(0, count($providerIds), '?'));
    try {
        $brkStmt = $db->prepare(
            "SELECT provider_id, rating, COUNT(*) AS cnt
             FROM service_reviews
             WHERE provider_id IN ($placeholders)
             GROUP BY provider_id, rating"
        );
        $brkStmt->execute($providerIds);
        foreach ($brkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = $row['provider_id'];
            if (!isset($ratingBreakdown[$pid])) {
                $ratingBreakdown[$pid] = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
            }
            $ratingBreakdown[$pid][(int)$row['rating']] = (int)$row['cnt'];
        }
    } catch (Exception $e) {}
}

// -- Recent reviews (up to 2 per provider) for the quote snippets --
$recentReviews = [];
if (count($providers) > 0) {
    $providerIds  = array_column($providers, 'id');
    $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
    try {
        $rvStmt = $db->prepare(
            "SELECT r.provider_id, r.rating, r.feedback, r.created_at,
                    u.first_name, u.last_name
             FROM service_reviews r
             LEFT JOIN users u ON u.id = r.seeker_user_id
             WHERE r.provider_id IN ($placeholders)
               AND r.feedback IS NOT NULL AND r.feedback != ''
             ORDER BY r.created_at DESC"
        );
        $rvStmt->execute($providerIds);
        foreach ($rvStmt->fetchAll(PDO::FETCH_ASSOC) as $rv) {
            $pid = $rv['provider_id'];
            if (!isset($recentReviews[$pid])) $recentReviews[$pid] = [];
            if (count($recentReviews[$pid]) < 2) $recentReviews[$pid][] = $rv;
        }
    } catch (Exception $e) {}
}

// -- A few real service names per provider, for the "Services offered" chips --
$providerServices = [];
$providerServiceTotals = [];
if (count($providers) > 0) {
    $providerIds  = array_column($providers, 'id');
    $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
    try {
        $svcStmt = $db->prepare(
            "SELECT id, provider_id, service_name, price
             FROM services
             WHERE provider_id IN ($placeholders) AND status = 'active'
             ORDER BY created_at DESC"
        );
        $svcStmt->execute($providerIds);
        foreach ($svcStmt->fetchAll(PDO::FETCH_ASSOC) as $svc) {
            $pid = $svc['provider_id'];
            if (!isset($providerServices[$pid])) $providerServices[$pid] = [];
            $providerServiceTotals[$pid] = ($providerServiceTotals[$pid] ?? 0) + 1;
            // provider-details.php's own #service-card-<id> elements exist
            // for every row here (single catalog), so the card can always
            // deep-link straight to it via ?highlight=<id> (same mechanism
            // the CRM outreach emails use).
            if (count($providerServices[$pid]) < 3) {
                $providerServices[$pid][] = ['id' => (int)$svc['id'], 'name' => $svc['service_name'], 'price' => (float)$svc['price'], 'source' => 'web'];
            }
        }
    } catch (Exception $e) {}
}

// Cities for filter
$city_stmt = $db->prepare("SELECT DISTINCT city FROM providers WHERE status = 'active' AND city IS NOT NULL AND city != '' ORDER BY city");
$city_stmt->execute();
$cities = $city_stmt->fetchAll(PDO::FETCH_ASSOC);

// -- Label for each star level --
$ratingLabels = [5 => 'Excellent', 4 => 'Very Good', 3 => 'Good', 2 => 'Fair', 1 => 'Poor'];
$ratingColors = [5 => '#28a745', 4 => '#5cb85c', 3 => '#ffc107', 2 => '#fd7e14', 1 => '#dc3545'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Pest Control Companies - Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= appUrl('assets/css/seeker-unified.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /*
          Card component uses a "prv-" prefixed namespace on purpose.
          A previous pass on browse/listings.php found that reusing generic
          names like .service-header/.provider-card collides with
          assets/css/style.css (loaded earlier on this page for unrelated
          reasons), which defines its OWN .provider-card with
          text-align:center and padding for a completely different card —
          that leaked in silently. These prv- classes exist nowhere else in
          the codebase, so there is no shared stylesheet that can collide
          with them. Colors still come from the shared --su-* tokens (set on
          body.seeker-unified) so this still matches the site's palette.
        */
        .providers-browse-page { min-height:100vh; padding:40px 20px 60px; }
        .providers-container   { max-width:1200px; margin:0 auto; }

        .page-header    { text-align:center; margin-bottom:36px; }
        .page-header h1 { font-size:34px; font-weight:800; letter-spacing:-0.5px; margin-bottom:8px; }
        .page-header p  { font-size:15px; color:var(--su-muted,#666); margin-bottom:0; }

        .filters-section { padding:22px 24px; margin-bottom:28px; }
        .filter-title {
            font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
            color:var(--su-muted,#666); margin-bottom:16px; display:flex; align-items:center; gap:8px;
        }
        .filter-title i { color:var(--su-primary,var(--primary)); }
        .filter-group  { display:grid; grid-template-columns:1.4fr 1fr 1fr; gap:16px; margin-bottom:18px; }
        .filter-item   { display:flex; flex-direction:column; }
        .filter-item label {
            font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
            color:var(--su-muted,#666); margin-bottom:7px; display:flex; align-items:center; gap:6px;
        }
        .filter-item label i { font-size:11px; opacity:.75; }
        .filter-item input, .filter-item select { padding:11px 13px; font-size:14px; font-family:inherit; }
        .filter-buttons { display:flex; gap:10px; padding-top:16px; border-top:1px solid var(--su-border,#eee); }
        .filter-buttons button, .filter-buttons a {
            padding:11px 22px; border:none; border-radius:10px; font-weight:700; font-size:14px;
            cursor:pointer; transition:all .2s; display:inline-flex; align-items:center;
            justify-content:center; gap:8px; text-decoration:none;
        }
        .btn-search { flex:2; }
        .btn-reset  { flex:1; }

        .results-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding:0 4px; }
        .results-count  { font-size:14px; }
        .results-count strong { color:var(--su-text,var(--dark-color)); font-size:16px; }

        .prv-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:24px; margin-bottom:40px; }

        /* -- Provider Card -- */
        .prv-card {
            display:flex; flex-direction:column;
            background:var(--su-surface,#fff); border:1px solid var(--su-border,#e2e8f0);
            border-radius:var(--su-radius,14px); box-shadow:var(--su-shadow,0 8px 22px rgba(18,33,58,.08));
            overflow:hidden; transition:transform .2s ease, box-shadow .2s ease;
        }
        .prv-card:hover { transform:translateY(-3px); box-shadow:var(--su-shadow-hover,0 14px 32px rgba(18,33,58,.14)); }

        .prv-banner { height:56px; background:var(--su-gradient,linear-gradient(135deg,#2b6cb0,#3b82f6)); flex-shrink:0; }

        .prv-avatar-row { display:flex; justify-content:center; margin-top:-36px; margin-bottom:10px; }
        .prv-avatar {
            width:72px; height:72px; border-radius:50%; background:#fff; border:3px solid #fff;
            box-shadow:0 2px 8px rgba(0,0,0,.12); display:flex; align-items:center; justify-content:center;
            font-weight:800; font-size:24px; color:var(--su-primary,var(--primary)); overflow:hidden; flex-shrink:0;
        }
        .prv-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; }

        .prv-name { font-size:18px; font-weight:700; text-align:center; color:var(--su-text,#1a2744); padding:0 16px; margin-bottom:4px; }
        .prv-sub  { font-size:12.5px; color:var(--su-muted,#888); text-align:center; margin-bottom:18px; }

        .prv-body { padding:0 20px 20px; flex-grow:1; display:flex; flex-direction:column; }

        /* -- Compact star row -- */
        .prv-rating-row { display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:14px; }
        .prv-stars i { color:#f39c12; font-size:13px; }
        .prv-rating-avg  { font-size:18px; font-weight:800; color:var(--su-text,#1a1a2e); }
        .prv-rating-meta { font-size:12px; color:var(--su-muted,#999); }

        /* -- Overall satisfaction badge -- */
        .prv-satisfaction-wrap { text-align:center; margin-bottom:14px; }
        .prv-satisfaction-badge {
            display:inline-flex; align-items:center; gap:5px; padding:4px 10px;
            border-radius:99px; font-size:11px; font-weight:700;
        }

        /* -- Rating breakdown panel -- */
        /* Caps the breakdown+reviews block so cards with lots of feedback don't
           grow much taller than cards with none — keeps the grid even. */
        .prv-ratings-scroll { max-height:280px; overflow-y:auto; margin-bottom:14px; padding-right:4px; }
        .prv-ratings-scroll::-webkit-scrollbar { width:6px; }
        .prv-ratings-scroll::-webkit-scrollbar-thumb { background:#d1dbd5; border-radius:99px; }
        .prv-ratings-scroll::-webkit-scrollbar-track { background:transparent; }
        .prv-breakdown { background:var(--su-surface-soft,#fafbfc); border:1px solid var(--su-border,#eee); border-radius:10px; padding:14px 16px; margin-bottom:16px; }
        .prv-breakdown-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--su-muted,#888); margin-bottom:10px; }
        .prv-breakdown-row { display:flex; align-items:center; gap:8px; margin-bottom:7px; font-size:12px; }
        .prv-breakdown-row:last-child { margin-bottom:0; }
        .prv-breakdown-label { width:100px; min-width:100px; font-weight:600; color:#555; white-space:nowrap; display:flex; align-items:center; gap:4px; }
        .prv-breakdown-label .star-num  { font-weight:700; color:var(--su-text,#1a1a2e); font-size:12px; }
        .prv-breakdown-label .star-word { font-size:11px; }
        .prv-breakdown-bar-wrap { flex:1; background:#e9ecef; border-radius:99px; height:8px; overflow:hidden; }
        .prv-breakdown-bar { height:100%; border-radius:99px; }
        .prv-breakdown-pct   { width:34px; text-align:right; color:var(--su-muted,#666); font-size:11px; font-weight:600; }
        .prv-breakdown-count { width:26px; text-align:right; color:#bbb; font-size:11px; }

        /* -- Services offered: small clickable cards nested inside the
              provider card, horizontal scroll, not wrapped, so the card
              height stays predictable regardless of how many services a
              provider has. Ends with a "View All" mini-card. -- */
        .prv-services-wrap { margin-bottom:14px; }
        .prv-services-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--su-muted,#aaa); margin-bottom:8px; }
        .prv-service-chips { display:flex; flex-wrap:nowrap; overflow-x:auto; gap:8px; padding-bottom:6px; }
        .prv-service-chips::-webkit-scrollbar { height:5px; }
        .prv-service-chips::-webkit-scrollbar-thumb { background:#d1dbd5; border-radius:99px; }
        .prv-service-chips::-webkit-scrollbar-track { background:transparent; }
        .prv-service-chip {
            display:block;
            width:132px; flex-shrink:0;
            padding:9px 11px;
            border-radius:10px;
            background:#eaf2ff; border:1px solid #d6e6ff;
            text-decoration:none;
            transition:background .15s, transform .15s;
        }
        .prv-service-chip:hover { background:#dcebff; transform:translateY(-2px); }
        .prv-service-chip-name {
            font-size:11.5px; font-weight:700; color:var(--su-primary-dark,#245a96);
            line-height:1.3; margin-bottom:4px;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
        }
        .prv-service-chip-price { font-size:11px; font-weight:600; color:var(--su-primary,var(--primary)); }
        .prv-service-chip-cta { font-size:9.5px; font-weight:700; color:var(--su-muted,#7a93b8); text-transform:uppercase; letter-spacing:.3px; margin-top:3px; display:flex; align-items:center; gap:3px; }
        .prv-service-chip-more {
            display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px;
            width:90px; flex-shrink:0;
            padding:9px 8px;
            border-radius:10px;
            background:var(--su-primary,var(--primary)); color:#fff; border:1px solid transparent;
            text-decoration:none; text-align:center;
            font-size:11px; font-weight:700; line-height:1.3;
            transition:background .15s, transform .15s;
        }
        .prv-service-chip-more:hover { background:var(--su-primary-dark,#245a96); transform:translateY(-2px); }
        .prv-service-chip-more i { font-size:14px; }

        /* -- Recent review snippets -- */
        .prv-reviews-wrap { margin-bottom:14px; }
        .prv-review-snippet { background:#f8f9fa; border-left:3px solid #dee2e6; border-radius:0 6px 6px 0; padding:8px 12px; margin-bottom:6px; font-size:12px; }
        .prv-review-snippet:last-child { margin-bottom:0; }
        .prv-review-stars i { color:#f39c12; font-size:10px; }
        .prv-review-text    { color:#555; line-height:1.5; margin:3px 0; font-style:italic; }
        .prv-review-author  { color:#aaa; font-size:11px; }

        .prv-details { font-size:13px; color:var(--su-muted,#666); margin-bottom:14px; line-height:1.6; }
        .prv-detail-item { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
        .prv-detail-item i { color:var(--su-primary,var(--primary)); width:16px; }

        .prv-about-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--su-muted,#aaa); margin-bottom:4px; }
        .prv-description { font-size:13px; color:var(--su-muted,#777); line-height:1.5; margin-bottom:14px; flex-grow:1; }

        .prv-actions { display:flex; gap:10px; margin-top:auto; }
        .prv-actions a { flex:1; padding:11px; text-align:center; text-decoration:none; font-size:13px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; gap:6px; }

        .prv-no-ratings { font-size:12px; color:#bbb; text-align:center; padding:10px 0; }

        .empty-state { text-align:center; padding:60px 20px; }
        .empty-state i { font-size:56px; color:#c3d3ea; margin-bottom:18px; }
        .empty-state h2 { font-size:22px; font-weight:700; margin-bottom:8px; }
        .empty-state p  { color:var(--su-muted,#666); margin-bottom:18px; }

        .pagination { display:flex; justify-content:center; gap:8px; margin-top:36px; flex-wrap:wrap; }
        .pagination a, .pagination span { padding:9px 15px; font-size:13.5px; font-weight:600; text-decoration:none; }

        @media (max-width:900px) {
            .filter-group { grid-template-columns:1fr 1fr; }
        }
        @media (max-width:600px) {
            .providers-browse-page { padding:24px 14px 40px; }
            .page-header h1     { font-size:26px; }
            .filter-group       { grid-template-columns:1fr; }
            .filter-buttons     { flex-direction:column; }
            .prv-grid           { grid-template-columns:1fr; }
            .results-header     { flex-direction:column; gap:10px; align-items:flex-start; }
        }
    </style>
</head>
<body class="seeker-unified">
    <?php
chdir(dirname(__DIR__));
    $current_page = 'providers';
    $use_seeker_unified_ui = true;
    include appPath('includes/header.php');
    ?>

    <div class="providers-browse-page">
        <div class="providers-container">

            <div class="page-header">
                <h1>Browse Pest Control Companies</h1>
                <p>Find trusted and verified pest control professionals in your area</p>
            </div>

            <!-- -- Filters -- -->
            <div class="filters-section">
                <h3 class="filter-title"><i class="fas fa-sliders"></i> Search &amp; Filter</h3>
                <form method="GET" action="<?php echo appUrl('providers.php'); ?>" id="filterForm">
                    <div class="filter-group">
                        <div class="filter-item">
                            <label for="search"><i class="fas fa-magnifying-glass"></i> Search Company Name</label>
                            <input type="text" id="search" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Enter company name...">
                        </div>
                        <div class="filter-item">
                            <label for="city"><i class="fas fa-location-dot"></i> City/Location</label>
                            <select id="city" name="city">
                                <option value="">All Cities</option>
                                <?php foreach($cities as $ct): ?>
                                    <option value="<?php echo htmlspecialchars($ct['city']); ?>"
                                            <?php echo ($city === $ct['city']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ct['city']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="sort"><i class="fas fa-arrow-down-wide-short"></i> Sort By</label>
                            <select id="sort" name="sort">
                                <option value="rating" <?php echo ($sort==='rating')?'selected':''; ?>>Highest Rating</option>
                                <option value="name"   <?php echo ($sort==='name')  ?'selected':''; ?>>Company Name</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                        <a href="<?php echo appUrl('providers.php'); ?>" class="btn-reset">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- -- Results header -- -->
            <div class="results-header">
                <div class="results-count">
                    <?php if($total_providers > 0): ?>
                        <strong><?php echo $total_providers; ?></strong>
                        <?php echo ($total_providers === 1) ? 'company' : 'companies'; ?> found
                    <?php endif; ?>
                </div>
            </div>

            <!-- -- Providers Grid -- -->
            <?php if(count($providers) > 0): ?>
            <div class="prv-grid">
                <?php foreach($providers as $provider):
                    $pid        = $provider['id'];
                    $avgRating  = (float)($provider['avg_rating'] ?? 0);
                    $revCount   = (int)($provider['review_count'] ?? 0);
                    $breakdown  = $ratingBreakdown[$pid] ?? [1=>0,2=>0,3=>0,4=>0,5=>0];
                    $snippets   = $recentReviews[$pid] ?? [];
                    $svcItems   = $providerServices[$pid] ?? [];
                    $svcTotal   = $providerServiceTotals[$pid] ?? count($svcItems);

                    // Overall satisfaction % = (4* + 5*) / total * 100
                    $satisfiedCount = ($breakdown[4] ?? 0) + ($breakdown[5] ?? 0);
                    $satisfactionPct = $revCount > 0 ? round($satisfiedCount / $revCount * 100) : 0;

                    // Satisfaction label + color
                    if ($satisfactionPct >= 90)     { $satLabel = 'Excellent'; $satColor = '#28a745'; $satBg = '#d4edda'; }
                    elseif ($satisfactionPct >= 75) { $satLabel = 'Very Good'; $satColor = '#20c997'; $satBg = '#d1f7ec'; }
                    elseif ($satisfactionPct >= 60) { $satLabel = 'Good';      $satColor = '#ffc107'; $satBg = '#fff3cd'; }
                    elseif ($satisfactionPct >= 40) { $satLabel = 'Fair';      $satColor = '#fd7e14'; $satBg = '#fde8d8'; }
                    elseif ($revCount > 0)           { $satLabel = 'Poor';      $satColor = '#dc3545'; $satBg = '#f8d7da'; }
                    else                             { $satLabel = '';          $satColor = '';        $satBg = ''; }

                    // Avatar: prefer the account's own uploaded profile photo, then the
                    // pasted company logo URL, then initials. profile_image is stored as
                    // a path relative to the app root (e.g. "uploads/profile/xxx.jpg") —
                    // resolved to an absolute URL here since this page lives in seeker/,
                    // one directory below the app root (see CLAUDE.md's "Recent Work
                    // Log" for the broken-image bug this exact pattern caused before).
                    // logo_url is already a full external URL a provider pastes in.
                    $avatarUrl = '';
                    if (!empty($provider['profile_image'])) {
                        $avatarUrl = siteUrl($provider['profile_image']);
                    } elseif (!empty($provider['logo_url'])) {
                        $avatarUrl = $provider['logo_url'];
                    }
                ?>
                <div class="prv-card">
                    <div class="prv-banner"></div>
                    <div class="prv-avatar-row">
                        <div class="prv-avatar">
                            <?php if ($avatarUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="">
                            <?php else: ?>
                                <?php echo strtoupper(substr($provider['company_name'], 0, 2)); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <h3 class="prv-name"><?php echo htmlspecialchars($provider['company_name']); ?></h3>
                    <div class="prv-sub">
                        <?php echo $provider['service_count'] ?? 0; ?> active services &middot;
                        <?php echo htmlspecialchars($provider['city'] ?? 'Cavite'); ?>
                    </div>

                    <div class="prv-body">

                        <?php if ($revCount > 0): ?>
                            <!-- -- Star summary row -- -->
                            <div class="prv-rating-row">
                                <div class="prv-stars">
                                    <?php for ($s = 1; $s <= 5; $s++):
                                        if ($s <= floor($avgRating))      echo '<i class="fas fa-star"></i>';
                                        elseif ($s - 0.5 <= $avgRating)   echo '<i class="fas fa-star-half-alt"></i>';
                                        else                               echo '<i class="far fa-star"></i>';
                                    endfor; ?>
                                </div>
                                <span class="prv-rating-avg"><?php echo number_format($avgRating, 1); ?></span>
                                <span class="prv-rating-meta"><?php echo $revCount; ?> review<?php echo $revCount !== 1 ? 's' : ''; ?></span>
                            </div>

                            <!-- -- Satisfaction badge -- -->
                            <?php if ($satLabel): ?>
                            <div class="prv-satisfaction-wrap">
                                <span class="prv-satisfaction-badge"
                                      style="background:<?php echo $satBg; ?>;color:<?php echo $satColor; ?>;">
                                    <i class="fas fa-thumbs-up"></i>
                                    <?php echo $satisfactionPct; ?>% <?php echo $satLabel; ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <!-- -- Rating breakdown + recent reviews, capped and scrollable so a
                                 provider with lots of reviews doesn't stretch the whole card. -->
                            <div class="prv-ratings-scroll">
                                <div class="prv-breakdown">
                                    <div class="prv-breakdown-title">Rating Breakdown</div>
                                    <?php foreach ([5,4,3,2,1] as $star):
                                        $cnt = $breakdown[$star] ?? 0;
                                        $pct = $revCount > 0 ? round($cnt / $revCount * 100) : 0;
                                        $color = $ratingColors[$star];
                                        $label = $ratingLabels[$star];
                                    ?>
                                    <div class="prv-breakdown-row">
                                        <div class="prv-breakdown-label">
                                            <i class="fas fa-star" style="color:#f39c12;font-size:10px;flex-shrink:0;"></i>
                                            <span class="star-num"><?php echo $star; ?></span>
                                            <span class="star-word" style="color:<?php echo $color; ?>;"><?php echo $label; ?></span>
                                        </div>
                                        <div class="prv-breakdown-bar-wrap">
                                            <div class="prv-breakdown-bar"
                                                 style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div>
                                        </div>
                                        <div class="prv-breakdown-pct"><?php echo $pct; ?>%</div>
                                        <div class="prv-breakdown-count">(<?php echo $cnt; ?>)</div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- -- Recent review snippets -- -->
                                <?php if (count($snippets) > 0): ?>
                                <div class="prv-reviews-wrap">
                                    <div class="prv-breakdown-title" style="margin-bottom:8px;">
                                        <i class="fas fa-quote-left" style="margin-right:4px;"></i>Recent Reviews
                                    </div>
                                    <?php foreach ($snippets as $snip):
                                        $snipName = trim(($snip['first_name'] ?? '') . ' ' . ($snip['last_name'] ?? ''));
                                        if (!$snipName) $snipName = 'Anonymous';
                                        $snipText = htmlspecialchars(mb_substr($snip['feedback'], 0, 90));
                                        if (mb_strlen($snip['feedback']) > 90) $snipText .= '…';
                                    ?>
                                    <div class="prv-review-snippet">
                                        <div class="prv-review-stars">
                                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                                <i class="<?php echo $s <= $snip['rating'] ? 'fas' : 'far'; ?> fa-star"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="prv-review-text">"<?php echo $snipText; ?>"</div>
                                        <div class="prv-review-author">
                                            &mdash; <?php echo htmlspecialchars($snipName); ?> &middot;
                                            <?php echo date('M j, Y', strtotime($snip['created_at'])); ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                        <?php else: ?>
                            <!-- No reviews yet -->
                            <div class="prv-no-ratings">
                                <i class="far fa-star" style="font-size:22px;display:block;margin-bottom:4px;color:#ddd;"></i>
                                No ratings yet
                            </div>
                        <?php endif; ?>

                        <!-- -- Services offered: small clickable cards, first 3, horizontally
                             scrollable, "View All" mini-card at the end. Web-catalog services
                             deep-link to provider-details.php's ?highlight=<id> (the same
                             scroll-to-and-highlight mechanism the CRM outreach emails already
                             use); mobile-only fallback services just link to the profile page
                             since that page has no matching card to highlight. -- -->
                        <?php if (count($svcItems) > 0): ?>
                        <div class="prv-services-wrap">
                            <div class="prv-services-title"><i class="fas fa-box-open"></i> Services Offered</div>
                            <div class="prv-service-chips">
                                <?php foreach ($svcItems as $svc):
                                    $svcUrl = appUrl('provider-details.php') . '?id=' . (int)$provider['id']
                                            . ($svc['source'] === 'web' ? '&highlight=' . (int)$svc['id'] : '');
                                ?>
                                    <a href="<?php echo $svcUrl; ?>" class="prv-service-chip">
                                        <div class="prv-service-chip-name"><?php echo htmlspecialchars($svc['name']); ?></div>
                                        <?php if ($svc['price'] > 0): ?>
                                        <div class="prv-service-chip-price">&#8369;<?php echo number_format($svc['price'], 0); ?></div>
                                        <?php endif; ?>
                                        <div class="prv-service-chip-cta"><i class="fas fa-arrow-right"></i> View Service</div>
                                    </a>
                                <?php endforeach; ?>
                                <?php if ($svcTotal > count($svcItems)): ?>
                                    <a href="<?php echo appUrl('provider-details.php'); ?>?id=<?php echo $provider['id']; ?>" class="prv-service-chip-more">
                                        <i class="fas fa-layer-group"></i> View All<br>(<?php echo $svcTotal; ?>)
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- -- Provider details -- -->
                        <div class="prv-details">
                            <div class="prv-detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($provider['city'] ?? 'Location not specified'); ?></span>
                            </div>
                            <div class="prv-detail-item">
                                <i class="fas fa-list"></i>
                                <span><?php echo $provider['service_count'] ?? 0; ?> Active Services</span>
                            </div>
                            <div class="prv-detail-item">
                                <i class="fas fa-check-circle"></i>
                                <span>Verified Service Provider</span>
                            </div>
                        </div>

                        <!-- Description -->
                        <?php if (!empty($provider['description'])): ?>
                        <div class="prv-about-label">About</div>
                        <p class="prv-description">
                            <?php echo htmlspecialchars(substr($provider['description'], 0, 100))
                                     . (strlen($provider['description']) > 100 ? '...' : ''); ?>
                        </p>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="prv-actions">
                            <a href="<?php echo appUrl('provider-details.php'); ?>?id=<?php echo $provider['id']; ?>" class="btn-view">
                                <i class="fas fa-eye"></i> View Profile
                            </a>
                        </div>

                    </div><!-- /prv-body -->
                </div><!-- /prv-card -->
                <?php endforeach; ?>
            </div><!-- /prv-grid -->

            <!-- -- Pagination -- -->
            <?php if($total_pages > 1):
                $qs = (!empty($search) ? '&search='.urlencode($search) : '')
                    . (!empty($city)   ? '&city='.urlencode($city)     : '')
                    . '&sort='.$sort;
            ?>
            <div class="pagination">
                <?php if($page > 1): ?>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=1<?php echo $qs; ?>">&laquo; First</a>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $page-1; ?><?php echo $qs; ?>">Previous</a>
                <?php endif; ?>
                <?php for($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                    <?php if($i===$page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $i; ?><?php echo $qs; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if($page < $total_pages): ?>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $page+1;?><?php echo $qs;?>">Next</a>
                    <a href="<?php echo appUrl('providers.php'); ?>?page=<?php echo $total_pages;?><?php echo $qs;?>">Last &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h2>No companies found</h2>
                <p>Try adjusting your search filters or browse all companies</p>
                <a href="<?php echo appUrl('providers.php'); ?>" class="btn-primary" style="display:inline-block;padding:10px 25px;border-radius:6px;text-decoration:none;">
                    View All Companies
                </a>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <?php include appPath('includes/footer.php'); ?>
</body>
</html>
